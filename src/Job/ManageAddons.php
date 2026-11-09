<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Laminas\I18n\Translator\TranslatorAwareInterface;
use Laminas\Log\Logger;
use Omeka\Job\AbstractJob;
use Omeka\Mvc\Controller\Plugin\Messenger;

class ManageAddons extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    public function perform(): void
    {
        /**
         * @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */

        $services = $this->getServiceLocator();

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'easy-admin/addons/job_' . $this->job->getId()
        );

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $plugins = $services->get('ControllerPluginManager');
        $addons = $plugins->get('easyAdminAddons');
        $messenger = $plugins->get('messenger');

        $operation = $this->getArg('operation', 'install');
        $options = $this->getArg('options', []);

        $this->logger->notice(
            'Starting the process "{operation}" for {count} addons: {addons}.', // @translate
            [
                'operation' => $operation,
                'count' => count($this->getArg('addons', [])),
                'addons' => implode(', ', $this->getArg('addons', [])),
            ]
        );

        switch ($operation) {
            case 'install':
                $this->performInstall($addons, $messenger);
                break;
            case 'update':
                $this->performUpdate($addons, $messenger, $options);
                break;
            case 'upgrade':
                // Download the new version of the modules, then finalize the
                // upgrade of their database.
                $this->performUpdate($addons, $messenger, ['auto_upgrade' => true] + $options);
                break;
            case 'finalize':
                $this->performFinalize($addons, $messenger);
                break;
            case 'remove':
                $this->performRemove($addons, $messenger);
                break;
            default:
                // Legacy: selection-based install.
                $this->performInstall($addons, $messenger);
                break;
        }
    }

    protected function performInstall($addons, $messenger): void
    {
        // Support legacy selection mode.
        $selection = $this->getArg('selection');
        $addonList = $this->getArg('addons', []);

        if ($selection) {
            $selections = $addons->getSelections();
            $addonList = $selections[$selection] ?? [];
        }

        // Install the dependencies before the modules that require them.
        $addonList = $addons->sortByDependencies($addonList);

        $unknowns = [];
        $existings = [];
        $errors = [];
        $installeds = [];
        foreach ($addonList as $addonName) {
            $addon = $addons->dataFromNamespace($addonName);
            if (!$addon) {
                $unknowns[] = $addonName;
            } elseif ($addons->dirExists($addon)) {
                $existings[] = $addonName;
            } else {
                $result = $addons->installAddon($addon);
                if ($result) {
                    $installeds[] = $addonName;
                } else {
                    $errors[] = $addonName;
                }
                // Log after each addon: the messages would be lost when the
                // job is stopped or fails in the middle of the process.
                $this->flushMessages($messenger);
            }
        }

        $this->flushMessages($messenger);
        $this->logSummary(
            'install',
            $unknowns,
            $existings,
            $errors,
            $installeds
        );
    }

    protected function performUpdate(
        $addons,
        $messenger,
        array $options
    ): void {
        $type = $this->getArg('type', 'module');
        $addonList = $this->getArg('addons', []);
        // Update the dependencies before the modules that require them. Only
        // modules have dependencies.
        if ($type === 'module') {
            $addonList = $addons->sortByDependencies($addonList);
        }
        $autoUpgrade = !empty($options['auto_upgrade']);

        $errors = [];
        $updated = [];
        foreach ($addonList as $addonName) {
            $addon = $addons->dataFromNamespace($addonName, $type)
                ?: $addons->dataFromNamespace($addonName);
            if (!$addon) {
                $this->logger->warn(
                    'Unknown addon for update: {name}.', // @translate
                    ['name' => $addonName]
                );
                // The files of an unknown module may be updated manually.
                if ($autoUpgrade) {
                    $updated[] = $addonName;
                }
                continue;
            }
            $result = $addons->updateAddon($addon);
            if ($result) {
                $updated[] = $addonName;
            } else {
                $errors[] = $addonName;
            }
            // Log after each addon: the messages would be lost when the job is
            // stopped or fails in the middle of the process.
            $this->flushMessages($messenger);
        }

        $this->flushMessages($messenger);

        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'Failed to update: {addons}.', // @translate
                ['addons' => implode(', ', $errors)]
            );
        }
        if ($updated) {
            $this->logger->notice(
                'Updated: {addons}.', // @translate
                ['addons' => implode(', ', $updated)]
            );
        }

        // The database is upgraded in a new process: this one has loaded the
        // previous version of the modules, so the module manager does not know
        // the new files.
        if ($autoUpgrade && $updated && $type === 'module') {
            $job = $this->getServiceLocator()->get(\Omeka\Job\Dispatcher::class)->dispatch(
                self::class,
                ['operation' => 'finalize', 'type' => 'module', 'addons' => $updated]
            );
            $this->logger->notice(
                'The upgrade of the database of the modules is processed in job #{job_id}.', // @translate
                ['job_id' => $job->getId()]
            );
        }
    }

    /**
     * Upgrade the database of modules whose files are already updated.
     *
     * This is not the download of the new files, that is done by the operation
     * "update".
     */
    protected function performFinalize($addons, $messenger): void
    {
        // Upgrade the dependencies before the modules that require them.
        $addonList = $addons->sortByDependencies(
            $this->getArg('addons', [])
        );

        $upgraded = [];
        $errors = [];
        $skipped = [];
        foreach ($addonList as $addonName) {
            $result = $this->tryUpgradeDb($addonName);
            if ($result === true) {
                $upgraded[] = $addonName;
            } elseif ($result === false) {
                $errors[] = $addonName;
            } else {
                $skipped[] = $addonName;
            }
            $this->flushMessages($messenger);
        }

        $this->flushMessages($messenger);

        if ($skipped) {
            $this->logger->notice(
                'No upgrade needed for: {addons}. Only the modules whose files were updated can be upgraded.', // @translate
                ['addons' => implode(', ', $skipped)]
            );
        }
        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'Failed to upgrade: {addons}.', // @translate
                ['addons' => implode(', ', $errors)]
            );
        }
        if ($upgraded) {
            $this->logger->notice(
                'Upgraded: {addons}.', // @translate
                ['addons' => implode(', ', $upgraded)]
            );
        }
    }

    protected function performRemove($addons, $messenger): void
    {
        $type = $this->getArg('type', 'module');
        $addonList = $this->getArg('addons', []);
        // Remove the modules that require a dependency before the dependency.
        // Only modules have dependencies.
        if ($type === 'module') {
            $addonList = $addons->sortByDependencies($addonList, true);
        }

        $errors = [];
        $removed = [];
        foreach ($addonList as $addonName) {
            $addon = $addons->dataFromNamespace($addonName, $type)
                ?: $addons->dataFromNamespace($addonName);
            if (!$addon) {
                $addon = [
                    'type' => $type,
                    'name' => $addonName,
                    'dir' => $addonName,
                    'basename' => $addonName,
                    'url' => '',
                    'zip' => '',
                    'version' => '',
                    'dependencies' => [],
                ];
            }
            $result = $addons->removeAddon($addon);
            if ($result) {
                $removed[] = $addonName;
            } else {
                $errors[] = $addonName;
            }
            // Log after each addon: the messages would be lost when the job is
            // stopped or fails in the middle of the process.
            $this->flushMessages($messenger);
        }

        $this->flushMessages($messenger);

        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'Failed to remove: {addons}.', // @translate
                ['addons' => implode(', ', $errors)]
            );
        }
        if ($removed) {
            $this->logger->notice(
                'Removed: {addons}.', // @translate
                ['addons' => implode(', ', $removed)]
            );
        }
    }

    /**
     * Upgrade the database of a module that needs it.
     *
     * @return bool|null True when upgraded, false on error, null when the module
     * does not need an upgrade.
     */
    protected function tryUpgradeDb(string $moduleId): ?bool
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleId);
        if ($module
            && $module->getState()
                === \Omeka\Module\Manager::STATE_NEEDS_UPGRADE
        ) {
            try {
                $moduleManager->upgrade($module);
                $this->logger->notice(
                    'Upgrade of module "{name}" finalized.', // @translate
                    ['name' => $moduleId]
                );
                return true;
            } catch (\Throwable $e) {
                $this->logger->err(
                    'Upgrade of module "{name}" could not be finalized: {error}', // @translate
                    [
                        'name' => $moduleId,
                        'error' => $e->getMessage(),
                    ]
                );
                return false;
            }
        }
        return null;
    }

    /**
     * Convert messenger messages into logger entries.
     */
    protected function flushMessages($messenger): void
    {
        $typesToLogPriorities = [
            Messenger::ERROR => Logger::ERR,
            Messenger::SUCCESS => Logger::NOTICE,
            Messenger::WARNING => Logger::WARN,
            Messenger::NOTICE => Logger::INFO,
        ];
        foreach ($messenger->get() as $type => $messages) {
            foreach ($messages as $message) {
                $priority = $typesToLogPriorities[$type]
                    ?? Logger::NOTICE;
                if ($message instanceof TranslatorAwareInterface) {
                    $this->logger->log(
                        $priority,
                        $message->getMessage(),
                        $message->getContext()
                    );
                } else {
                    $this->logger->log(
                        $priority,
                        (string) $message
                    );
                }
            }
        }

        // Messenger::get() does not empty the stack, so clear it to avoid
        // logging the same messages again on the next flush.
        $messenger->clear();
    }

    protected function logSummary(
        string $operation,
        array $unknowns,
        array $existings,
        array $errors,
        array $success
    ): void {
        if ($unknowns) {
            $this->logger->notice(
                'The following addons are unknown: {addons}.', // @translate
                ['addons' => implode(', ', $unknowns)]
            );
        }
        if ($existings) {
            $this->logger->notice(
                'The following addons are already installed: {addons}.', // @translate
                ['addons' => implode(', ', $existings)]
            );
        }
        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'The following addons failed to {operation}: {addons}.', // @translate
                [
                    'operation' => $operation,
                    'addons' => implode(', ', $errors),
                ]
            );
        }
        if ($success) {
            $this->logger->notice(
                'The following addons were successfully with {operation}: {addons}.', // @translate
                [
                    'operation' => $operation,
                    'addons' => implode(', ', $success),
                ]
            );
        }
    }
}
