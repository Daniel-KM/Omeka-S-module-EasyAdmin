<?php

namespace EasyInstallTest\Controller;

use EasyInstallTest\Controller\EasyInstallControllerTestCase;

class PresentationControllerTest extends EasyInstallControllerTestCase
{
    public function testIndexActionCanBeAccessed()
    {
        $this->dispatch('/admin/easyinstall');
        $this->assertResponseStatusCode(200);
    }

    public function testIndexActionCannotBeAccessedInPublic()
    {
        $this->dispatch('/easyinstall');
        $this->assertResponseStatusCode(404);
    }
}
