<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Common\Form\Element as CommonElement;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerAwareTrait;
use Laminas\Form\Form;

class CronForm extends Form
{
    use EventManagerAwareTrait;

    public function init(): void
    {
        // TODO This is a first form for cron, that will be improved: start, rythm, modules, options, short/long.

        $this
            ->setAttribute('id', 'form-cron')
            ->add([
                'name' => 'easyadmin_cron_tasks',
                // TODO Use a full cron config or a single numeric input.
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Tasks to run once a day', // @translate
                    'label_attributes' => [
                        'style' => 'display:inline-block',
                    ],
                    'value_options' => [
                        'session_1h' => 'Clear sessions older than 1 hour', // @translate
                        'session_2h' => 'Clear sessions older than 2 hours', // @translate
                        'session_4h' => 'Clear sessions older than 4 hours', // @translate
                        'session_12h' => 'Clear sessions older than 12 hours', // @translate
                        'session_1d' => 'Clear sessions older than 1 day', // @translate
                        'session_2d' => 'Clear sessions older than 2 days', // @translate
                        'session_8d' => 'Clear sessions older than 8 days', // @translate
                        'session_40d' => 'Clear sessions older than 40 days', // @translate
                        'session_100d' => 'Clear sessions older than 100 days', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'easyadmin_cron_tasks',
                ],
            ])
        ;

        $event = new Event('form.add_elements', $this);
        $this->getEventManager()->triggerEvent($event);

        $inputFilter = $this->getInputFilter();

        $event = new Event('form.add_input_filters', $this, ['inputFilter' => $inputFilter]);
        $this->getEventManager()->triggerEvent($event);
    }
}
