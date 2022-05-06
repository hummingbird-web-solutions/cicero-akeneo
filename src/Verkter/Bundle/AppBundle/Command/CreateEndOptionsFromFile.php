<?php

namespace Verkter\Bundle\AppBundle\Command;

use Akeneo\Pim\Structure\Bundle\Form\Type\AttributeOptionType;
use Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Factory\SimpleFactoryInterface;
use Akeneo\Tool\Component\StorageUtils\Saver\SaverInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class CreateOptionsFromFile
 * @package Verkter\Bundle\AppBundle\Command
 */
class CreateEndOptionsFromFile extends Command
{
    // php bin/console verkter:create:attribute_options "/var/www/public_html/isdalinta_ok_short_version.csv" "./file_path_for_number.txt"

    const ATTRIBUTE_CODES = [
        '1' => 'product_name_start',
        '2' => 'product_name_ending'
    ];

    const LOCALES = [
        'da_DK',
        'en_GB',
        'et_EE',
        'fi_FI',
        'fr_FR',
        'it_IT',
        'lt_LT',
        'lv_LV',
        'sv_SE',
        'nb_NO',
        'ru_RU'
    ];

    /** @var Connection  */
    protected $connection;

    /** @var AttributeRepositoryInterface */
    protected $attributeRepository;

    /** @var SimpleFactoryInterface */
    protected $optionFactory;

    /** @var SaverInterface */
    protected $optionSaver;

    /** @var FormFactoryInterface */
    protected $formFactory;

    /** @var EntityManager */
    protected $em;

    /** @var array  */
    protected $attributeObjects = [];

