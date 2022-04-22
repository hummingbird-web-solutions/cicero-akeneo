<?php

namespace Webkul\Magento2Bundle\Connector\Reader;

use Webkul\Magento2Bundle\EventListener\AkeneoVersionsCompatibility;

$classLoad = new AkeneoVersionsCompatibility();
$classLoad->checkVersionAndCreateClassAliases();

class ProductAssosiationReader implements \ItemReaderInterface, \InitializableInterface, \StepExecutionAwareInterface
{
    
    /** @var \ProductQueryBuilderFactoryInterface */
    private $pqbFactory;

    /** @var \ChannelRepositoryInterface */
    private $channelRepository;

    /** @var \CompletenessManager */
    private $completenessManager;

    /** @var \StepExecution */
    private $stepExecution;

    /** @var \CursorInterface */
    private $productsAndProductModels;

    /** @var boolean */
    private $readChildren;

    /**
     * @param \ProductQueryBuilderFactoryInterface $pqbFactory
     * @param \ChannelRepositoryInterface          $channelRepository
     * @param \CompletenessManager                 $completenessManager
     * @param boolean                             $readChildren
     */
    public function __construct(
        \ProductQueryBuilderFactoryInterface $pqbFactory,
        \ChannelRepositoryInterface $channelRepository,
        \CompletenessManager $completenessManager,
        $readChildren
    ) {
        $this->pqbFactory          = $pqbFactory;
        $this->channelRepository   = $channelRepository;
        $this->completenessManager = $completenessManager;
        $this->readChildren        = $readChildren;
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(): void
    {
        $channel = $this->getConfiguredChannel();
        if (null !== $channel) {
            $this->completenessManager->generateMissingForChannel($channel);
        }
        $filters = $this->getConfiguredFilters();
        
        $this->productsAndProductModels = $this->getCursor($filters, $channel);
    }

    /**
     * {@inheritdoc}
     */
    public function read(): ?EntityWithFamilyInterface
    {
        $entity = null;

        if ($this->productsAndProductModels->valid()) {
            $entity = $this->productsAndProductModels->current();
            $this->productsAndProductModels->next();
            $this->stepExecution->incrementSummaryInfo('read');
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(\StepExecution $stepExecution): void
    {
        $this->stepExecution = $stepExecution;
    }

    /**
     * Returns the configured channel from the parameters.
     * If no channel is specified, returns null.
     *
     * @throws ObjectNotFoundException
     *
     * @return \ChannelInterface|null
     */
    private function getConfiguredChannel(): ?\ChannelInterface
    {
        $parameters = $this->stepExecution->getJobParameters();
        if (!isset($parameters->get('filters')['structure']['scope'])) {
            return null;
        }

        $channelCode = $parameters->get('filters')['structure']['scope'];
        $channel = $this->channelRepository->findOneByIdentifier($channelCode);
        if (null === $channel) {
            throw new ObjectNotFoundException(sprintf('Channel with "%s" code does not exist', $channelCode));
        }

        return $channel;
    }

    /**
     * Returns the filters from the configuration.
     * The parameters can be in the 'filters' root node, or in filters data node (e.g. for export).
     *
     * Here we transform the ID filter into SELF_AND_ANCESTOR.ID in order to retrieve
     * all the product models and products that are possibly impacted by the mass edit.
     *
     * @return array
     */
    private function getConfiguredFilters(): array
    {
        $filters = $this->stepExecution->getJobParameters()->get('filters');

        if (array_key_exists('data', $filters)) {
            $filters = $filters['data'];
        }

        if ($this->readChildren) {
            $filters = array_map(function ($filter) {
                if ('id' === $filter['field']) {
                    $filter['field'] = 'self_and_ancestor.id';
                }

                return $filter;
            }, $filters);
        }

        return array_filter($filters, function ($filter) {
            return count($filter) > 0;
        });
    }

    /**
     * @param array            $filters
     * @param \ChannelInterface $channel
     *
     * @return \CursorInterface
     */
    private function getCursor(array $filters, \ChannelInterface $channel = null): \CursorInterface
    {
        foreach ($filters as $key => $filter) {
            if ($filter["field"] === "completeness") {
                unset($filters[$key]);
            }
        }

        $options = ['filters' => $filters];
        
        if (null !== $channel) {
            $options['default_scope'] = $channel->getCode();
        }
        
        
        $queryBuilder = $this->pqbFactory->create($options);

            
        return ($queryBuilder->execute());
    }
}
