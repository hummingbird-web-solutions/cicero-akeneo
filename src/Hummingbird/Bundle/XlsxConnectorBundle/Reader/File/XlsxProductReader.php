<?php

namespace Hummingbird\Bundle\XlsxConnectorBundle\Reader\File;

use Akeneo\Tool\Component\Batch\Item\FileInvalidItem;
use Akeneo\Tool\Component\Batch\Item\FlushableInterface;
use Akeneo\Tool\Component\Batch\Item\InvalidItemException;
use Akeneo\Tool\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Akeneo\Tool\Component\Batch\Step\StepExecutionAwareInterface;
use Akeneo\Tool\Component\Connector\Reader\File\FileIteratorFactory;
use Akeneo\Tool\Component\Connector\ArrayConverter\ArrayConverterInterface;
use Akeneo\Tool\Component\Connector\Exception\DataArrayConversionException;
use Akeneo\Tool\Component\Connector\Exception\InvalidItemFromViolationsException;
use Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface;
use Akeneo\Tool\Component\Connector\Reader\File\MediaPathTransformer;

class XlsxProductReader implements
    ItemReaderInterface,
    StepExecutionAwareInterface,
    FlushableInterface
{
    protected $fileIteratorFactory;

    protected $fileIterator;

    /** @var array */
    protected $xlsx;

    /** @var StepExecution */
    protected $stepExecution;

    protected $attributeRepository;


    /** @var ArrayConverterInterface */
    protected $converter;

    protected $options;

    protected $mediaPathTransformer;

    /**
     * @param ArrayConverterInterface $converter
     */
    public function __construct(
        ArrayConverterInterface $converter,
        FileIteratorFactory $fileIteratorFactory,
        AttributeRepositoryInterface $attributeRepository,
        array $options=[],
        MediaPathTransformer $mediaPathTransformer
        ){
        $this->fileIteratorFactory = $fileIteratorFactory;
        $this->converter = $converter;
        $this->attributeRepository = $attributeRepository;
        $this->options = $options;
        $this->mediaPathTransformer = $mediaPathTransformer;
    }

    public function totalItems(): int
    {
        $jobParameters = $this->stepExecution->getJobParameters();
        $filePath = $jobParameters->get('filePath');
        $iterator = $this->fileIteratorFactory->create($filePath, $this->options);

        return max(iterator_count($iterator) - 1, 0);
    }

    public function read()
    {
        $jobParameters = $this->stepExecution->getJobParameters();
        $filePath = $jobParameters->get('filePath');

        $filename = basename($filePath, '.xlsx');
        $filenamecode = strtolower($filename);
        if(str_contains($filenamecode, " ")){
            $filenamecode = str_replace(' - ', '_', $filenamecode);
            $filenamecode = str_replace('-', '_', $filenamecode);
            $filenamecode = str_replace(' ', '_', $filenamecode);
        }

        $clientBuilder = new \Akeneo\Pim\ApiClient\AkeneoPimClientBuilder('http://akeneo-pim.local/');
        $client = $clientBuilder->buildAuthenticatedByPassword('6_4ymevfr7meg4c00s0g8000wogg84s8owk0040cwgwo08gkc0k4', '2sszzurl4ke8c4cw40sk8888s8g0okwowg0g4w4kskkwkww4c0', 'admin_3451', '0542b65c6');
        
        $client->getCategoryApi()->upsert($filenamecode, [
            'parent' => 'master',
            'labels' => [
                'en_US' => $filename,
                'fr_FR' => $filename,
                'de_DE' => $filename,
            ]
        ]);


        if (null === $this->fileIterator) {
            $this->fileIterator = $this->fileIteratorFactory->create($filePath, $this->options);
            $this->fileIterator->rewind();
        }

        $this->fileIterator->next();
        
        if ($this->fileIterator->valid() && null !== $this->stepExecution) {
            $this->stepExecution->incrementSummaryInfo('item_position');
        }
        
        $data = $this->fileIterator->current();
        
        if (null === $data) {
            return null;
        }
        
        $headers = $this->fileIterator->getHeaders();
        $countHeaders = count($headers);
        $countData = count($data);
        
        $this->checkColumnNumber($countHeaders, $countData, $data, $filePath);
        
        if ($countHeaders > $countData) {
            $missingValuesCount = $countHeaders - $countData;
            $missingValues = array_fill(0, $missingValuesCount, '');
            $data = array_merge($data, $missingValues);
        }
        
        $item = array_combine($this->fileIterator->getHeaders(), $data);

        if (isset($item['3M ID'])) {
            $item['sku'] = $item['3M ID'];
            unset($item['3M ID']);
        }

        if (isset($item['short_description'])){
            $item['Short_description-en_US-ecommerce'] = $item['short_description'];
            unset($item['short_description']);
        }

        $brand_value = '';
        if (isset($item['Brand'])){
            $brand_value = preg_replace("/[^a-zA-Z0-9]/", "", $item['Brand']);
        }

        foreach(array_keys($item) as $attribute){
            if(str_contains($attribute, " ")){
                $attribute = str_replace(' - ', '_', $attribute);
                $attribute = str_replace('-', '_', $attribute);
                $attribute = str_replace(' ', '_', $attribute);
            }
        }
        foreach(array_keys($item) as $attribute){
            if(!$item[$attribute])
                unset($item[$attribute]);
        }
        
        $productInfo = "";
        $supported_attr = [];
        foreach($item as $key => $value){
            $attributes = $this->attributeRepository->findBy(['code' => $key]);

            if(!empty($attributes)) {
                if($attributes[0]->getType() === 'pim_catalog_simpleselect') {
                    $attr_modified = preg_replace("/[^a-zA-Z0-9]/", "", $item[$key]);
                    $client->getAttributeOptionApi()->upsert(strtolower($key), $attr_modified, [
                        'labels'     => [
                            'en_US' => $item[$key]
                        ]
                    ]);
                }
                array_push($supported_attr, $key);
            }

            if($key !== 'sku' && $key !== 'Short_description-en_US-ecommerce'){
                $productInfo = $productInfo . $key .":" . $value.';';
                unset($item[$key]);
            }
        }

        $item['Brand'] = $brand_value;

        $client->getFamilyApi()->upsert($filenamecode, [
            'attributes'             => array_merge($supported_attr, ['product_info']),
            'attribute_requirements' => [
                'ecommerce' => ['sku'],
                'mobile' => ['sku'],
                'print' =>  ['sku'],
            ],
            'labels'                 => [
                'en_US' => $filename,
                'fr_FR' => $filename,
                'de_DE' => $filename,
            ]
       ]);
        $item["product_info"] = $productInfo;
        $item["categories"] = $filenamecode;
        $item['family'] = $filenamecode;  


        try {
            $item = $this->converter->convert($item, $this->getArrayConverterOptions());
        } catch (DataArrayConversionException $e) {
            $this->skipItemFromConversionException($item, $e);
        }

        if (!is_array($item) || !isset($item['values'])) {
            return $item;
        }
        $item['values'] = $this->mediaPathTransformer
            ->transform($item['values'], $this->fileIterator->getDirectoryPath());

        return $item;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->fileIterator = null;
    }

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    protected function getArrayConverterOptions()
    {
        return [];
    }

    /**
     * @param array                        $item
     * @param DataArrayConversionException $exception
     *
     * @throws InvalidItemException
     * @throws InvalidItemFromViolationsException
     */
    protected function skipItemFromConversionException(array $item, DataArrayConversionException $exception)
    {
        if (null !== $this->stepExecution) {
            $this->stepExecution->incrementSummaryInfo('skip');
        }

        if (null !== $exception->getViolations()) {
            throw new InvalidItemFromViolationsException(
                $exception->getViolations(),
                new FileInvalidItem($item, $this->stepExecution->getSummaryInfo('item_position')),
                [],
                0,
                $exception
            );
        }
        throw new InvalidItemException(
            $exception->getMessage(),
            new FileInvalidItem($item, ($this->stepExecution->getSummaryInfo('item_position'))),
            [],
            0,
            $exception
        );
    }

    protected function checkColumnNumber($countHeaders, $countData, $data, $filePath)
    {
        if ($countHeaders < $countData) {
            throw new InvalidItemException(
                'pim_connector.steps.file_reader.invalid_item_columns_count',
                new FileInvalidItem($data, ($this->stepExecution->getSummaryInfo('item_position'))),
                [
                    '%totalColumnsCount%' => $countHeaders,
                    '%itemColumnsCount%'  => $countData,
                    '%filePath%'          => $filePath,
                    '%lineno%'            => $this->fileIterator->key()
                ]
            );
        }
    }
}