<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

use Omeka\Stdlib\Message;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\MvcEvent;
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
            case 'files_dimension_check':
            case 'files_dimension_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDimension::class, $defaultParams);
                break;
            case 'files_media_type_check':
            case 'files_media_type_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaType::class, $defaultParams);
                break;
            case 'media_position_check':
            case 'media_position_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\MediaPosition::class, $defaultParams);
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
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, $defaultParams + $params['database']['db_session']);
                break;
            case 'db_log_check':
            case 'db_log_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLog::class, $defaultParams + $params['database']['db_log']);
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
                 if (!$jobClass) {
                    $this->messenger()->addError(new Message(
                        'Unknown process "%s"', // @translate
                        $process
                    ));
                    return $view;
                }
                $job = $dispatcher->dispatch($jobClass, $args['args']);
                break;
        }

        $urlHelper = $this->url();
        // TODO Don't use PsrMessage for now to fix issues with Doctrine and inexisting file to remove.
        $message = new Message(
            'Processing checks in background (job %1$s#%2$d%3$s, %4$slogs%3$s).', // @translate
            sprintf(
                '<a href="%s">',
                htmlspecialchars($urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf(
                '<a href="%s">',
                // Check if module Log is enabled (avoid issue when disabled).
                htmlspecialchars(class_exists(\Log\Stdlib\PsrMessage::class)
                    ? $urlHelper->fromRoute('admin/log/default', [], ['query' => ['job_id' => $job->getId()]])
                    : $urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log'])
            ))
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        // Reset the form after a submission.
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        return $view
            ->setVariable('form', $form);
    }
}
