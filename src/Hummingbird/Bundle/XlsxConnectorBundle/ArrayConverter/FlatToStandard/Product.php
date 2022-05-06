<?php

namespace Hummingbird\Bundle\XlsxConnectorBundle\ArrayConverter\FlatToStandard;

use Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface;
use Akeneo\Tool\Component\Connector\ArrayConverter\ArrayConverterInterface;
use Akeneo\Tool\Component\Connector\ArrayConverter\FieldsRequirementChecker;
use Akeneo\Tool\Component\Connector\Exception\DataArrayConversionException;
use Akeneo\Tool\Component\Connector\Exception\StructureArrayConversionException;
use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\FlatToStandard\AssociationColumnsResolver;
use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\FlatToStandard\AttributeColumnsResolver;
use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\FlatToStandard\FieldConverter;
use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\FlatToStandard\ColumnsMerger;
use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\FlatToStandard\ColumnsMapper;

class Product implements ArrayConverterInterface
{
    /** @var AttributeColumnsResolver */
    protected $attrColumnsResolver;

    /** @var AssociationColumnsResolver */
    protected $assocColumnsResolver;

    /** @var FieldConverter */
    protected $fieldConverter;

    /** @var ColumnsMerger */
    protected $columnsMerger;

    /** @var ColumnsMapper */
    protected $columnsMapper;

    /** @var FieldsRequirementChecker */
    protected $fieldChecker;

    /** @var array */
    protected $optionalAssocFields;

    /** @var AttributeRepositoryInterface */
    protected $attributeRepository;

    /** @var ArrayConverterInterface */
    protected $productValueConverter;

    /**
     * @param AssociationColumnsResolver      $assocColumnsResolver
     * @param AttributeColumnsResolver        $attrColumnsResolver
     * @param FieldConverter                  $fieldConverter
     * @param ColumnsMerger                   $columnsMerger
     * @param ColumnsMapper                   $columnsMapper
     * @param FieldsRequirementChecker        $fieldChecker
     * @param AttributeRepositoryInterface    $attributeRepository
     * @param ArrayConverterInterface         $productValueConverter
     */
    public function __construct(
        AssociationColumnsResolver $assocColumnsResolver,
        AttributeColumnsResolver $attrColumnsResolver,
        FieldConverter $fieldConverter,
        ColumnsMerger $columnsMerger,
        ColumnsMapper $columnsMapper,
        FieldsRequirementChecker $fieldChecker,
        AttributeRepositoryInterface $attributeRepository,
        ArrayConverterInterface $productValueConverter
    ) {
        $this->assocColumnsResolver = $assocColumnsResolver;
        $this->attrColumnsResolver = $attrColumnsResolver;
        $this->fieldConverter = $fieldConverter;
        $this->columnsMerger = $columnsMerger;
        $this->columnsMapper = $columnsMapper;
        $this->fieldChecker = $fieldChecker;
        $this->optionalAssocFields = [];
        $this->attributeRepository = $attributeRepository;
        $this->productValueConverter = $productValueConverter;
    }


    public function convert(array $item, array $options = []): array
    {
        $options = $this->prepareOptions($options);

        $mappedItem = $this->mapFields($item, $options);
        $filteredItem = $this->filterFields($mappedItem, $options['with_associations']);
        $this->validateItem($filteredItem);

        $mergedItem = $this->columnsMerger->merge($filteredItem);
        $convertedItem = $this->convertItem($mergedItem);
        return $convertedItem;
    }

    /**
     * @param array $options
     *
     * @return array
     */
    protected function prepareOptions(array $options): array
    {
        $options['with_associations'] = isset($options['with_associations']) ? $options['with_associations'] : true;
        $options['default_values'] = isset($options['default_values']) ? $options['default_values'] : [];

        return $options;
    }

    /**
     * @param array $item
     * @param array $options
     *
     * @return array
     */
    protected function mapFields(array $item, array $options): array
    {
        if (isset($options['mapping'])) {
            $item = $this->columnsMapper->map($item, $options['mapping']);
        }

        return $item;
    }

    protected function filterFields(array $mappedItem, bool $withAssociations): array
    {
        if (false === $withAssociations) {
            $isGroupAssociationPattern = sprintf('/^\w+%s$/', AssociationColumnsResolver::GROUP_ASSOCIATION_SUFFIX);
            $isProductAssociationPattern = sprintf('/^\w+%s$/', AssociationColumnsResolver::PRODUCT_ASSOCIATION_SUFFIX);
            $isProductModelAssociationPattern = sprintf('/^\w+%s$/', AssociationColumnsResolver::PRODUCT_MODEL_ASSOCIATION_SUFFIX);
            $isProductAssociationQuantityPattern = sprintf('/^\w+%s%s$/', AssociationColumnsResolver::PRODUCT_ASSOCIATION_SUFFIX, AssociationColumnsResolver::QUANTITY_SUFFIX);
            $isProductModelAssociationQuantityPattern = sprintf('/^\w+%s%s$/', AssociationColumnsResolver::PRODUCT_MODEL_ASSOCIATION_SUFFIX, AssociationColumnsResolver::QUANTITY_SUFFIX);
            foreach (array_keys($mappedItem) as $field) {
                $isGroup = (1 === preg_match($isGroupAssociationPattern, $field));
                $isProduct = (1 === preg_match($isProductAssociationPattern, $field));
                $isProductModel = (1 === preg_match($isProductModelAssociationPattern, $field));
                $isProductQuantity = (1 === preg_match($isProductAssociationQuantityPattern, $field));
                $isProductModelQuantity = (1 === preg_match($isProductModelAssociationQuantityPattern, $field));
                if ($isGroup || $isProduct || $isProductModel || $isProductQuantity || $isProductModelQuantity) {
                    unset($mappedItem[$field]);
                }
            }
        }

        return $mappedItem;
    }

