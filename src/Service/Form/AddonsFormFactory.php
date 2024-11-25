<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\AddonsForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AddonsFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        $csv = @file_get_contents('https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/refs/heads/master/_data/omeka_s_selections.csv');
        $selections = [];
        if ($csv) {
            $headers = [];
            $isFirst = true;
            foreach (explode("\n", $csv) as $row) {
                $row = str_getcsv($row) ?: [];
                if ($isFirst) {
                    $headers = array_flip($row);
                    $isFirst = false;
                } elseif ($row) {
                    $name = $row[$headers['Name']] ?? '';
                    if ($name) {
                        $selections[] = $name;
                    }
                }
            }
        }

        $form = new AddonsForm();
        return $form
            ->setAddons($plugins->get('easyAdminAddons'))
            ->setSelections($selections)
        ;
    }
}
