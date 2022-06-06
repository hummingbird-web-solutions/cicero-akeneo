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

    private $url = 'http://pim.humcommerce.com/';
    private $clientId = '5_1aro719g4qsk88s8wc0wokgwkkw0ccwgss0s4o0cc44wc08gc0';
    private $clientSecret = '3fgcqr8nqxa80g8ok4wkw0c0o4c484k8wg88ok4g40gcw8ksos';
    private $userName = 'php_api_client_3942';
    private $password = '291c80ae6';

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

    /*
    * This function reads the xlsx file and dumps the access attributes in the form of key value pair in a custom product info attribute
    * Sets the category and family name to the filename
    * Creates Attribute option if its not present by default 
    */
    public function read()
    {
        $jobParameters = $this->stepExecution->getJobParameters();
        $filePath = $jobParameters->get('filePath');

        $mainCategory = $this->getMainCategory($filePath);
        $mainCategoryCode = $this->getCategoryCode($mainCategory);

        $filename = $this->getSubCategory($filePath);
        $filenamecode = $this->getCategoryCode($filename);

        //using php api client to set categories, families, attributes and attribute option following its documentation and hardcoded the local pim url
        $clientBuilder = new \Akeneo\Pim\ApiClient\AkeneoPimClientBuilder($this->url);
        $client = $clientBuilder->buildAuthenticatedByPassword($this->clientId, $this->clientSecret, $this->userName, $this->password);

        //creating or updating the category and subcategory
        $this->upsertCategory($client, $mainCategory, $mainCategoryCode);
        $this->upsertCategory($client, $filename, $filenamecode, $mainCategoryCode);

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

        //maps the file fields with akeneo attributes
        $this->mapAttributes($item, '3M ID', 'sku');
        $this->mapAttributes($item, 'short_description', 'Short_description-en_US-ecommerce');
        $this->mapAttributes($item, 'Marketplace Description', 'Description-en_US-ecommerce');
        $this->mapAttributes($item, 'Marketplace Formal Name', 'Name');

        $this->createGroupedProduct($item, $client, $filenamecode);

        //to remove duplicate description
        if($item['Enhanced Extended Description']){
            unset($item['Enhanced Extended Description']);
        }

        // Cleans the array for processing
        foreach(array_keys($item) as $attribute){
            if(str_contains($attribute, " ")){
                $attribute = str_replace(' - ', '_', $attribute);
                $attribute = str_replace('-', '_', $attribute);
                $attribute = str_replace(' ', '_', $attribute);
            }
        }

        // Removes the key from the items array if the attribute is blank
        foreach(array_keys($item) as $attribute){
            if(!$item[$attribute])
                unset($item[$attribute]);
        }

        //creating diameter attribute
        $client->getAttributeApi()->upsert('diameter', [
            'type'                   => 'pim_catalog_simpleselect',
            'group'                  => 'design',
            'sort_order'             => 1,
            'labels'                 => [
                'en_US' => 'Diameter',
            ],
        ]);

        //creating image attribute
        $client->getAttributeApi()->upsert('image', [
            'type'                   => 'pim_catalog_image',
            'group'                  => 'medias',
            'allowed_extensions'     => ['jpg' ,'jpeg', 'png', 'tiff'],
            'sort_order'             => 1,
            'labels'                 => [
                'en_US' => 'Image',
            ],
        ]);

        if($item['sku'] === '70020233386'){
            $item['image'] = '7035632bd93aa85e39297a278d7ca384f3a72522.jpg';
        }

        //unit mapping
        $unit = ['Inch' => 'in', 'mm' => 'mm',];

        //creates the attribute option if not present already
        $supportedAttr = [];
        foreach($item as $key => $value){
            $attributes = $this->attributeRepository->findBy(['code' => $key]);
            if(!empty($attributes)) {
                if($attributes[0]->getType() === 'pim_catalog_simpleselect') {
                    $attr_modified = preg_replace("/[^a-zA-Z0-9]/", "", $value);
                    if($key === 'Diameter' && array_key_exists('Diameter Unit', $item)){
                        $value .= " ".$unit[$item['Diameter Unit']];
                    }

                    $client->getAttributeOptionApi()->upsert(strtolower($key), $attr_modified, [
                        'labels'     => [
                            'en_US' => $value,
                        ]
                    ]);
                }
                array_push($supportedAttr, $key);
            }
        }

        //cleans the supported attribute options
        $attrOption = [];
        foreach($supportedAttr as $attr){
            if (isset($item[$attr])){
                if($attr === "image"){
                    $attrOption[$attr] = $item[$attr];
                }
                else if($attr === "Name"){
                    $attrOption[$attr] = preg_replace("/[^a-zA-Z0-9 ]/", "", $item[$attr]);
                }
                else{
                    $attrOption[$attr] = preg_replace("/[^a-zA-Z0-9]/", "", $item[$attr]);
                }
            }
        }

        // Creating the attribute product_info if not already exist;
        $client->getAttributeApi()->upsert('product_info', [
            'type'                   => 'pim_catalog_textarea',
            'group'                  => 'product',
            'wysiwyg_enabled'        => true,
            'sort_order'             => 1,
            'labels'                 => [
                'en_US' => 'Product Information',
            ],
        ]);

        // create the content of product info string
        $productInfo = "";
        $descString = "";
        foreach($item as $key => $value){
            if($key !== 'Description-en_US-ecommerce' && $key !== 'sku' && $key !== 'Short_description-en_US-ecommerce'){
                if($key ==='Name'||str_contains($key, 'Image')||str_contains($key, 'image')){
                    unset($item[$key]);
                }
                else{
                    if(str_contains($key, 'bullet')){
                        $descString = $descString.'<li>'.$value.'</li>';
                        unset($item[$key]);
                        continue;
                    }
                    if($descString){
                        $productInfo = $productInfo . "<tr><th>Short Description</th><td>" . $descString . '</td></tr>';
                        $descString = "";
                    }
                    $info = "<tr><th>".$key."</th>" . "<td>".$value."</td></tr>";
                    $productInfo = $productInfo . $info;
                    unset($item[$key]);
                }
            }
        }


        // sets the attributes
        foreach($supportedAttr as $attr){
            $item[$attr] = $attrOption[$attr];

        }

        // creates the family with the filename and adds attributes to that family
        $client->getFamilyApi()->upsert($filenamecode, [
            'attributes'             => array_merge($supportedAttr, ['product_info', 'description']),
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

    private function createGroupedProduct(&$item, $client, $categoryCode){
        $familyName = explode(',', $item['Name'])[0];
        $familyNameCode = $this->getFamilyCode($familyName)."_group";
        
        $client->getFamilyApi()->upsert($familyNameCode, [
            'attributes'             => ['sku'],
            'attribute_requirements' => [
                'ecommerce' => ['sku'],
                'mobile' => ['sku'],
                'print' =>  ['sku'],
            ],
            'labels'                 => [
                'en_US' => $familyName,
                'fr_FR' => $familyName,
                'de_DE' => $familyName,
            ]
        ]);
        
        $associationCode = $familyNameCode;
        // check that the length is within sql column identifier character limit
        if(strlen($familyNameCode)>55){
            $associationCode = substr($familyNameCode, -55);
        }
        $client->getAssociationTypeApi()->upsert($associationCode, [
            'labels' => [
                'en_US' => $familyName,
            ],
            'is_quantified' => true
        ]);
        
        try{
            $product = $client->getProductApi()->get($associationCode);
            $productList = $product['quantified_associations'][$associationCode]['products'];
            array_push($productList, ["identifier" => $item['sku'], "quantity" => 2]);
        }
        catch(\Exception $e){
            $productList = [["identifier" => $item['sku'], "quantity" => 2]];
        }

        //association code is used instead of familycode due to the sku character limit in magento
        $client->getProductApi()->upsert($associationCode, [
            "identifier"=> $associationCode,
            "enabled" => true,
            'family' => $familyNameCode,
            'categories' => [$categoryCode],
            "quantified_associations"=> [
                $associationCode=> [
                    "products"=> $productList
                ]
            ],
            "values" => [
                "name" => [
                    [
                        "data" => $familyName,
                        "locale" => null,
                        "scope" => null,    
                    ]
                ]
            ]
        ]);
    }

    private function mapAttributes(&$item, $fileAttribute, $akeneoAttribute){
        if (isset($item[$fileAttribute])) {
            $item[$akeneoAttribute] = $item[$fileAttribute];
            unset($item[$fileAttribute]);
        }
    }

    private function upsertCategory($client, $label, $code, $parent='master'){
        $client->getCategoryApi()->upsert($code, [
            'parent' => $parent,
            'labels' => [
                'en_US' => $label,
                'fr_FR' => $label,
                'de_DE' => $label,
            ]
        ]);
    }

    private function getMainCategory($filePath){
        $filePathArr = explode('/', $filePath);
        if(sizeof($filePathArr)<3){
            echo "unable to convert the filepath to category structure";
            die;
        }
        return implode(array_slice($filePathArr, -2, 1));
    }

    private function getCategoryCode($categoryName){
        $categoryNameCode = strtolower($categoryName);
        if(str_contains($categoryNameCode, " ")){
            $categoryNameCode = str_replace(' - ', '_', $categoryNameCode);
            $categoryNameCode = str_replace('-', '_', $categoryNameCode);
            $categoryNameCode = str_replace(' ', '_', $categoryNameCode);
            $categoryNameCode = str_replace('&', 'and', $categoryNameCode);
        }
        return $categoryNameCode;
    }

    private function getFamilyCode($familyName){
        $familyNameCode = preg_replace("/[^a-zA-Z0-9 ]/", "", $familyName);
        $familyNameCode = strtolower($familyNameCode);
        if(str_contains($familyNameCode, " ")){
            $familyNameCode = str_replace(' - ', '_', $familyNameCode);
            $familyNameCode = str_replace('-', '_', $familyNameCode);
            $familyNameCode = str_replace(' ', '_', $familyNameCode);
        }
        return $familyNameCode;
    }

    private function getSubCategory($filePath){
        return basename($filePath, '.xlsx');
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