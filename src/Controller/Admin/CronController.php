<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CronController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getServiceLocator();

        // Check if Cron module is active - if so, it handles the cron page.
        // The route is handled by Cron module, so we only reach here if Cron is not installed.
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $cronModule = $moduleManager->getModule('Cron');
        $hasCronModule = $cronModule && $cronModule->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        // This controller is only used when Cron module is not installed.
        // When Cron is installed, its route takes precedence.

        // Cron module not installed - show legacy form with warning.
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
            'cronModuleMissing' => true,
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
     * Run all enabled tasks immediately (legacy mode).
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

        // Execute legacy session cleanup directly.
        $this->executeSessionCleanupLegacy($enabledTasks);

        // Update last run time.
        $settings->set('easyadmin_cron_last', time());

        $this->messenger()->addSuccess(new PsrMessage(
            'Cron tasks executed.' // @translate
        ));

        return $this->redirect()->toRoute('admin/easy-admin/cron', [], true);
    }

    /**
     * Legacy session cleanup (when Cron module not installed).
     */
    protected function executeSessionCleanupLegacy(array $enabledTasks): void
    {
        $sessionSecondsMap = [
            'session_1h' => 3600,
            'session_2h' => 7200,
            'session_4h' => 14400,
            'session_12h' => 43200,
            'session_1d' => 86400,
            'session_2d' => 172800,
            'session_8d' => 691200,
            'session_30d' => 2592000,
        ];

        $services = $this->getServiceLocator();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $time = time();

        foreach ($enabledTasks as $taskId => $taskSettings) {
            $seconds = $sessionSecondsMap[$taskId] ?? null;
            if ($seconds === null) {
                continue;
            }

            $sql = 'DELETE FROM `session` WHERE `modified` < :time;';
            $connection->executeStatement(
                $sql,
                ['time' => $time - $seconds],
                ['time' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
        }
    }

    /**
     * Build the cron command suggestion.
     */
    protected function buildCronCommand(): string
    {
        /** @var \Laminas\View\Helper\ServerUrl $serverUrl */
        $serverUrl = $this->viewHelpers()->get('ServerUrl');
        $basePath = $this->viewHelpers()->get('BasePath');

        $scriptPath = realpath(OMEKA_PATH . '/modules/EasyAdmin/data/scripts/task.php');

        // Suggest daily execution by default.
        return sprintf(
            '0 0 * * * php %s --task="EasyAdmin\\Job\\DbSession" --user-id=1 --server-url="%s" --base-path="%s" -a \'{"process":"db_session_clean","seconds":691200}\'',
            $scriptPath,
            rtrim($serverUrl(), '/'),
            $basePath()
        );
    }

    /**
     * Helper to get service locator.
     */
    protected function getServiceLocator()
    {
        return $this->getEvent()->getApplication()->getServiceManager();
    }
}
