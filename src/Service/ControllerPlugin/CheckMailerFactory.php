<?php declare(strict_types=1);

namespace EasyAdmin\Service\ControllerPlugin;

use EasyAdmin\Mvc\Controller\Plugin\CheckMailer;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CheckMailerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        return new CheckMailer(
            $services->get('Config'),
            $plugins->get('messenger'),
            $services->get('Omeka\Settings')
        );
    }
}
