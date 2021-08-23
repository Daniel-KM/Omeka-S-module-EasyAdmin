<?php declare(strict_types=1);

namespace EasyInstallTest\Controller;

use OmekaTestHelper\Controller\OmekaControllerTestCase;

abstract class EasyInstallControllerTestCase extends OmekaControllerTestCase
{
    public function setUp(): void
    {
        $this->loginAsAdmin();
    }
}
