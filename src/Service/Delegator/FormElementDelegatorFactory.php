<?php declare(strict_types=1);

namespace EasyAdmin\Service\Delegator;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

/**
 * @deprecated Use Omeka S v4 Form group.
 */
class FormElementDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name,
        callable $callback, array $options = null
    ) {
        /** @var \Laminas\Form\View\Helper\FormElement $formElement */
        $formElement = $callback();
        return $formElement
            ->addType('note', 'formNote')
        ;
    }
}
