<?php

declare(strict_types=1);

namespace Verkter\Bundle\AppBundle\Updater;

use Akeneo\Pim\Enrichment\Component\Product\Association\ParentAssociationsFilter;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Component\Product\QuantifiedAssociation\QuantifiedAssociationsFromAncestorsFilter;
use Akeneo\Pim\Enrichment\Component\Product\Updater\Validator\QuantifiedAssociationsStructureValidatorInterface;
use Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Exception\InvalidObjectException;
use Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Updater\ObjectUpdaterInterface;
use Akeneo\Tool\Component\StorageUtils\Updater\PropertySetterInterface;
use Akeneo\UserManagement\Bundle\Context\UserContext;
use Doctrine\Common\Util\ClassUtils;
// use Webkul\Magento2BundleProductBundle\Component\Catalog\Updater\ProductUpdater as ProductUpdaterBase;
use Akeneo\Pim\Enrichment\Component\Product\Updater\ProductUpdater as ProductUpdaterBase;

/**
 * Class ProductUpdater
 *
 * @package Verkter\Bundle\AppBundle\Updater
 */
class ProductUpdater extends ProductUpdaterBase
{
    /** @var PropertySetterInterface */
    protected $propertySetter;

    /** @var ObjectUpdaterInterface */
    protected $valuesUpdater;

    /** @var array */
    protected $ignoredFields = [];

    /** @var ParentAssociationsFilter */
    protected $parentAssociationsFilter;

    /** @var QuantifiedAssociationsFromAncestorsFilter */
    protected $quantifiedAssociationsFromAncestorsFilter;

    /** @var QuantifiedAssociationsStructureValidatorInterface */
    protected $quantifiedAssociationsStructureValidator;

    /** @var array */
    protected $attributesCodesGeneratingNameAndUrlKey;

    /** @var string */
    protected $attributeCodeNameProduct;

    /** @var string */
    protected $attributeCodeUrlKey;

    /** @var AttributeRepositoryInterface */
    protected $attributeRepository;

    /** @var UserContext */
    protected $userContext;

    /** @var IdentifiableObjectRepositoryInterface */
    protected $attributeOptionRepository;

    public function __construct(
        PropertySetterInterface $propertySetter,
        ObjectUpdaterInterface $valuesUpdater,
        ParentAssociationsFilter $parentAssociationsFilter,
        QuantifiedAssociationsFromAncestorsFilter $quantifiedAssociationsFromAncestorsFilter,
        QuantifiedAssociationsStructureValidatorInterface $quantifiedAssociationsStructureValidator,
        array $ignoredFields,
        AttributeRepositoryInterface $attributeRepository,
        UserContext $userContext,
        IdentifiableObjectRepositoryInterface $attributeOptionRepository,
        array $attributesCodesGeneratingNameAndUrlKey,
        string $attributeCodeNameProduct,
        string $attributeCodeUrlKey
    ) {
        parent::__construct(
            $propertySetter,
            $valuesUpdater,
            $parentAssociationsFilter,
            $quantifiedAssociationsFromAncestorsFilter,
            $quantifiedAssociationsStructureValidator,
            $ignoredFields
        );
        $this->propertySetter = $propertySetter;
        $this->valuesUpdater = $valuesUpdater;
        $this->ignoredFields = $ignoredFields;
        $this->parentAssociationsFilter = $parentAssociationsFilter;
        $this->quantifiedAssociationsFromAncestorsFilter = $quantifiedAssociationsFromAncestorsFilter;
        $this->quantifiedAssociationsStructureValidator = $quantifiedAssociationsStructureValidator;
        $this->attributesCodesGeneratingNameAndUrlKey = $attributesCodesGeneratingNameAndUrlKey;
        $this->attributeCodeNameProduct = $attributeCodeNameProduct;
        $this->attributeCodeUrlKey = $attributeCodeUrlKey;
        $this->attributeRepository = $attributeRepository;
        $this->userContext = $userContext;
        $this->attributeOptionRepository = $attributeOptionRepository;
    }

    /**
     * @param $product
     * @param array $data
     * @param array $options
     * @return $this
     * @throws InvalidObjectException
     */
    public function update($product, array $data, array $options = []): ProductUpdater
    {
        /** @var ProductInterface $product */
        if (!$product instanceof ProductInterface) {
            throw InvalidObjectException::objectExpected(
                ClassUtils::getClass($product),
                ProductInterface::class
            );
        }

        $isNewChangesControlAttributes = false;
        $isValueNameOrUrl = false;
        foreach ($this->attributesCodesGeneratingNameAndUrlKey as $attributeCode) {
            if (isset($data['values'][$attributeCode])) {
                $isNewChangesControlAttributes = true;
                break;
            }
        }
        if (!$isNewChangesControlAttributes) {
            $userLocaleCodes = $this->userContext->getUserLocaleCodes();
            $currentLocaleCode = $this->userContext->getCurrentLocaleCode() ?: 'en_GB';

            $attributeNameValue = $product->getValue($this->attributeCodeNameProduct, $currentLocaleCode) ?
                $product->getValue($this->attributeCodeNameProduct, $currentLocaleCode)->getData(): '';
            $attributeUrlKeyValue = $product->getValue($this->attributeCodeUrlKey) ?
                $product->getValue($this->attributeCodeUrlKey)->getData() : '';

            if (empty($attributeNameValue) || empty($attributeUrlKeyValue)) {
                $isValueNameOrUrl = true;
            }
        }

        if (($isNewChangesControlAttributes || $isValueNameOrUrl) || isset($data['from_option'])) {
            if (isset($data['from_option'])) {
                unset($data['from_option']);
            }

            if (!isset($data['values'])) {
                $data['values'] = [];
            }

            $info = $this->prepareInfo($product, $data['values'], $isNewChangesControlAttributes);

            $data = $this->addName($data, $info);

            $data = $this->addUrlKey($data, $info);
        }

        foreach ($data as $code => $value) {
            $filteredValue = $this->filterData($product, $code, $value, $data);
            $this->setData($product, $code, $filteredValue, $options);
        }

        return $this;
    }

