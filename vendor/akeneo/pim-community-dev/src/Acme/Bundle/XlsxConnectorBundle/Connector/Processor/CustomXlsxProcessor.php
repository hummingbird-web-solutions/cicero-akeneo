<?php

declare(strict_types=1);

namespace Acme\Bundle\XlsxConnectorBundle\Connector\Processor;

use Akeneo\Tool\Component\StorageUtils\Saver\SaverInterface;
use Akeneo\Pim\Enrichment\Component\Comment\Builder\CommentBuilder;
use Akeneo\Pim\Enrichment\Component\Comment\Model\CommentInterface;
use Akeneo\Pim\Enrichment\Component\Product\Connector\Processor\MassEdit\AbstractProcessor;
use Akeneo\UserManagement\Component\Repository\UserRepositoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CustomXlsxProcessor extends AbstractProcessor
{
    protected $commentBuilder;
    protected $commentSaver;
    protected $userRepository;

    public function __construct(
        CommentBuilder $commentBuilder,
        SaverInterface $commentSaver,
        UserRepositoryInterface $userRepository
    ) {
        $this->commentBuilder = $commentBuilder;
        $this->commentSaver = $commentSaver;
        $this->userRepository = $userRepository;
    }

    public function process($product)
    {
        echo "from custom processor";
        die;
        $actions = $this->getConfiguredActions();

        $comment = $this->commentBuilder->buildComment(
            $product,
            $this->userRepository->findOneByIdentifier($actions[0]['username'])
        )->setBody($actions[0]['value']);
        $this->commentSaver->save($comment);

        return $product;
    }
}