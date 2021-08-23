<?php declare(strict_types=1);

namespace EasyInstallTest\Controller;

class IndexControllerTest extends EasyInstallControllerTestCase
{
    public function testIndexActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/easy-install');
        $this->assertResponseStatusCode(200);
    }

    public function testIndexActionCannotBeAccessedInPublic(): void
    {
        $this->dispatch('/easy-install');
        $this->assertResponseStatusCode(404);
    }
}
