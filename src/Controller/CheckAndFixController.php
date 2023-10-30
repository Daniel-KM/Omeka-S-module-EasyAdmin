<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Model\ViewModel;
use Log\Stdlib\PsrMessage;

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
            case 'db_resource_incomplete_check':
            case 'db_resource_incomplete_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceIncomplete::class, $defaultParams);
                break;
            case 'item_no_value':
            case 'item_no_value_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\ItemNoValue::class, $defaultParams);
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
            case 'backup_install':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\Backup::class, $defaultParams + $params['backup']['backup_install']);
                break;
            case 'cache_check':
            case 'cache_fix':
                // This is not a job, because it is instant.
                $this->checkCache($params['cache']['cache_clear'], $process === 'cache_fix');
                $job = null;
                break;
            case 'db_fulltext_index':
                $job = $dispatcher->dispatch(\Omeka\Job\IndexFulltextSearch::class);
                break;
            case 'db_statistics_index':
                $job = $dispatcher->dispatch(\Statistics\Job\AggregateHits::class);
                break;
            case 'db_thesaurus_index':
                $job = $dispatcher->dispatch(\Thesaurus\Job\Indexing::class);
                break;
            default:
                $eventManager = $this->getEventManager();
                $args = $eventManager->prepareArgs([
                    'process' => $process,
                    'params' => $params,
                    'job' => null,
                    'args' => [],
                ]);
                $eventManager->triggerEvent(new MvcEvent('easyadmin.job', null, $args));
                $jobClass = $args['job'];
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
                'Processing checks in background (job {link_job}#{job_id}{ae}, {link_log}logs{ae}).', // @translate
                [
                    'link_job' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                    ),
                    'job_id' => $job->getId(),
                    'ae' => '</a>',
                    'link_log' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars($urlPlugin->fromRoute('admin/log/default', [], ['query' => ['job_id' => $job->getId()]]))
                    )
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

        if (in_array('path', $options['type'])) {
            $result = @clearstatcache(true);
            $messenger->addSuccess('The cache of real paths was reset.'); // @translate
        }
    }
}
