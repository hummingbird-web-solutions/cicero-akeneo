<?php

namespace Verkter\Bundle\AppBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Class VerkterAppExtension
 * @package Verkter\Bundle\AppBundle\DependencyInjection
 */
class VerkterAppExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('cli_commands.yml');
        $loader->load('event_subscribers.yml');
        $loader->load('parameters.yml');
        $loader->load('updaters.yml');
        $loader->load('jobs.yml');
        $loader->load('processors.yml');
        $loader->load('steps.yml');
    }
}