    /**
     * @param array $item
     *
     * @return array
     */
    protected function convertItem(array $item): array
    {
        $convertedItem = [];
        $convertedValues = [];

        foreach ($item as $column => $value) {
            if ($this->fieldConverter->supportsColumn($column)) {
                $convertedField = $this->fieldConverter->convert($column, $value);
                $convertedItem = $convertedField->appendTo($convertedItem);
            } else {
                $convertedValues[$column] = $value;
            }
        }

        $convertedValues = $this->productValueConverter->convert($convertedValues);

        if (empty($convertedValues)) {
            throw new \LogicException('Cannot find any values. There should be at least one identifier attribute');
        }

        $convertedItem['values'] = $convertedValues;

        $identifierCode = $this->attributeRepository->getIdentifierCode();
        if (!isset($convertedItem['values'][$identifierCode])) {
            throw new \LogicException(sprintf('Unable to find the column "%s"', $identifierCode));
        }

        $convertedItem['identifier'] = $convertedItem['values'][$identifierCode][0]['data'];

        return $convertedItem;
    }

    /**
     * @param array $item
     */
    protected function validateItem(array $item): void
    {
        $requiredField = $this->attrColumnsResolver->resolveIdentifierField();
        $this->fieldChecker->checkFieldsPresence($item, [$requiredField]);
        $this->validateOptionalFields($item);
        $this->validateFieldValueTypes($item);
    }

    /**
     * @param array $item
     *
     * @throws StructureArrayConversionException
     */
    protected function validateOptionalFields(array $item): void
    {
        $extraFields = array_keys($item);

        $optionalFields = array_merge(
            ['family', 'enabled', 'categories', 'groups', 'parent'],
            $this->attrColumnsResolver->resolveAttributeColumns(),
            $this->getOptionalAssociationFields(),
            $extraFields
        );

        // index $optionalFields by keys to improve performances
        $optionalFields = array_combine($optionalFields, $optionalFields);
        $unknownFields = [];
        foreach (array_keys($item) as $field) {
            if (!isset($optionalFields[$field])) {
                $unknownFields[] = $field;
            }
        }

        $nonLocalizableOrScopableFields = $this->filterNonLocalizableOrScopableFields($unknownFields);
        $unknownFields = array_diff($unknownFields, $nonLocalizableOrScopableFields);

        $messages = [];
        if (0 < count($unknownFields)) {
            $messages[] = count($unknownFields) > 1 ?
                sprintf('The fields "%s" do not exist.', implode(', ', $unknownFields)) :
                sprintf('The field "%s" does not exist.', $unknownFields[0]);
        }
        foreach ($nonLocalizableOrScopableFields as $nonLocalizableOrScopableField) {
            $messages[] = sprintf(
                'The field "%s" needs an additional locale and/or a channel information; ' .
                    'in order to do that, please set the code as follow: ' .
                    '\'%s-[locale_code]-[channel_code]\'.',
                $nonLocalizableOrScopableField,
                $nonLocalizableOrScopableField
            );
        }

        if (count($messages) > 0) {
            throw new StructureArrayConversionException(join(' ', $messages));
        }
    }

    /**
     * @param array $item
     *
     * @throws DataArrayConversionException
     */
    protected function validateFieldValueTypes(array $item): void
    {
        $stringFields = ['family', 'categories', 'groups'];

        foreach ($item as $field => $value) {
            if (in_array($field, $stringFields) && !is_string($value)) {
                throw new DataArrayConversionException(
                    sprintf('The field "%s" should contain a string, "%s" provided', $field, $value)
                );
            }
        }
    }

    /**
     * Returns associations and quantified associations fields (resolves once)
     */
    protected function getOptionalAssociationFields(): array
    {
        if (empty($this->optionalAssocFields)) {
            $this->optionalAssocFields = array_merge(
                $this->assocColumnsResolver->resolveAssociationColumns(),
                $this->assocColumnsResolver->resolveQuantifiedAssociationColumns()
            );
        }

        return $this->optionalAssocFields;
    }

    /**
     * This method filters a list of fields (attribute codes) to return only the existing attributes
     * that are scopable or localizable.
     *
     * @param string[]  $attributeCodes
     * @return string[]
     */
    private function filterNonLocalizableOrScopableFields(array $attributeCodes): array
    {
        $result = [];
        if (count($attributeCodes) === 0) {
            return $result;
        }

        $attributes = $this->attributeRepository->findBy(['code' => $attributeCodes]);
        foreach ($attributeCodes as $attributeCode) {
            $found = false;
            foreach ($attributes as $attribute) {
                if (
                    $attribute->getCode() === $attributeCode &&
                    ($attribute->isLocalizable() || $attribute->isScopable())
                ) {
                    $found = true;
                }
            }
            if ($found === true) {
                $result[] = $attributeCode;
            }
        }

        return $result;
    }
}
