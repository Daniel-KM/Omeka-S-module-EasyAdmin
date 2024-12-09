<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Form\AddonsForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class AddonsController extends AbstractActionController
{
    public function indexAction()
    {
        /**
         * @var \EasyAdmin\Form\AddonsForm $form
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */

        $form = $this->getForm(AddonsForm::class);
        $messenger = $this->messenger();

        $view = new ViewModel([
            'form' => $form,
        ]);

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        if ($addons->isEmpty()) {
            $messenger->addWarning(
                'No addon to list: check your connection.' // @translate
            );
            return $view;
        }

        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $view;
        }

        $form->setData($this->params()->fromPost());

        if (!$form->isValid()) {
            $messenger->addError(
                'There was an error on the form. Please try again.' // @translate
            );
            return $view;
        }

        $data = $form->getData();

        if (!empty($data['selection'])) {
            $selections = $addons->getSelections();
            $selectionAddons = $selections[$data['selection']] ?? [];
            // For big selections (about 10 seconds to install an addon), a
            // background job avoids a time out.
            $strategy = count($selectionAddons) > 3
                ? null
                : $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get(\Omeka\Job\DispatchStrategy\Synchronous::class);
            /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
            $dispatcher = $this->jobDispatcher();
            $args = [
                'selection' => $data['selection'],
            ];
            $job = $dispatcher->dispatch(\EasyAdmin\Job\ManageAddons::class, $args, $strategy);
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                'Processing install of selection "{selection}" in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'selection' => $data['selection'],
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
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        foreach ($addons->types() as $type) {
            $url = $data[$type] ?? null;
            if ($url) {
                $addon = $addons->dataFromUrl($url, $type);
                if ($addons->dirExists($addon)) {
                    // Hack to get a clean message.
                    $type = str_replace('omeka', '', $type);
                    $messenger->addError(new PsrMessage(
                        'The {type} "{name}" is already downloaded.', // @translate
                        ['type' => $type, 'name' => $addon['name']]
                    ));
                    return $this->redirect()->toRoute(null, ['action' => 'index'], true);
                }
                $addons->installAddon($addon);
                return $this->redirect()->toRoute(null, ['action' => 'index'], true);
            }
        }

        $messenger->addError(
            'Nothing processed. Please try again.' // @translate
        );
        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }
}
