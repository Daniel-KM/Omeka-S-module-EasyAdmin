<?php declare(strict_types=1);

namespace EasyAdmin\Service\ControllerPlugin;

use EasyAdmin\Mvc\Controller\Plugin\SpecifyMediaType;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SpecifyMediaTypeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $mediaTypesIdentifiers = require_once dirname(__DIR__, 3) . '/data/media-types/media-type-identifiers.php';
        return new SpecifyMediaType(
            $services->get('Omeka\Logger'),
            $mediaTypesIdentifiers
        );
    }
}
