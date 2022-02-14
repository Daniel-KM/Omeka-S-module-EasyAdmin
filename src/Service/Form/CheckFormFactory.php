<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\CheckForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CheckFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new CheckForm(null, $options);
        return $form
            ->setConnection($services->get('Omeka\Connection'));
    }
}
