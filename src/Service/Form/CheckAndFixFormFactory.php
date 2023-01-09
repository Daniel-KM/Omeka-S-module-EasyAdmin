<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\CheckAndFixForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CheckAndFixFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new CheckAndFIxForm(null, $options ?? []);
        $form->setEventManager($services->get('EventManager'));
        return $form
            ->setConnection($services->get('Omeka\Connection'));
    }
}
