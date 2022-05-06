<?php

declare(strict_types=1);

namespace Verkter\Bundle\AppBundle\EventSubscriber;

use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Pim\Structure\Component\Model\AttributeOptionInterface;
use Akeneo\Tool\Component\StorageUtils\StorageEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Akeneo\Tool\Component\StorageUtils\Updater\ObjectUpdaterInterface;
use Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Saver\SaverInterface;
use Akeneo\Tool\Component\StorageUtils\Saver\BulkSaverInterface;

/**
 * Class UpdateProductNameSubscriber
 *
 * @package Verkter\Bundle\AppBundle\EventSubscriber
 */
class UpdateProductNameSubscriber implements EventSubscriberInterface
{
    /** @var Connection  */
    protected $connection;

    /** @var ObjectUpdaterInterface */
    protected $productUpdater;

    /** @var IdentifiableObjectRepositoryInterface */
    protected $productRepository;

    /** @var BulkSaverInterface */
    protected $productsSaver;

    /** @var array  */
    protected $attributesCodes = [];

    public function __construct(
        Connection $connection,
        ObjectUpdaterInterface $productUpdater,
        IdentifiableObjectRepositoryInterface $productRepository,
        BulkSaverInterface $productsSaver,
        array $attributesCodes
    ) {
        $this->connection = $connection;
        $this->productUpdater = $productUpdater;
        $this->productRepository = $productRepository;
        $this->productsSaver = $productsSaver;
        $this->attributesCodes = $attributesCodes;
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents()
    {
        return [
            StorageEvents::POST_SAVE => 'onPostSave',
        ];
    }

    /**
     * @param GenericEvent $event
     */
    public function onPostSave(GenericEvent $event): void
    {
        if (!$event->hasArgument('unitary') || false === $event->getArgument('unitary')) {
            return;
        }

        $subject = $event->getSubject();
        if ($subject instanceof AttributeOptionInterface) {
            if (!in_array($subject->getAttribute()->getCode(), $this->attributesCodes)) {
                return;
            }
            $whereRawValues = sprintf('%s\": {\"<all_channels>\": {\"<all_locales>\": \"%s',
                $subject->getAttribute()->getCode(),
                $subject->getOptionValue()->getOption()->getCode()
            );
            $whereRawValues = '%' . $whereRawValues . '%';

            $sql = <<<SQL
SELECT pcp.* FROM pim_catalog_product pcp WHERE pcp.raw_values LIKE :whereRawValues;
SQL;
            $param = [
                'whereRawValues' => $whereRawValues
            ];

            $resultQuery = $this->connection->fetchAll($sql, $param);

            if ($resultQuery) {
                $productIds = [];

                foreach ($resultQuery as $item) {
                    $productIds[] = $item['identifier'];
                }

                $products = $this->productRepository->getItemsFromIdentifiers($productIds);

//                ['values' => [
//                    $subject->getAttribute()->getCode() => [
//                        [
//                            'data' => $subject->getOptionValue()->getOption()->getCode(),
//                            'scope' => null,
//                            'locale' => null
//                        ]
//                    ]
//                ]]

                foreach ($products as $product) {
                    $this->productUpdater->update(
                        $product,
                        [
                            'family' => $product->getFamily()->getCode(),
                            'from_option' => 'yes'
                        ]
                    );
                }

                $this->productsSaver->saveAll($products);
            }

            return;
        }
    }
}
