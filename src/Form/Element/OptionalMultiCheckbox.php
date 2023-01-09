<?php declare(strict_types=1);

namespace EasyAdmin\Form\Element;

use Laminas\Form\Element\MultiCheckbox;

class OptionalMultiCheckbox extends MultiCheckbox
{
    use TraitOptionalElement;
}
