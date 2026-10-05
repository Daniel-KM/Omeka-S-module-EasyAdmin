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
