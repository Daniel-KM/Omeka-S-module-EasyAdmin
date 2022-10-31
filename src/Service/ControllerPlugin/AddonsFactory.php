<?php declare(strict_types=1);

namespace EasyAdmin\Service\ControllerPlugin;

use EasyAdmin\Mvc\Controller\Plugin\Addons;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AddonsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Addons(
            $services->get('Omeka\HttpClient')
        );
    }
}
