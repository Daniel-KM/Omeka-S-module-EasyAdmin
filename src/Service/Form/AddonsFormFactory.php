<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\AddonsForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AddonsFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new AddonsForm(null, $options);
        $addons = $services->get('ControllerPluginManager')
            ->get('easyAdminAddons');
        $form->setAddons($addons);
        return $form;
    }
}
