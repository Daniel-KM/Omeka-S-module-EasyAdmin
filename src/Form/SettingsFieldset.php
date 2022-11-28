<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use EasyAdmin\Form\Element as EasyAdminElement;
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
                'name' => 'easyadmin_maintenance_mode',
                'type' => EasyAdminElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Set Omeka under maintenance', // @translate
                    'value_options' => [
                        '' => 'No', // @translate
                        'public' => 'Public front-end', // @translate
                        'admin' => 'Admin (except global admins)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'easyadmin_maintenance_mode',
                    'required' => false,
                    'value' => '',
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
