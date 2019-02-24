<?php
namespace BulkCheck;

require_once dirname(__DIR__) . '/Generic/AbstractModule.php';

use BulkCheck\Form\ConfigForm;
use Generic\AbstractModule;
use Log\Stdlib\PsrMessage;
use Zend\Mvc\Controller\AbstractController;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Log';

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $params = $controller->getRequest()->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $params = $form->getData();

        if (empty($params['process']) || $params['process'] !== $controller->translate('Process')) {
            return;
        }

        unset($params['csrf']);
        unset($params['process']);

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\BulkCheck\Job\Check::class, $params);
        $message = new PsrMessage(
            'Checking data and files in background ({link_open}job #{jobId}{link_close})', // @translate
            [
                'link_open' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($controller->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'jobId' => $job->getId(),
                'link_close' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $controller->messenger()->addSuccess($message);
    }
}
