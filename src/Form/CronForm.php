<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Common\Form\Element as CommonElement;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Cron configuration form.
 *
 * Tasks are registered via event 'easyadmin.cron.tasks'. Each task defines:
 * - id: unique task identifier
 * - label: display name
 * - module: source module name
 * - job: job class to dispatch (optional)
 * - callback: callable for quick inline tasks (optional)
 * - frequencies: supported frequencies ['hourly', 'daily', 'weekly'] (optional)
 * - default_frequency: default frequency (optional, defaults to 'daily')
 * - options: sub-options for configurable tasks like session cleanup (optional)
 *
 * Settings are stored as:
 * [
 *     'tasks' => ['task_id' => ['enabled' => true, 'frequency' => 'hourly', 'option' => 'value'], ...],
 *     'global_frequency' => 'daily', // Used if individual frequencies not set.
 * ]
 */
class CronForm extends Form
{
    use EventManagerAwareTrait;

    /**
     * @var array Registered cron tasks from modules.
     */
    protected $registeredTasks = [];

    public function init(): void
    {
        $this->setAttribute('id', 'form-cron');

        // Collect tasks from modules via event.
        $this->collectTasks();

        // Build task checkboxes.
        $taskOptions = $this->buildTaskOptions();

        $this
            ->add([
                'name' => 'cron_tasks',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Scheduled tasks', // @translate
                    'value_options' => $taskOptions,
                ],
                'attributes' => [
                    'id' => 'cron_tasks',
                ],
            ])
            ->add([
                'name' => 'cron_frequency',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Frequency', // @translate
                    'info' => 'How often tasks should run. For precise control, use server cron.', // @translate
                    'value_options' => [
                        'hourly' => 'Hourly', // @translate
                        'daily' => 'Daily', // @translate
                        'weekly' => 'Weekly', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'cron_frequency',
                    'value' => 'hourly',
                ],
            ])
        ;

        // Allow modules to add extra elements.
        $event = new Event('form.add_elements', $this);
        $this->getEventManager()->triggerEvent($event);

        $inputFilter = $this->getInputFilter();
        $event = new Event('form.add_input_filters', $this, ['inputFilter' => $inputFilter]);
        $this->getEventManager()->triggerEvent($event);
    }

    /**
     * Collect cron tasks from all modules via event.
     *
     * Modules attach to 'easyadmin.cron.tasks' and add their tasks to the
     * event params['tasks'] array. Example:
     *
     * $sharedEventManager->attach(
     *     \EasyAdmin\Form\CronForm::class,
     *     'easyadmin.cron.tasks',
     *     function ($event) {
     *         $tasks = $event->getParam('tasks', []);
     *         $tasks['my_task'] = [
     *             'label' => 'My task description',
     *             'module' => 'MyModule',
     *             'job' => \MyModule\Job\MyJob::class,
     *             'frequencies' => ['hourly', 'daily'],
     *             'default_frequency' => 'daily',
     *         ];
     *         $event->setParam('tasks', $tasks);
     *     }
     * );
     */
    protected function collectTasks(): void
    {
        // Default EasyAdmin tasks.
        $defaultTasks = [
            'session' => [
                'label' => 'Clear old sessions', // @translate
                'module' => 'EasyAdmin',
                'task_type' => 'builtin',
                'frequencies' => ['hourly', 'daily'],
                'default_frequency' => 'daily',
                'options' => [
                    'session_1h' => 'older than 1 hour', // @translate
                    'session_2h' => 'older than 2 hours', // @translate
                    'session_4h' => 'older than 4 hours', // @translate
                    'session_12h' => 'older than 12 hours', // @translate
                    'session_1d' => 'older than 1 day', // @translate
                    'session_2d' => 'older than 2 days', // @translate
                    'session_8d' => 'older than 8 days', // @translate
                    'session_30d' => 'older than 30 days', // @translate
                ],
                'default_option' => 'session_8d',
            ],
        ];

        // Collect tasks from other modules via shared event.
        $event = new Event('easyadmin.cron.tasks', $this, ['tasks' => $defaultTasks]);
        $this->getEventManager()->triggerEvent($event);

        $this->registeredTasks = $event->getParam('tasks', $defaultTasks);
    }

    /**
     * Build value options for the task checkboxes.
     */
    protected function buildTaskOptions(): array
    {
        $options = [];

        // TODO Translate labels and option label.
        foreach ($this->registeredTasks as $taskId => $task) {
            $module = $task['module'] ?? 'Unknown';
            $label = $task['label'] ?? $taskId;

            // For tasks with sub-options (like session cleanup).
            if (!empty($task['options'])) {
                foreach ($task['options'] as $optionId => $optionLabel) {
                    $options[$optionId] = sprintf('[%s] %s (%s)', $module, $label, $optionLabel);
                }
            } else {
                $options[$taskId] = sprintf('[%s] %s', $module, $label);
            }
        }

        return $options;
    }

    /**
     * Get all registered tasks.
     */
    public function getRegisteredTasks(): array
    {
        return $this->registeredTasks;
    }

    /**
     * Convert form data to settings structure.
     *
     * Converts flat form data to structured settings.
     */
    public function prepareSettingsFromData(array $data): array
    {
        $settings = [
            'tasks' => [],
            'global_frequency' => $data['cron_frequency'] ?? 'daily',
        ];

        $enabledTasks = $data['cron_tasks'] ?? [];
        foreach ($this->registeredTasks as $taskId => $task) {
            // Handle tasks with sub-options.
            if (!empty($task['options'])) {
                foreach ($task['options'] as $optionId => $optionLabel) {
                    if (in_array($optionId, $enabledTasks)) {
                        $settings['tasks'][$optionId] = [
                            'enabled' => true,
                            'frequency' => $data['cron_frequency'] ?? $task['default_frequency'] ?? 'daily',
                            'parent_task' => $taskId,
                        ];
                    }
                }
            } else {
                if (in_array($taskId, $enabledTasks)) {
                    $settings['tasks'][$taskId] = [
                        'enabled' => true,
                        'frequency' => $data['cron_frequency'] ?? $task['default_frequency'] ?? 'daily',
                    ];
                }
            }
        }

        return $settings;
    }

    /**
     * Convert settings structure to form data.
     */
    public function prepareDataFromSettings(array $settings): array
    {
        $data = [
            'cron_tasks' => [],
            'cron_frequency' => $settings['global_frequency'] ?? 'daily',
        ];

        foreach ($settings['tasks'] ?? [] as $taskId => $taskSettings) {
            if (!empty($taskSettings['enabled'])) {
                $data['cron_tasks'][] = $taskId;
            }
        }

        return $data;
    }
}
