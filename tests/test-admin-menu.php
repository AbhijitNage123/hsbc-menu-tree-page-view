<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class Test_Admin_Menu extends TestCase {

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_admin_menu_hook_registered()
    {

        Monkey\Functions\expect('add_action')
            ->once()
            ->with('admin_menu', 'hsbc_register_menu');

        hsbc_register_menu();

    }

}