    /**
     * @param ProductInterface $product
     * @param array $dataValues
     * @param bool $isNewChangesControlAttributes
     *
     * @return array
     */
    protected function prepareInfo(
        ProductInterface $product,
        array $dataValues,
        bool $isNewChangesControlAttributes = false
    ): array {
        $result = [];
        foreach ($this->attributesCodesGeneratingNameAndUrlKey as $key => $attributeCode) {
            $attributeValue = '';
            if (0 == $key ||  2 == $key) {
                if ($isNewChangesControlAttributes && isset($dataValues[$attributeCode])) {
                    $attributeValue = $dataValues[$attributeCode][0]['data'];
                } else {
                    $attributeValue = $product->getValues()->getByCodes($attributeCode) ?
                        $product->getValues()->getByCodes($attributeCode)->getData() : '';
                }
                //for local specific
                //$attributeValue = $product->getValue($attributeCode, $currentLocaleCode)->getData();
                if ($attributeValue) {
                    $options = $this->attributeOptionRepository
                        ->findOneByIdentifier($attributeCode . '.' . $attributeValue)
                        ->getOptionValues()
                        ->getValues();
                    foreach ($options as $optionLocale => $option) {
                        if ($option->getValue()) {
                            $result[$option->getLocale()][$attributeCode] = $option->getValue();
                        }
                    }
                }
            } elseif (1 == $key) {
                if ($isNewChangesControlAttributes && isset($dataValues[$attributeCode])) {
                    $attributeValue = $dataValues[$attributeCode][0]['data'];
                } else {
                    $attributeValue = $product->getValue($attributeCode) ?
                        $product->getValue($attributeCode)->getData() : '';
                }
                if ($attributeValue) {
                    if ($result) {
                        foreach ($result as $locale => $values) {
                            $result[$locale][$attributeCode] = $attributeValue;
                        }
                    } else {
                        foreach ($this->userContext->getUserLocaleCodes() as $locale) {
                            $result[$locale][$attributeCode] = $attributeValue;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array $data
     * @param array $info
     * @return array
     */
    protected function addName(array $data, array $info): array
    {
        foreach ($info as $locale => $values) {
            if (isset($values['product_name_middle'])) {
                $data['values'][$this->attributeCodeNameProduct][] = [
                    'scope' => null,
                    'locale' => $locale,
                    'data' => ucfirst(preg_replace('/\s+/', ' ', mb_strtolower(implode(' ', $values)))),
                ];
            }
        }

        return $data;
    }

    /**
     * @param array $data
     * @param array $info
     * @return array
     */
    protected function addUrlKey(array $data, array $info): array
    {
        foreach ($info as $locale => $values) {
            if (isset($values['product_name_middle'])) {
                $urlKey = mb_strtolower(implode(' ', $values));
                $urlKey = $this->transliterate($urlKey);
                $urlKey = str_replace('?', '', iconv("UTF-8", "ASCII//TRANSLIT", $urlKey));
                $urlKey = preg_replace('/\s+/', '-', trim($urlKey));
                $data['values'][$this->attributeCodeUrlKey][] = [
                    'scope' => null,
                    'locale' => $locale,
                    'data' => $urlKey,
                ];
            }
        }

        return $data;
    }

    /**
     * @param $string
     * @return string
     */
    protected function transliterate($string) {
        $roman = array("Sch","sch",'Yo','Zh','Kh','Ts','Ch','Sh','Yu','ya','yo','zh','kh','ts','ch','sh','yu','ya','A','B','V','G','D','E','Z','I','Y','K','L','M','N','O','P','R','S','T','U','F','','Y','','E','a','b','v','g','d','e','z','i','y','k','l','m','n','o','p','r','s','t','u','f','','y','','e');
        $cyrillic = array("Щ","щ",'Ё','Ж','Х','Ц','Ч','Ш','Ю','я','ё','ж','х','ц','ч','ш','ю','я','А','Б','В','Г','Д','Е','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Ь','Ы','Ъ','Э','а','б','в','г','д','е','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','ь','ы','ъ','э');

        return str_replace($cyrillic, $roman, $string);
    }
}
