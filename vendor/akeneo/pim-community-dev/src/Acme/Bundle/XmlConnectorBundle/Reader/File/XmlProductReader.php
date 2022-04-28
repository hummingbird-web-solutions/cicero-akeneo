<?php

namespace Acme\Bundle\XmlConnectorBundle\Reader\File;

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

class XmlProductReader implements
    ItemReaderInterface,
    StepExecutionAwareInterface,
    FlushableInterface
{
    protected $fileIteratorFactory;

    protected $fileIterator;

    /** @var array */
    protected $xml;

    /** @var StepExecution */
    protected $stepExecution;

    protected $attributeRepository;


    /** @var ArrayConverterInterface */
    protected $converter;

    /**
     * @param ArrayConverterInterface $converter
     */
    public function __construct(ArrayConverterInterface $converter, FileIteratorFactory $fileIteratorFactory, AttributeRepositoryInterface $attributeRepository)
    {
        $this->fileIteratorFactory = $fileIteratorFactory;
        $this->converter = $converter;
        $this->attributeRepository = $attributeRepository;
    }

    public function read()
    {
        // if (null === $this->xml) {
        //     $jobParameters = $this->stepExecution->getJobParameters();
        //     $filePath = $jobParameters->get('filePath');
        //     // for example purpose, we should use XML Parser to read line per line
        //     $this->xml = simplexml_load_file($filePath, 'SimpleXMLIterator');
        //     $this->xml->rewind();
        // }

        $jobParameters = $this->stepExecution->getJobParameters();
        $filePath = $jobParameters->get('filePath');
        if (null === $this->fileIterator) {
            $this->fileIterator = $this->fileIteratorFactory->create($filePath);
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
        // var_dump($headers);
        // die;
        $countHeaders = count($headers);
        $countData = count($data);
        
        $this->checkColumnNumber($countHeaders, $countData, $data, $filePath);
        
        if ($countHeaders > $countData) {
            $missingValuesCount = $countHeaders - $countData;
            $missingValues = array_fill(0, $missingValuesCount, '');
            $data = array_merge($data, $missingValues);
        }
        
        $item = array_combine($this->fileIterator->getHeaders(), $data);

        // if ($data = $this->xml->current()) {
        //     $item = [];
        //     foreach ($data->attributes() as $attributeName => $attributeValue) {
        //         $item[$attributeName] = (string) $attributeValue;
        //     }
        //     $this->xml->next();

        //     if (null !== $this->stepExecution) {
        //         $this->stepExecution->incrementSummaryInfo('item_position');
        //     }

        //     try {
        //         $item = $this->converter->convert($item);
        //     } catch (DataArrayConversionException $e) {
        //         $this->skipItemFromConversionException($this->xml->current(), $e);
        //     }
        //     return $item;
        // }

        if (isset($item['3M ID'])) {
            $item['sku'] = $item['3M ID'];
            unset($item['3M ID']);
        }

        if (isset($item['short_description'])){
            $item['Short_description-en_US-ecommerce'] = $item['short_description'];
            unset($item['short_description']);
        }

        foreach(array_keys($item) as $attribute){
            $oldAttribute = $attribute;
            if(str_contains($attribute, " ")){
                // echo $attribute;
                $attribute = str_replace(' - ', '_', $attribute);
                $attribute = str_replace('-', '_', $attribute);
                $attribute = str_replace(' ', '_', $attribute);
            }
            if(strcmp($attribute, $oldAttribute)!==0){
                $item[strtolower($attribute)] = $item[$oldAttribute];
                unset($item[$oldAttribute]);
            }
        }
        foreach(array_keys($item) as $attribute){
            if(!$item[$attribute])
                unset($item[$attribute]);
            // unset($item['Adhesion']);
            // unset($item['Adhesive']);
            // unset($item['Adhesives']);
        }
        // var_dump(array_keys($item));
        // die;
        // if (isset($item['%_solids_(volume)'])){
        //     $item['percent_solids_volume'] = $item['%_solids_(volume)'];
        //     unset($item['%_solids_(volume)']);
        // }

        // var_dump($item);
        // die;
        $productInfo = "";
        foreach($item as $key => $value){
            if(!$this->attributeRepository->findBy(['code' => $key])){
                $productInfo = $productInfo . $key ." => " . $value;
                unset($item[$key]);
            }
        }
        $item["product_info"] = $productInfo;
        try {
            // var_dump($this->getArrayConverterOptions());
            // die;
            $item = $this->converter->convert($item);
        } catch (DataArrayConversionException $e) {
            $this->skipItemFromConversionException($item, $e);
        }

        return $item;
    }

    // public function read()
    // {
    //     if (null === $this->xml) {
    //         $jobParameters = $this->stepExecution->getJobParameters();
    //         $filePath = $jobParameters->get('filePath');
    //         // for example purpose, we should use XML Parser to read line per line
    //         $this->xml = simplexml_load_file($filePath, 'SimpleXMLIterator');
    //         $this->xml->rewind();
    //     }

    //     if ($data = $this->xml->current()) {
    //         $item = [];
    //         foreach ($data->attributes() as $attributeName => $attributeValue) {
    //             $item[$attributeName] = (string) $attributeValue;
    //         }
    //         $this->xml->next();

    //         if (null !== $this->stepExecution) {
    //             $this->stepExecution->incrementSummaryInfo('item_position');
    //         }

    //         try {
    //             $item = $this->converter->convert($item);
    //         } catch (DataArrayConversionException $e) {
    //             $this->skipItemFromConversionException($this->xml->current(), $e);
    //         }

    //         return $item;
    //     }

    //     return null;
    // }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->xml = null;
    }

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
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

        $invalidItem = new FileInvalidItem(
            $item,
            $this->stepExecution->getSummaryInfo('item_position')
        );

        throw new InvalidItemException($exception->getMessage(), $invalidItem, [], 0, $exception);
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