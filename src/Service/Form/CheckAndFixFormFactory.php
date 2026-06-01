<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\CheckAndFixForm;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CheckAndFixFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $candidates = [
            'value_annotation' => 'value_annotations',
            'annotation' => 'annotations',
            'digital_object' => 'digital_objects',
        ];
        $available = ['items', 'item_sets', 'media'];
        foreach ($candidates as $table => $apiName) {
            try {
                $connection->executeQuery(sprintf('SELECT 1 FROM `%s` LIMIT 1', $table));
                $available[] = $apiName;
            } catch (\Throwable $e) {
                // Table absent: skip.
            }
        }

        $options = ($options ?? []) + [
            'available_resource_types' => $available,
        ];

        $form = new CheckAndFixForm(null, $options);
        $form->setEventManager($services->get('EventManager'));
        return $form;
    }
}
