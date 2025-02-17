<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CheckAndFixController extends AbstractActionController
{
    public function indexAction()
    {
        /** @var \EasyAdmin\Form\CheckAndFixForm $form */
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        $view = new ViewModel([
            'form' => $form,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        // Only first process is managed.
        $process = null;
        foreach ($params as $value) {
            if (is_array($value) && !empty($value['process'])) {
                $process = $value['process'];
                break;
            }
        }

        if (empty($process)) {
            $this->messenger()->addWarning('No process submitted.'); // @translate
            return $view;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $dispatcher = $this->jobDispatcher();

        $defaultParams = [
            'process' => $process,
        ];

        switch ($process) {
            case 'files_excess_check':
            case 'files_excess_move':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileExcess::class, $defaultParams);
                break;
            case 'files_missing_check_full':
                $params['files_checkfix']['files_missing']['include_derivatives'] = true;
                // no break
            case 'files_missing_check':
            case 'files_missing_fix':
            case 'files_missing_fix_db':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMissing::class, $defaultParams + $params['files_checkfix']['files_missing']);
                break;
            case 'files_derivative':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDerivative::class, $defaultParams + $params['files_checkfix']['files_derivative']);
                break;
            case 'files_media_no_original':
            case 'files_media_no_original_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaNoOriginal::class, $defaultParams);
                break;
            case 'dirs_excess':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DirExcess::class, $defaultParams);
                break;
            case 'files_size_check':
            case 'files_size_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileSize::class, $defaultParams);
                break;
            case 'files_hash_check':
            case 'files_hash_check':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileHash::class, $defaultParams);
                break;
            case 'files_media_type_check':
            case 'files_media_type_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaType::class, $defaultParams);
                break;
            case 'files_dimension_check':
            case 'files_dimension_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDimension::class, $defaultParams);
                break;
            case 'media_position_check':
            case 'media_position_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\MediaPosition::class, $defaultParams);
                break;
            case 'db_resource_invalid_check':
            case 'db_resource_invalid_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceInvalid::class, $defaultParams);
                break;
            case 'db_loop_save':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLoopResources::class, $defaultParams + $params['resource_values']['db_loop_save']);
                break;
            case 'db_resource_incomplete_check':
            case 'db_resource_incomplete_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceIncomplete::class, $defaultParams);
                break;
            case 'db_item_no_value':
            case 'db_item_no_value_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbItemNoValue::class, $defaultParams);
                break;
            case 'db_utf8_encode_check':
            case 'db_utf8_encode_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbUtf8Encode::class, $defaultParams + $params['resource_values']['db_utf8_encode']);
                break;
            case 'db_resource_title_check':
            case 'db_resource_title_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceTitle::class, $defaultParams);
                break;
            case 'db_item_primary_media_check':
            case 'db_item_primary_media_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbItemPrimaryMedia::class, $defaultParams);
            case 'db_value_annotation_template_check':
            case 'db_value_annotation_template_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbValueAnnotationTemplate::class, $defaultParams);
                break;
            case 'db_content_lock_check':
            case 'db_content_lock_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbContentLock::class, $defaultParams + $params['database']['db_content_lock']);
                break;
            case 'db_job_check':
            case 'db_job_fix':
            case 'db_job_fix_all':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbJob::class, $defaultParams);
                break;
            case 'db_session_check':
            case 'db_session_clean':
            case 'db_session_recreate':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, $defaultParams + $params['database']['db_session']);
                break;
            case 'db_log_check':
            case 'db_log_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLog::class, $defaultParams + $params['database']['db_log']);
                break;
            case 'db_customvocab_missing_itemsets_check':
            case 'db_customvocab_missing_itemsets_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbCustomVocabMissingItemSets::class, $defaultParams + $params['database']['db_customvocab_missing_itemsets']);
                break;
            case 'backup_install':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\Backup::class, $defaultParams + $params['backup']['backup_install']);
                break;
            case 'theme_templates_check':
            case 'theme_templates_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\ThemeTemplate::class, $defaultParams + $params['themes']['theme_templates'] + $params['themes']['theme_templates_warn']);
                break;
            case 'cache_check':
            case 'cache_fix':
                // TODO Manage instant job via synchronous jobs.
                // This is not a job, because it is instant.
                $this->checkCache($params['cache']['cache_clear'], $process === 'cache_fix');
                $job = null;
                break;
            case 'db_fulltext_index':
                $job = $dispatcher->dispatch(\Omeka\Job\IndexFulltextSearch::class);
                break;
            default:
                $eventManager = $this->getEventManager();
                $args = $eventManager->prepareArgs([
                    'process' => $process,
                    'params' => $params,
                    'job' => null,
                    'args' => [],
                ]);
                $eventManager->trigger('easyadmin.job', $this, $args);
                $jobClass = $args['job'];
                // TODO Remove this fix when it will be pushed for all known modules.
                if (!$jobClass) {
                    /*
                    [
                        'Compilatio' => '3.4.2',
                        'Dante' => '3.4.7',
                        'Guest' => '3.4.29',
                        'IiifServer' => '3.6.23',
                        'Reference' => '3.4.51',
                        'Statistics' => '3.4.9',
                        'Thesaurus' => '3.4.19',
                    ];
                    */
                    $eventManager->trigger('easyadmin.job', null, $args);
                    $jobClass = $args['job'];
                }
                if ($jobClass) {
                    $job = $dispatcher->dispatch($jobClass, $args['args']);
                } else {
                    $job = null;
                    $this->messenger()->addError(new PsrMessage(
                        'Unknown process "{process}"', // @translate
                        ['process' => $process]
                    ));
                }
                break;
        }

        if ($job) {
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                'Processing checks in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'link_job' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                    ),
                    'job_id' => $job->getId(),
                    'link_end' => '</a>',
                    'link_log' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars(class_exists(\Log\Module::class)
                            ? $urlPlugin->fromRoute('admin/log/default', [], ['query' => ['job_id' => $job->getId()]])
                            : $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log']))
                    ),
                ]
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
        }

        // Reset the form after a submission.
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        return $view
            ->setVariable('form', $form);
    }

    protected function checkCache(array $options, bool $fix): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger  */
        $messenger = $this->messenger();
        if (empty($options['type'])) {
            $messenger->addWarning('No type of cache selected.'); // @translate
            return;
        }

        if (in_array('code', $options['type'])) {
            $hasCache = function_exists('opcache_reset');
            if ($hasCache) {
                $result = @opcache_get_status(false);
                if (!$result) {
                    $messenger->addWarning('An issue occurred when checking status of "opcache" or the status is not enabled.'); // @translate
                } else {
                    /*
                    $resultConfig = @opcache_get_configuration();
                    $msg = new PsrMessage(nl2br(htmlspecialchars(json_encode($resultConfig, 448), ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                    */
                    $msg = new PsrMessage(nl2br(htmlspecialchars(json_encode($result, 448), ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                }
                if ($fix) {
                    $result = opcache_reset();
                    if ($result) {
                        $messenger->addSuccess('The cache "opcache" was reset.'); // @translate
                    } else {
                        $messenger->addWarning('The cache "opcache" is disabled.'); // @translate
                    }
                }
            } else {
                $messenger->addWarning('The php extension "opcache" is not available.'); // @translate
            }
        }

        if (in_array('data', $options['type'])) {
            $hasCache = function_exists('apcu_clear_cache');
            if ($hasCache) {
                $result = @apcu_cache_info(true);
                if (!$result) {
                    $messenger->addWarning('An issue occurred when checking status of "apcu" or the status is disabled.'); // @translate
                } else {
                    $msg = new PsrMessage(nl2br(htmlspecialchars(json_encode($result, 448), ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                }
                if ($fix) {
                    apcu_clear_cache();
                    $messenger->addSuccess('The cache "apcu" was reset.'); // @translate
                }
            } else {
                $messenger->addWarning('The php extension "apcu" is not available.'); // @translate
            }
        }

        if (in_array('path', $options['type'])
            && $fix
        ) {
            @clearstatcache(true);
            $messenger->addSuccess('The cache of real paths was reset.'); // @translate
        }
    }
}
