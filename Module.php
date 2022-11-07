<?php declare(strict_types=1);

/*
 * Copyright 2017-2022 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace EasyAdmin;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;

/**
 * Easy Admin
 *
 * @copyright Daniel Berthereau, 2017-2022
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Log';

    protected function preInstall(): void
    {
        $this->installDir();
    }

    protected function installDir(): void
    {
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // Automatic upgrade from module Bulk Check.
        $result = null;
        $bulkCheckPath = $basePath . '/bulk_check';
        if (file_exists($bulkCheckPath) && is_dir($bulkCheckPath)) {
            $result = rename($bulkCheckPath, $basePath . '/check');
            if (!$result) {
                $message = new \Omeka\Stdlib\Message(
                    'Upgrading module BulkCheck: Unable to rename directory "files/bulk_check" into "files/check". Trying to create it.' // @translate
                );
                $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
                $messenger->addWarning($message);
            }
        }

        if (!$result && !$this->checkDestinationDir($basePath . '/check')) {
            $message = new \Omeka\Stdlib\Message(
                'The directory "%s" is not writeable.', // @translate
                $basePath
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('BulkCheck');
        if (!$module || in_array($module->getState(), [
            \Omeka\Module\Manager::STATE_NOT_FOUND,
            \Omeka\Module\Manager::STATE_NOT_INSTALLED,
        ])) {
            return;
        }

        // The module BulkCheck doesn't have any param, so it is uninstalled
        // directly.
        $sql = 'DELETE FROM `module` WHERE `id` = "BulkCheck";';
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $connection->executeStatement($sql);
        $message = new \Omeka\Stdlib\Message(
            'The module "%s" was upgraded by module "%s" and uninstalled.', // @translate
            'Bulk Check', 'Easy Admin'
        );
        $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
        $messenger->addWarning($message);
    }

    protected function preUninstall(): void
    {
        if (!empty($_POST['remove-dir-check'])) {
            $config = $this->getServiceLocator()->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            $this->rmDir($basePath . '/check');
        }
    }

    public function warnUninstall(Event $event): void
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $serviceLocator = $this->getServiceLocator();
        $t = $serviceLocator->get('MvcTranslator');
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $html = '<p>';
        $html .= '<strong>';
        $html .= $t->translate('WARNING:'); // @translate
        $html .= '</strong>';
        $html .= '</p>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('All stored files from checks and fixes, if any, will be removed from folder "{folder}".'), // @translate
            $basePath . '/check'
        );
        $html .= '</p>';

        $html .= '<label><input name="remove-dir-check" type="checkbox" form="confirmform">';
        $html .= $t->translate('Remove directory "files/check"'); // @translate
        $html .= '</label>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );

        // Content lockiing in admin board.
        // It is useless in public board, because there is the moderation.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        // The check for content locking can be done via `api.hydrate.pre` or
        // `api.update.pre`, that is bypassable in code.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
    }

    public function contentLockingOnEdit(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Representation\AbstractEntityRepresentation $resource
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */
        $view = $event->getTarget();
        $resource = $view->resource;
        if (!$resource) {
            return;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('easyadmin_content_lock')) {
            return;
        }

        // This mapping is needed because the api name is not available in the
        // representation.
        $resourceNames = [
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            'o:Item' => 'items',
            'o:ItemSet' => 'item_sets',
            'o:Media' => 'media',
        ];

        $entityId = $resource->id();
        $entityName = $resourceNames[get_class($resource)] ?? $resourceNames[$resource->getJsonLdType()] ?? null;
        if (!$entityId || !$entityName) {
            return;
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(\EasyAdmin\Entity\ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);

        if (!$contentLock) {
            $contentLock = new \EasyAdmin\Entity\ContentLock($entityId, $entityName);
            $contentLock
                ->setUser($user)
                ->setCreated(new \DateTIme('now'));
            // Flush is needed because the event does not run it.
            $entityManager->persist($contentLock);
            $entityManager->flush();
            return;
        }

        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $contentLockUser = $contentLock->getUser();
        $isCurrentUser = $user->getId() === $contentLockUser->getId();

        if ($isCurrentUser) {
            // Refresh the content lock: this is a new edition or a
            // submitted one. So the previous lock should be removed and a
            // a new one created, but it's simpler to override first one.
            $contentLock->setCreated(new \DateTIme('now'));
            $entityManager->persist($contentLock);
            $entityManager->flush();
            $message = new \Log\Stdlib\PsrMessage(
                'You edit already this resource somewhere since {date}.', // @translate
                ['date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messenger->addWarning($message);
            return;
        }

        $controllerNames = [
            'items' => 'item',
            'item_sets' => 'item-set',
            'media' => 'media',
        ];

        // TODO Add rights to bypass.

        /** @var \Laminas\Http\PhpEnvironment\Request $request */
        $request = $services->get('Application')->getMvcEvent()->getRequest();

        $isPost = $request->isPost();

        $message = new \Log\Stdlib\PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $messenger->add($isPost ? \Omeka\Mvc\Controller\Plugin\Messenger::ERROR : \Omeka\Mvc\Controller\Plugin\Messenger::WARNING, $message);

        $message = new \Log\Stdlib\PsrMessage(
            'You can bypass this lock by checking this box: {checkbox}', // @translate
            ['checkbox' => '<input type="checkbox" name="bypass_content_lock" value="1" id="bypass_content_lock" form="edit-' . $controllerNames[$entityName] . '"/>']
        );
        $message->setEscapeHtml(false);
        $messenger->add($isPost ? \Omeka\Mvc\Controller\Plugin\Messenger::ERROR : \Omeka\Mvc\Controller\Plugin\Messenger::WARNING, $message);
    }

    public function contentLockingOnSave(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('easyadmin_content_lock')) {
            return;
        }

        $request = $event->getParam('request');

        $entityId = $request->getId();
        $entityName = $request->getResource();
        if (!$entityId || !$entityName) {
            return;
        }

        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(\EasyAdmin\Entity\ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
        if (!$contentLock) {
            return;
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $contentLockUser = $contentLock->getUser();
        if ($user->getId() === $contentLockUser->getId()) {
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        $i18n = $services->get('ViewHelperManager')->get('i18n');

        // When a lock is bypassed, keep it for the original user.
        if ($request->getValue('bypass_content_lock')) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new \Log\Stdlib\PsrMessage(
                'The lock in place since {date} has been bypassed, but the user {user_name} can override it on save.', // @translate
                [
                    'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
                    'user_name' => $contentLockUser->getName(),
                ]
            );
            $messenger->addWarning($message);
            return;
        }

        // Keep the message for backend api process.
        $message = new \Log\Stdlib\PsrMessage(
            'User {user} (#{userid}) tried to save {resource_name} #{resource_id} edited by the user {user_name} (#{user_id}) since {date}.', // @translate
            [
                'user' => $user->getName(),
                'userid' => $user->getId(),
                'resource_name' => $entityName,
                'resource_id' => $entityId,
                'user_name' => $contentLockUser->getName(),
                'user_id' => $contentLockUser->getId(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $services->get('Omeka\Logger')->err($message);

        $message = new \Log\Stdlib\PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );

        // Throw exception for frontend and backend.
        throw new \Omeka\Api\Exception\ValidationException((string) $message);
    }

    /**
     * Remove content lock on resouce deletion.
     *
     * The primary key is not a join column, so old deleted record can remain.
     */
    public function contentLockingOnDelete(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');

        $entityId = $request->getId();
        $entityName = $request->getResource();
        if (!$entityId || !$entityName) {
            return;
        }

        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(\EasyAdmin\Entity\ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
        if (!$contentLock) {
            return;
        }

        $entityManager->remove($contentLock);
        // Will be flushed auomatically in post.
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir($dirPath)
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'The directory "{path}" is not writeable.', // @translate
                    ['path' => $dirPath]
                );
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                'The directory "{path}" is not writeable: {error}.', // @translate
                ['path' => $dirPath, 'error' => error_get_last()['message']]
            );
            return null;
        }
        return $dirPath;
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath Absolute path.
     */
    private function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        if (strpos($dirPath, '/..') !== false || substr($dirPath, 0, 1) !== '/') {
            return false;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }
}
