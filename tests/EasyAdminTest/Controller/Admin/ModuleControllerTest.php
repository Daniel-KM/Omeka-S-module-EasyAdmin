<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Entity\Module as ModuleEntity;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the action that sets the version of the database to the one of the
 * files.
 */
class ModuleControllerTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    const ROUTE = '/admin/easy-admin/module/downgrade-version';

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->logout();
        parent::tearDown();
    }

    public function testDowngradeVersionRedirectsOnGet(): void
    {
        $this->dispatch(self::ROUTE . '?id=Common');
        $this->assertResponseStatusCode(302);
        $this->assertStringContainsString(
            '/admin/easy-admin/module',
            $this->getResponse()->getHeaders()->get('Location')->getFieldValue()
        );
    }

    public function testDowngradeVersionKeepsVersionOfUnknownModule(): void
    {
        $this->dispatch(self::ROUTE . '?id=NotAModule', 'POST', ['id' => 'NotAModule']);
        $this->assertResponseStatusCode(302);
        $this->assertStringContainsString('not found', $this->lastMessage());
    }

    /**
     * The version in the database is not changed when it is not greater than
     * the version of the files: there is nothing to fix.
     */
    public function testDowngradeVersionSkipsWhenVersionsAreEqual(): void
    {
        $entity = $this->moduleEntity('Common');
        $before = $entity->getVersion();

        $this->dispatch(self::ROUTE . '?id=Common', 'POST', ['id' => 'Common']);

        $this->assertResponseStatusCode(302);
        $this->assertStringContainsString('is already on version', $this->lastMessage());
        $this->assertSame($before, $this->moduleEntity('Common')->getVersion());
    }

    /**
     * The version of the files replaces the greater version of the database.
     */
    public function testDowngradeVersionSetsVersionOfFiles(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $iniVersion = $services->get('Omeka\ModuleManager')
            ->getModule('Common')->getIni('version');

        $entityManager = $this->getApplication()->getServiceManager()
            ->get('Omeka\EntityManager');
        $entity = $this->moduleEntity('Common');
        $before = $entity->getVersion();
        $entity->setVersion('9999.9.9');
        $entityManager->flush();

        try {
            $this->dispatch(self::ROUTE . '?id=Common', 'POST', ['id' => 'Common']);

            $this->assertResponseStatusCode(302);
            $entityManager->clear(ModuleEntity::class);
            $this->assertSame($iniVersion, $this->moduleEntity('Common')->getVersion());
        } finally {
            $entityManager->clear(ModuleEntity::class);
            $entity = $this->moduleEntity('Common');
            $entity->setVersion($before);
            $entityManager->flush();
        }
    }

    public function testBrowseWarnsWhenTheLastJobDidNotStart(): void
    {
        $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
        $job = new \Omeka\Entity\Job();
        $job->setClass(\EasyAdmin\Job\ManageAddons::class);
        $job->setArgs(['operation' => 'upgrade', 'type' => 'module', 'addons' => ['Common']]);
        $job->setStatus(\Omeka\Entity\Job::STATUS_STARTING);
        $entityManager->persist($job);
        $entityManager->flush();
        $jobId = $job->getId();
        // The date is set on persist, so it is updated directly.
        $entityManager->getConnection()->executeStatement(
            'UPDATE job SET started = ? WHERE id = ?',
            [(new \DateTime('-2 minutes'))->format('Y-m-d H:i:s'), $jobId]
        );
        $entityManager->clear();

        try {
            $this->dispatch('/admin/easy-admin/module');
            $this->assertResponseStatusCode(200);
            $this->assertStringContainsString('did not start', $this->getResponse()->getBody() . $this->lastMessage());
        } finally {
            $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
            $job = $entityManager->find(\Omeka\Entity\Job::class, $jobId);
            if ($job) {
                $entityManager->remove($job);
                $entityManager->flush();
            }
        }
    }

    public function testBrowseDoesNotWarnForARecentJob(): void
    {
        $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
        $job = new \Omeka\Entity\Job();
        $job->setClass(\EasyAdmin\Job\ManageAddons::class);
        $job->setArgs(['operation' => 'upgrade', 'type' => 'module', 'addons' => ['Common']]);
        $job->setStatus(\Omeka\Entity\Job::STATUS_STARTING);
        $job->setStarted(new \DateTime('now'));
        $entityManager->persist($job);
        $entityManager->flush();
        $jobId = $job->getId();

        try {
            $this->dispatch('/admin/easy-admin/module');
            $this->assertResponseStatusCode(200);
            $this->assertStringNotContainsString('did not start', $this->getResponse()->getBody() . $this->lastMessage());
        } finally {
            $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
            $job = $entityManager->find(\Omeka\Entity\Job::class, $jobId);
            if ($job) {
                $entityManager->remove($job);
                $entityManager->flush();
            }
        }
    }

    public function testBrowseWarnsForReadOnlySymbolicLinks(): void
    {
        $base = sys_get_temp_dir() . '/easyadmin_test_links_' . uniqid();
        $readOnly = $base . '/readonly/ZzReadOnlyLink';
        $writeable = $base . '/writeable/ZzWriteableLink';
        mkdir($readOnly, 0775, true);
        mkdir($writeable, 0775, true);
        chmod($readOnly, 0555);
        chmod(dirname($readOnly), 0555);
        $linkReadOnly = OMEKA_PATH . '/modules/ZzReadOnlyLink';
        $linkWriteable = OMEKA_PATH . '/modules/ZzWriteableLink';
        symlink($readOnly, $linkReadOnly);
        symlink($writeable, $linkWriteable);

        try {
            $links = $this->getApplicationServiceLocator()->get('ControllerPluginManager')
                ->get('easyAdminAddons')->readOnlyLinks('modules');
            $this->assertArrayHasKey('ZzReadOnlyLink', $links);
            $this->assertSame(realpath($readOnly), $links['ZzReadOnlyLink']);
            $this->assertArrayNotHasKey('ZzWriteableLink', $links);

            $this->dispatch('/admin/easy-admin/module');
            $this->assertResponseStatusCode(200);
            $body = $this->getResponse()->getBody();
            $this->assertStringContainsString('symbolic links to a directory that is not writeable', $body);
            $this->assertStringContainsString('ZzReadOnlyLink', $body);
        } finally {
            unlink($linkReadOnly);
            unlink($linkWriteable);
            chmod(dirname($readOnly), 0775);
            chmod($readOnly, 0775);
            exec('rm -rf ' . escapeshellarg($base));
        }
    }

    protected function moduleEntity(string $id): ModuleEntity
    {
        return $this->getApplication()->getServiceManager()
            ->get('Omeka\EntityManager')
            ->find(ModuleEntity::class, $id);
    }

    /**
     * Last message added to the messenger, that the redirect did not display.
     */
    protected function lastMessage(): string
    {
        $messages = [];
        $container = new \Laminas\Session\Container('OmekaMessenger');
        foreach ($container->messages ?? [] as $typeMessages) {
            foreach ($typeMessages as $message) {
                $messages[] = (string) $message;
            }
        }
        return implode("\n", $messages);
    }
}
