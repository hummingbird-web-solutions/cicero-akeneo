<?php

namespace Acme\Bundle\XmlConnectorBundle\ArrayConverter\FlatToStandard;

use Akeneo\Tool\Component\Connector\ArrayConverter\ArrayConverterInterface;

class Product implements ArrayConverterInterface
{
    /** @var ArrayConverterInterface */
    protected $productConverter;

    /**
     * @param ArrayConverterInterface $productConverter
     */
    public function __construct(ArrayConverterInterface $productConverter)
    {
        $this->productConverter = $productConverter;
    }

    public function convert(array $item, array $options = [])
    {
        // cleans the sku
        $item['sku'] = str_replace('uselesspart-', '', $item['sku']);

        $convertedItem = $this->productConverter->convert($item, $options);



        return $convertedItem;
    }
}