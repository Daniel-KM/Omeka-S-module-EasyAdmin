<?php declare(strict_types=1);

namespace EasyAdminTest\Controller;

use OmekaTestHelper\Controller\OmekaControllerTestCase;

abstract class EasyAdminControllerTestCase extends OmekaControllerTestCase
{
    public function setUp(): void
    {
        $this->loginAsAdmin();
    }
}
