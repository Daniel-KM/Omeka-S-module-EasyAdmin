<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CronController extends AbstractActionController
{
    public function indexAction()
    {
        $settings = $this->settings();

        /** @var \EasyAdmin\Form\CronForm $form */
        $form = $this->getForm(\EasyAdmin\Form\CronForm::class);
        $form->init();

        // Load current settings.
        $cronSettings = $settings->get('easyadmin_cron', []);

        // Convert settings to form data.
        $formData = $form->prepareDataFromSettings($cronSettings);
        $form->setData($formData);

        // Prepare view data.
        $lastRun = $settings->get('easyadmin_cron_last');
        $cronCommand = $this->buildCronCommand();

        $view = new ViewModel([
            'form' => $form,
            'lastRun' => $lastRun,
            'cronCommand' => $cronCommand,
            'registeredTasks' => $form->getRegisteredTasks(),
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        // Handle "Run now" action.
        if (!empty($params['run_now'])) {
            return $this->runNow();
        }

        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $data = $form->getData();
        unset($data['csrf']);

        // Convert form data to settings structure.
        $newSettings = $form->prepareSettingsFromData($data);
        $settings->set('easyadmin_cron', $newSettings);

        // Keep backward compatibility with old setting name during transition.
        // TODO Remove in a future version.
        $oldTasks = [];
        foreach ($newSettings['tasks'] ?? [] as $taskId => $taskSettings) {
            if (!empty($taskSettings['enabled'])) {
                $oldTasks[] = $taskId;
            }
        }
        $settings->set('easyadmin_cron_tasks', $oldTasks);

        $enabledCount = count($oldTasks);
        if ($enabledCount) {
            $msg = new PsrMessage(
                '{count} tasks defined to be run regularly.', // @translate
                ['count' => $enabledCount]
            );
        } else {
            $msg = new PsrMessage(
                'No task defined to be run regularly.' // @translate
            );
        }
        $this->messenger()->addSuccess($msg);

        return $this->redirect()->toRoute('admin/easy-admin/cron', [], true);
    }

    /**
     * Run all enabled tasks immediately.
     */
    protected function runNow()
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $cronSettings = $settings->get('easyadmin_cron', []);
        $enabledTasks = [];
        foreach ($cronSettings['tasks'] ?? [] as $taskId => $taskSettings) {
            if (!empty($taskSettings['enabled'])) {
                $enabledTasks[$taskId] = $taskSettings;
            }
        }

        if (!count($enabledTasks)) {
            $this->messenger()->addWarning(new PsrMessage(
                'No tasks are enabled.' // @translate
            ));
            return $this->redirect()->toRoute('admin/easy-admin/cron', [], true);
        }

        // Dispatch the cron job to run all tasks.
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\EasyAdmin\Job\CronTasks::class, [
            'tasks' => $enabledTasks,
            'manual' => true,
        ]);

        // Update last run time.
        $settings->set('easyadmin_cron_last', time());

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing cron tasks in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/easy-admin/cron', [], true);
    }

    /**
     * Build the cron command suggestion.
     */
    protected function buildCronCommand(): string
    {
        /** @var \Laminas\View\Helper\ServerUrl $serverUrl */
        $serverUrl = $this->viewHelpers()->get('ServerUrl');
        $basePath = $this->viewHelpers()->get('BasePath');

        $baseUrl = rtrim($serverUrl(), '/') . $basePath();
        $scriptPath = realpath(OMEKA_PATH . '/modules/EasyAdmin/data/scripts/task.php');

        // Suggest daily execution by default.
        return sprintf(
            '0 0 * * * php %s --task="EasyAdmin\\Job\\CronTasks" --user-id=1 --server-url="%s" --base-path="%s"',
            $scriptPath,
            rtrim($serverUrl(), '/'),
            $basePath()
        );
    }
}
