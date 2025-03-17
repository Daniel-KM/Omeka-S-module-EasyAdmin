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
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Tasks to run once a day', // @translate
                    'value_options' => [
                        'session_2' => 'Clear sessions older than 2 days', // @translate
                        'session_8' => 'Clear sessions older than 8 days', // @translate
                        'session_40' => 'Clear sessions older than 40 days', // @translate
                        'session_100' => 'Clear sessions older than 100 days', // @translate
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
