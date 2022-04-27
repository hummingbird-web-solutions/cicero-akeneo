<?php

namespace Akeneo\Tool\Component\Connector\Reader\File\Xlsx;

use Akeneo\Tool\Component\Batch\Item\FileInvalidItem;
use Akeneo\Tool\Component\Batch\Item\InvalidItemException;
use Akeneo\Tool\Component\Batch\Item\TrackableItemReaderInterface;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Akeneo\Tool\Component\Connector\ArrayConverter\ArrayConverterInterface;
use Akeneo\Tool\Component\Connector\Exception\DataArrayConversionException;
use Akeneo\Tool\Component\Connector\Exception\InvalidItemFromViolationsException;
use Akeneo\Tool\Component\Connector\Reader\File\FileIteratorFactory;
use Akeneo\Tool\Component\Connector\Reader\File\FileIteratorInterface;
use Akeneo\Tool\Component\Connector\Reader\File\FileReaderInterface;

/**
 * Xlsx Reader
 *
 * @author    Marie Bochu <marie.bochu@akeneo.com>
 * @copyright 2016 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Reader implements FileReaderInterface, TrackableItemReaderInterface
{
    /** @var FileIteratorFactory */
    protected $fileIteratorFactory;

    /** ArrayConverterInterface */
    protected $converter;

    /** @var FileIteratorInterface */
    protected $fileIterator;

    /** @var StepExecution */
    protected $stepExecution;

    /** @var array */
    protected $options;

    /**
     * @param FileIteratorFactory     $fileIteratorFactory
     * @param ArrayConverterInterface $converter
     * @param array                   $options
     */
    public function __construct(
        FileIteratorFactory $fileIteratorFactory,
        ArrayConverterInterface $converter,
        array $options = []
    ) {
        $this->fileIteratorFactory = $fileIteratorFactory;
        $this->converter = $converter;
        $this->options = $options;
    }

    public function totalItems(): int
    {
        $jobParameters = $this->stepExecution->getJobParameters();
        $filePath = $jobParameters->get('filePath');
        $iterator = $this->fileIteratorFactory->create($filePath, $this->options);

        return max(iterator_count($iterator) - 1, 0);
    }

    /**
     * {@inheritdoc}
     */
    public function read()
    {
        $jobParameters = $this->stepExecution->getJobParameters();
        $filePath = $jobParameters->get('filePath');
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

        //custom code
        if (isset($item['3M ID'])) {
            $item['Sku'] = $item['3M ID'];
            unset($item['3M ID']);
        }

        if (isset($item['short_description'])){
            $item['Short_description-en_US-ecommerce'] = $item['short_description'];
            unset($item['short_description']);
        }

        // if (isset($item['Accessories'])){
        //     $item['accessories'] = $item['Accessories'];
        //     unset($item['Accessories']);
        // }

        // var_dump(array_keys($item));
        // die;

        
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
            else{
                $item[strtolower($attribute)] = $item[$attribute];
                unset($item[$attribute]);
            }
        }
        if (isset($item['%_solids_(volume)'])){
            $item['percent_solids_volume'] = $item['%_solids_(volume)'];
            unset($item['%_solids_(volume)']);
        }
        // var_dump(array_keys($item));
        // die;
        // echo "hello3";
        // die;
        
        try {
            // var_dump($this->getArrayConverterOptions());
            // die;
            $item = $this->converter->convert($item, $this->getArrayConverterOptions());
        } catch (DataArrayConversionException $e) {
            $this->skipItemFromConversionException($item, $e);
        }

        return $item;
    }

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->fileIterator = null;
    }

    /**
     * Returns the options for array converter. It can be overridden in the sub classes.
     *
     * @return array
     */
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
                new FileInvalidItem($item, ($this->stepExecution->getSummaryInfo('item_position'))),
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

    /**
     * @param int    $countHeaders
     * @param int    $countData
     * @param string $data
     * @param string $filePath
     *
     * @throws InvalidItemException
     */
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
