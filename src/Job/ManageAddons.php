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
         * @var \Common\View\Helper\Messages $messages
         */

        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('easy-admin/addons/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $plugins = $services->get('ControllerPluginManager');
        $helpers = $services->get('ViewHelperManager');

        $addons = $plugins->get('easyAdminAddons');
        $messenger = $plugins->get('messenger');
        $messages = $helpers->get('messages');

        $selection = $this->getArg('selection');
        $selections = $addons->getSelections();
        $selectionAddons = $selections[$selection] ?? [];

        $unknowns = [];
        $existings = [];
        $errors = [];
        $installeds = [];
        foreach ($selectionAddons as $addonName) {
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
            }
        }

        // Convert messsages from addons into logs.
        // TODO Use Messages->log() (Common 3.4.65).
        $typesToLogPriorities = [
            Messenger::ERROR => Logger::ERR,
            Messenger::SUCCESS => Logger::NOTICE,
            Messenger::WARNING => Logger::WARN,
            Messenger::NOTICE => Logger::INFO,
        ];
        foreach ($messenger->get() as $type => $messages) {
            foreach ($messages as $message) {
                $priority = $typesToLogPriorities[$type] ?? Logger::NOTICE;
                if ($message instanceof TranslatorAwareInterface) {
                    $this->logger->log($priority, $message->getMessage(), $message->getContext());
                } else {
                    $this->logger->log($priority, (string) $message);
                }
            }
        }

        if (count($unknowns)) {
            $this->logger->notice(
                'The following modules of the selection are unknown: {addons}.', // @translate
                ['addons' => implode(', ', $unknowns)]
            );
        }
        if (count($existings)) {
            $this->logger->notice(
                'The following modules are already installed: {addons}.', // @translate
                ['addons' => implode(', ', $existings)]
            );
        }
        if (count($errors)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->error(
                'The following modules cannot be installed: {addons}.', // @translate
                ['addons' => implode(', ', $errors)]
            );
        }
        if (count($installeds)) {
            $this->logger->notice(
                'The following modules have been installed: {addons}.', // @translate
                ['addons' => implode(', ', $installeds)]
            );
        }
    }
}
