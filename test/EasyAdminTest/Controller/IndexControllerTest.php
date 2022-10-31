<?php declare(strict_types=1);

namespace EasyAdminTest\Controller;

class IndexControllerTest extends EasyAdminControllerTestCase
{
    public function testIndexActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/easy-admin/addons');
        $this->assertResponseStatusCode(200);
    }

    public function testIndexActionCannotBeAccessedInPublic(): void
    {
        $this->dispatch('/easy-admin/addons');
        $this->assertResponseStatusCode(404);
    }
}
