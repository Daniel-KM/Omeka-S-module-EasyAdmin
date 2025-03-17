<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CronController extends AbstractActionController
{
    public function indexAction()
    {
        /** @var \EasyAdmin\Form\CronForm $form */
        $form = $this->getForm(\EasyAdmin\Form\CronForm::class);

        $cronTasks = $this->settings()->get('easyadmin_cron_tasks') ?: [];

        $form->init();
        $form->setData([
            'easyadmin_cron_tasks' => $cronTasks,
        ]);

        $view = new ViewModel([
            'form' => $form,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        $tasks = $params['easyadmin_cron_tasks'] ?: [];
        $this->settings()->set('easyadmin_cron_tasks', $tasks);

        if ($tasks) {
            $msg = new PsrMessage(
                '{count} tasks defined to be run automatically once a day.',  // @translate
                ['count' => count($tasks)]
            );
        } else {
            $msg = new PsrMessage('No task defined to be run automatically once a day.');
        }
        $this->messenger()->addSuccess($msg);

        return $view;
    }
}
