<?php declare(strict_types=1);

namespace EasyAdmin\Service\ViewHelper;

use EasyAdmin\View\Helper\PreviousResource;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PreviousResourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $currentSite = $services->get('ViewHelperManager')->get('currentSite');
        return new PreviousResource(
            $services->get('Omeka\ApiAdapterManager'),
            $currentSite()
        );
    }
}
