<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Laminas\EventManager\Event;
use Omeka\Job\AbstractJob;

/**
 * Execute scheduled cron tasks.
 *
 * This job runs all enabled tasks configured in the cron settings.
 * Tasks can be:
 * - Built-in tasks (session cleanup)
 * - Module-provided jobs (dispatched as sub-jobs)
 * - Module-provided callbacks (executed inline)
 *
 * Modules can register tasks via the 'easyadmin.cron.tasks' event on CronForm.
 */
class CronTasks extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Omeka\Job\Dispatcher
     */
    protected $dispatcher;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // Set up logger with reference.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('easy-admin/cron/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->connection = $services->get('Omeka\Connection');
        $this->dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        $tasks = $this->getArg('tasks', []);
        $isManual = $this->getArg('manual', false);

        if (!count($tasks)) {
            $this->logger->notice('No tasks to execute.'); // @translate
            return;
        }

        $this->logger->notice(
            'Starting cron execution with {count} tasks.', // @translate
            ['count' => count($tasks)]
        );

        $executedCount = 0;
        $errorCount = 0;

        foreach ($tasks as $taskId => $taskSettings) {
            if ($this->shouldStop()) {
                $this->logger->warn('Job stopped by user.'); // @translate
                break;
            }

            try {
                $this->executeTask($taskId, $taskSettings);
                $executedCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $this->logger->err(
                    'Error executing task "{task}": {error}', // @translate
                    ['task' => $taskId, 'error' => $e->getMessage()]
                );
            }
        }

        if ($errorCount && !$executedCount) {
            throw new \Common\Job\Exception\RuntimeException(
                'Cron execution failed: all {errors} tasks failed.', // @translate
                ['errors' => $errorCount]
            );
        }

        $this->logger->notice(
            'Cron execution completed: {executed} tasks executed, {errors} errors.', // @translate
            ['executed' => $executedCount, 'errors' => $errorCount]
        );
    }

    /**
     * Execute a single task.
     */
    protected function executeTask(string $taskId, array $taskSettings): void
    {
        $this->logger->info(
            'Executing task "{task}".', // @translate
            ['task' => $taskId]
        );

        // Built-in session cleanup task (real id + "age" param, or legacy
        // flattened id whose suffix is the clean value).
        if ($taskId === 'session' || strncmp($taskId, 'session_', 8) === 0) {
            $params = $taskSettings['params'] ?? [];
            $age = $params['age'] ?? (strncmp($taskId, 'session_', 8) === 0 ? substr($taskId, 8) : '');
            $this->executeSessionCleanup((string) $age);
            return;
        }

        // Check for module-registered task via event.
        // Trigger event to let modules handle their own tasks.
        $services = $this->getServiceLocator();
        $eventManager = $services->get('EventManager');

        $event = new Event('easyadmin.cron.execute', $this, [
            'task_id' => $taskId,
            'task_settings' => $taskSettings,
            'handled' => false,
        ]);

        // Allow modules to handle their tasks.
        $eventManager->setIdentifiers([self::class]);
        $eventManager->triggerEvent($event);

        if (!$event->getParam('handled')) {
            $this->logger->warn(
                'Task "{task}" has no handler.', // @translate
                ['task' => $taskId]
            );
        }
    }

    /**
     * Execute session cleanup task.
     */
    protected function executeSessionCleanup(string $age): void
    {
        $seconds = \EasyAdmin\Job\DbSession::secondsForAge($age);
        if ($seconds === null) {
            return;
        }

        $time = time();

        // Check if index exists for performance.
        $result = $this->connection->executeQuery(
            'SHOW INDEX FROM `session` WHERE `column_name` = "modified";'
        );

        if ($result->fetchOne()) {
            // Direct delete with index.
            $sql = 'DELETE FROM `session` WHERE `modified` < :time;';
            $deleted = $this->connection->executeStatement(
                $sql,
                ['time' => $time - $seconds],
                ['time' => \Doctrine\DBAL\ParameterType::INTEGER]
            );

            $this->logger->info(
                'Session cleanup: {count} old sessions removed (older than {seconds} seconds).', // @translate
                ['count' => $deleted, 'seconds' => $seconds]
            );
        } else {
            // Dispatch as background job for tables without index.
            $job = $this->dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, [
                'seconds' => $seconds,
                'quick' => true,
            ]);

            $this->logger->info(
                'Session cleanup dispatched as job #{job_id} (older than {seconds} seconds).', // @translate
                ['job_id' => $job->getId(), 'seconds' => $seconds]
            );
        }
    }
}
