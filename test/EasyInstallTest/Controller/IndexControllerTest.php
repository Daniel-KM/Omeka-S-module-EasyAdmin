<?php

namespace EasyInstallTest\Controller;

class PresentationControllerTest extends EasyInstallControllerTestCase
{
    public function testIndexActionCanBeAccessed()
    {
        $this->dispatch('/admin/easy-install');
        $this->assertResponseStatusCode(200);
    }

    public function testIndexActionCannotBeAccessedInPublic()
    {
        $this->dispatch('/easy-install');
        $this->assertResponseStatusCode(404);
    }
}
