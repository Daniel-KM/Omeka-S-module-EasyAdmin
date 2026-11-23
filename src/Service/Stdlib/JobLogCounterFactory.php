<?php declare(strict_types=1);

namespace EasyAdmin\Service\Stdlib;

use EasyAdmin\Stdlib\JobLogCounter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class JobLogCounterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new JobLogCounter($services->get('Omeka\Connection'));
    }
}
