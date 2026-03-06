<?php

use PHPUnit\Framework\TestCase;

class Test_Plugin_Init extends TestCase {

	public function test_plugin_class_exists() {

		$this->assertTrue(
			class_exists( 'HSBC_Menu' )
		);
	}
}
