<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Easy Admin'; // @translate

    public function init(): void
    {
        $this
            ->setAttribute('id', 'easy-admin')
            ->add([
                'name' => 'easyadmin_content_lock',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Enable content lock to avoid simultaneous editing', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_content_lock',
                ],
            ])
            ->add([
                'name' => 'easyadmin_content_lock_duration',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of seconds before automatic removing of the lock', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_content_lock_duration',
                ],
            ]);
    }
}
