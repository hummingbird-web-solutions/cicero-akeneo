<?php

namespace Akeneo\Pim\Enrichment\Bundle\Storage\ElasticsearchAndSql\ProductAndProductModel;

use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Component\Product\Query\CountProductsWithRemovedAttributeInterface;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Client;

final class CountProductsWithRemovedAttribute implements CountProductsWithRemovedAttributeInterface
{
    /** @var Client */
    private $elasticsearchClient;

    public function __construct(
        Client $elasticsearchClient
    ) {
        $this->elasticsearchClient = $elasticsearchClient;
    }

    public function count(array $attributesCodes): int
    {
        $body = [
            'query' => [
                'constant_score' => [
                    'filter' => [
                        'bool' => [
                            'filter' => [
                                [
                                    'term' => [
                                        'document_type' => ProductInterface::class,
                                    ],
                                ],
                                [
                                    'terms' => [
                                        'attributes_for_this_level' => $attributesCodes,
                                    ],
                                ],
                            ],
                            'should' => array_map(function (string $attributeCode) {
                                return [
                                    'exists' => ['field' => sprintf('values.%s-*', $attributeCode)],
                                ];
                            }, $attributesCodes),
                            'minimum_should_match' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->elasticsearchClient->count($body);

        return (int)$result['count'];
    }
}
