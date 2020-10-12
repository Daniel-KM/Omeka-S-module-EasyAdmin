<?php declare(strict_types=1);
namespace BulkCheck\Service\Form;

use BulkCheck\Form\BulkCheckForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkCheckFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new BulkCheckForm(null, $options);
        return $form
            ->setConnection($services->get('Omeka\Connection'));
    }
}
