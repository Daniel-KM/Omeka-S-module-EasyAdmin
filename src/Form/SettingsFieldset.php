<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\CkeditorInline;

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
            ])

            ->add([
                'name' => 'easyadmin_maintenance_status',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Set the public front-end under maintenance', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_maintenance_status',
                ],
            ])
            ->add([
                'name' => 'easyadmin_maintenance_text',
                'type' => CkeditorInline::class,
                'options' => [
                    'label' => 'Text to display for maintenance', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_maintenance_text',
                    'rows' => 12,
                    'placeholder' => 'This site is down for maintenance. Please contact the site administrator for more information.', // @translate
                ],
            ]);
    }
}