    /**
     * CreateOptionsFromFile constructor.
     * @param Connection $connection
     * @param AttributeRepositoryInterface $attributeRepository
     * @param SimpleFactoryInterface $optionFactory
     * @param SaverInterface $optionSaver
     * @param FormFactoryInterface $formFactory
     * @param EntityManager $em
     *
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct(
        Connection $connection,
        AttributeRepositoryInterface $attributeRepository,
        SimpleFactoryInterface $optionFactory,
        SaverInterface $optionSaver,
        FormFactoryInterface $formFactory,
        EntityManager $em
    ) {
        parent::__construct();
        $this->connection = $connection;
        $this->attributeRepository = $attributeRepository;
        $this->optionFactory = $optionFactory;
        $this->optionSaver = $optionSaver;
        $this->formFactory = $formFactory;
        $this->em = $em;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('verkter:create:end_attribute_options')
            ->setDescription('Create options')
            ->addArgument('file_path', InputArgument::REQUIRED, 'File path: data/verkter_rows_g.csv');
    }

    private function fseekLine($handle, $count) {
        while ((--$count >= 0) && (fgets($handle, 4096) !== false)) { }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $filePath = $input->getArgument('file_path');
        $filePathForNumber = $input->getArgument('file_path') . '.num';
        $number = 0;
        if (is_writable($filePathForNumber)) {
            $number = (int)file_get_contents($filePathForNumber);
        }
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            $firstLine = fgetcsv($handle, 1000, "|");
            $headers = array_flip($firstLine);
            $this->fseekLine($handle, $number);
            $i = 0;
            while (($data = fgetcsv($handle, 1000, "|")) !== FALSE) {
                if (1000 == $i) {
                    file_put_contents($filePathForNumber, $number + 1000);
                    fclose($handle);
                    return 2;
                } else {
                    ++$i;
                    $output->writeln('Process row: ' . ($number + $i));
                    $dataCreateOption = [];

                    $dataCreateOption[self::ATTRIBUTE_CODES[2]]['data'] = [
                        'code' => $data[$headers['artnum']],
                        'id' => null,
                        'optionValues' => [
                            'da_DK' => [
                                'id' => null,
                                'locale' => 'da_DK',
                                'value' => $data[$headers['da_DK']] ?? ''
                            ],
                            'en_GB' => [
                                'id' => null,
                                'locale' => 'en_GB',
                                'value' => $data[$headers['en_GB']] ?? ''
                            ],
                            'et_EE' => [
                                'id' => null,
                                'locale' => 'et_EE',
                                'value' => $data[$headers['et_EE']] ?? ''
                            ],
                            'fi_FI' => [
                                'id' => null,
                                'locale' => 'fi_FI',
                                'value' => $data[$headers['fi_FI']] ?? ''
                            ],
                            'fr_FR' => [
                                'id' => null,
                                'locale' => 'fr_FR',
                                'value' => $data[$headers['fr_FR']] ?? ''
                            ],
                            'it_IT' => [
                                'id' => null,
                                'locale' => 'it_IT',
                                'value' => $data[$headers['it_IT']] ?? ''
                            ],
                            'lt_LT' => [
                                'id' => null,
                                'locale' => 'lt_LT',
                                'value' => $data[$headers['lt_LT']] ?? ''
                            ],
                            'lv_LV' => [
                                'id' => null,
                                'locale' => 'lv_LV',
                                'value' => $data[$headers['lv_LV']] ?? ''
                            ],
                            'sv_SE' => [
                                'id' => null,
                                'locale' => 'sv_SE',
                                'value' => $data[$headers['sv_SE']] ?? ''
                            ],
                            'nb_NO' => [
                                'id' => null,
                                'locale' => 'nb_NO',
                                'value' => $data[$headers['nb_NO']] ?? ''
                            ],
                            'ru_RU' => [
                                'id' => null,
                                'locale' => 'ru_RU',
                                'value' => $data[$headers['ru_RU']] ?? ''
                            ]
                        ]
                    ];
                    $messages = $this->createOption($dataCreateOption);
                    foreach ($messages as $message) {
                        $output->writeln($message);
                    }
                }
            }
            fclose($handle);
        }

        return 0;
    }

    /**
     * @param array $data
     * @throws NotFoundHttpException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\ORMInvalidArgumentException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Symfony\Component\Form\Exception\AlreadySubmittedException
     * @throws \Symfony\Component\Form\Exception\LogicException
     * @throws \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     */
    protected function createOption(array $data)
    {
        $messages = [];
        foreach ($data as $codeAttribute => $dataOption) {
            $this->findAttributeOr404($codeAttribute);

            $sqlCheck = <<<SQL
                SELECT pcaov.value FROM pim_catalog_attribute as pca
                LEFT JOIN pim_catalog_attribute_option as pcao ON pcao.attribute_id = pca.id
                LEFT JOIN pim_catalog_attribute_option_value as pcaov ON pcao.id = pcaov.option_id
                WHERE pca.id = :attribute_id
                AND pcaov.locale_code = 'lt_LT'
                AND pcaov.value = :option_value
            SQL;

            $param = [
                'attribute_id' => $this->attributeObjects[$codeAttribute]->getId(),
                'option_value' => $dataOption['data']['optionValues']['lt_LT']['value'],
            ];

            if (!$this->connection->fetchAll($sqlCheck, $param)) {
                $attributeOption = $this->optionFactory->create();
                $attributeOption->setAttribute($this->attributeObjects[$codeAttribute]);

                $form = $this->formFactory->createNamed('option', AttributeOptionType::class, $attributeOption);
                $form->submit($dataOption['data'], false);

                if ($form->isValid()) {
                    $this->optionSaver->save($attributeOption);
                    $messages[] = 'Adding option... Attribute: '. $codeAttribute . '. Artnum: ' . $dataOption['data']['code'];
                } else {
                    $messages[] = 'Invalid... Attribute: '. $codeAttribute . '. Artnum: ' . $dataOption['data']['code'];

                }
                $this->em->flush();
            } else {
                $messages[] = 'Already exists... Attribute: '. $codeAttribute . '. Artnum: ' . $dataOption['data']['code'];
            }
        }

        return $messages;
    }

    /**
     * @param $code

     * @throws NotFoundHttpException
     */
    protected function findAttributeOr404($code)
    {
        if (!isset($this->attributeObjects[$code])) {
            $attribute = $this->attributeRepository->findOneBy(['code' => $code]);
            if ($attribute) {
                $this->attributeObjects[$code] = $attribute;
            } else {
                throw new NotFoundHttpException('Attribute not found: ' . $code);
            }
        }
    }
}
