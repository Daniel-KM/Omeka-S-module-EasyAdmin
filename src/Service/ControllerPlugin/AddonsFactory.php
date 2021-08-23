<?php declare(strict_types=1);
namespace EasyInstall\Service\ControllerPlugin;

use EasyInstall\Mvc\Controller\Plugin\Addons;
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
