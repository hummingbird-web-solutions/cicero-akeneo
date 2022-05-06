<?php

namespace Verkter\Bundle\AppBundle\Command;

use Akeneo\Pim\Structure\Component\Repository\ExternalApi\AttributeRepositoryInterface;
use Akeneo\Tool\Component\Api\Exception\ViolationHttpException;
use Akeneo\Tool\Component\StorageUtils\Factory\SimpleFactoryInterface;
use Akeneo\Tool\Component\StorageUtils\Saver\SaverInterface;
use Akeneo\Tool\Component\StorageUtils\Updater\ObjectUpdaterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class CreateAttributeForProductName
 * @package Verkter\Bundle\AppBundle\Command
 */
class CreateAttributesForProductName extends Command
{
    const ATTRIBUTE_CODES = [
        'product_name_start' => [
            'code'                => 'product_name_start',
            'type'                => 'pim_catalog_simpleselect',
            'group'               => 'general',
            'labels' => [
                'da_DK' => 'Product name START',
                'en_GB' => 'Product name START',
                'et_EE' => 'Product name START',
                'fi_FI' => 'Product name START',
                'fr_FR' => 'Product name START',
                'it_IT' => 'Product name START',
                'lt_LT' => 'Product name START',
                'lv_LV' => 'Product name START',
                'sv_SE' => 'Product name START',
                'nb_NO' => 'Product name START',
                'ru_RU' => 'Product name START',
            ]
        ],
        'product_name_middle' => [
            'code'                => 'product_name_middle',
            'type'                => 'pim_catalog_text',
            'group'               => 'general',
            'labels' => [
                'da_DK' => 'Product name MIDDLE',
                'en_GB' => 'Product name MIDDLE',
                'et_EE' => 'Product name MIDDLE',
                'fi_FI' => 'Product name MIDDLE',
                'fr_FR' => 'Product name MIDDLE',
                'it_IT' => 'Product name MIDDLE',
                'lt_LT' => 'Product name MIDDLE',
                'lv_LV' => 'Product name MIDDLE',
                'sv_SE' => 'Product name MIDDLE',
                'nb_NO' => 'Product name MIDDLE',
                'ru_RU' => 'Product name MIDDLE',
            ]
        ],
        'product_name_ending' => [
            'code'                => 'product_name_ending',
            'type'                => 'pim_catalog_simpleselect',
            'group'               => 'general',
            'labels' => [
                'da_DK' => 'Product name ENDING',
                'en_GB' => 'Product name ENDING',
                'et_EE' => 'Product name ENDING',
                'fi_FI' => 'Product name ENDING',
                'fr_FR' => 'Product name ENDING',
                'it_IT' => 'Product name ENDING',
                'lt_LT' => 'Product name ENDING',
                'lv_LV' => 'Product name ENDING',
                'sv_SE' => 'Product name ENDING',
                'nb_NO' => 'Product name ENDING',
                'ru_RU' => 'Product name ENDING',
            ]
        ]
    ];

    /** @var Connection  */
    protected $connection;

    /** @var AttributeRepositoryInterface */
    protected $repository;

    /** @var SimpleFactoryInterface */
    protected $factory;

    /** @var ObjectUpdaterInterface */
    protected $updater;

    /** @var  ValidatorInterface */
    protected $validator;

    /** @var SaverInterface */
    protected $saver;

    /**
     * CreateAttributeForProductName constructor.
     * @param Connection $connection
     * @param AttributeRepositoryInterface $repository
     * @param SimpleFactoryInterface $factory
     * @param ObjectUpdaterInterface $updater
     * @param ValidatorInterface $validator
     * @param SaverInterface $saver
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct(
        Connection $connection,
        AttributeRepositoryInterface $repository,
        SimpleFactoryInterface $factory,
        ObjectUpdaterInterface $updater,
        ValidatorInterface $validator,
        SaverInterface $saver
    ) {
        parent::__construct();
        $this->connection = $connection;
        $this->repository = $repository;
        $this->factory = $factory;
        $this->updater = $updater;
        $this->validator = $validator;
        $this->saver = $saver;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('verkter:create:attributes')
            ->setDescription('Create attributes for product name and set for all family');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $attributeIDs = [];
        foreach (self::ATTRIBUTE_CODES as $attributeCode => $attributeData) {
            $attribute = $this->repository->findOneByIdentifier($attributeCode);
            if (null !== $attribute) {
                $attributeIDs[] = $attribute->getId();
                continue;
            }
            $attribute = $this->factory->create();
            $this->updater->update($attribute, $attributeData);
            $this->saver->save($attribute);
            $attributeIDs[] = $attribute->getId();
        }

        $sqlFamilyIDs = "SELECT id FROM pim_catalog_family;";
        $familyIDs = $this->connection->fetchAll($sqlFamilyIDs);

        foreach ($familyIDs as $familyID) {
            foreach ($attributeIDs as $attributeID) {
                $sqlCheck = <<<SQL
SELECT * FROM pim_catalog_family_attribute WHERE family_id = :family_id AND attribute_id = :attribute_id;
SQL;
                $param = [
                    'family_id' => $familyID['id'],
                    'attribute_id' => $attributeID,
                ];
                if (!$this->connection->fetchAll($sqlCheck, $param)) {
                    $sql = <<<SQL
INSERT INTO pim_catalog_family_attribute (family_id, attribute_id) VALUES (:family_id, :attribute_id);
SQL;
                    $this->connection->executeQuery($sql, [
                        'family_id' =>  $familyID['id'],
                        'attribute_id' =>  $attributeID,
                    ]);
                }
            }
        }
    }
}