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
            if (count($unknowns)) {
                $messenger->addWarning(new PsrMessage(
                    'The following modules of the selection are unknown: {addons}.', // @translate
                    ['addons' => implode(', ', $unknowns)]
                ));
            }
            if (count($existings)) {
                $messenger->addNotice(new PsrMessage(
                    'The following modules are already installed: {addons}.', // @translate
                    ['addons' => implode(', ', $existings)]
                ));
            }
            if (count($errors)) {
                $messenger->addError(new PsrMessage(
                    'The following modules cannot be installed: {addons}.', // @translate
                    ['addons' => implode(', ', $errors)]
                ));
            }
            if (count($installeds)) {
                $messenger->addSuccess(new PsrMessage(
                    'The following modules have been installed: {addons}.', // @translate
                    ['addons' => implode(', ', $installeds)]
                ));
            }
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
