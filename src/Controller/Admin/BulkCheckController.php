<?php
namespace BulkCheck\Controller\Admin;

use Log\Stdlib\PsrMessage;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class BulkCheckController extends AbstractActionController
{
    public function indexAction()
    {
        $form = $this->getForm(\BulkCheck\Form\BulkCheckForm::class);
        $view = new ViewModel;
        $view
            ->setVariable('form', $form);

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

        if (empty($params['process'])) {
            $this->messenger()->addWarning('No process submitted.'); // @translate
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(\BulkCheck\Job\Check::class, $params);
        $urlHelper = $this->url();
        $message = new PsrMessage(
            'Checking database and files in background (job {link_open_job}#{jobId}{link_close}, {link_open_log}logs{link_close}).', // @translate
            [
                'link_open_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'jobId' => $job->getId(),
                'link_close' => '</a>',
                'link_open_log' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper->fromRoute('admin/log/default', [], ['query' => ['job_id' => $job->getId()]]))
                ),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        // Reset the form after a submission.
        $form = $this->getForm(\BulkCheck\Form\BulkCheckForm::class);
        return $view
            ->setVariable('form', $form);
    }
}
