<?php
/**
 * Class BootstrapTest
 *
 * @package HSBC_Menu_Tree_Page_View
 */

/**
 * Test cases for the main plugin bootstrap file.
 */
class BootstrapTest extends WP_UnitTestCase {

	/**
	 * The main plugin file.
	 *
	 * @var string
	 */
	protected $plugin_file;

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plugin_file = HSBC_MTPV_PATH . 'hsbc-menu-tree-page-view.php';
	}

	/**
	 * Test if plugin constants are defined correctly.
	 */
	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'HSBC_MTPV_VERSION' ), 'HSBC_MTPV_VERSION should be defined.' );
		$this->assertSame( '1.0.0', HSBC_MTPV_VERSION );

		$this->assertTrue( defined( 'HSBC_MTPV_PATH' ), 'HSBC_MTPV_PATH should be defined.' );
		$this->assertTrue( defined( 'HSBC_MTPV_URL' ), 'HSBC_MTPV_URL should be defined.' );
	}

	/**
	 * Test if the main instantiation function is hooked to 'plugins_loaded'.
	 */
	public function test_instantiation_is_hooked() {
		// This test assumes the refactoring to a named function has been applied.
		$this->assertEquals( 10, has_action( 'plugins_loaded', 'hsbc_mtpv_instantiate_classes' ) );
	}
}
