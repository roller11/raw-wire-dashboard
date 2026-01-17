<?php
/**
 * Settings Class Tests
 *
 * Test cases for the Raw_Wire_Settings class.
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/tests
 * @since      1.0.0
 */

/**
 * Test_Raw_Wire_Settings class
 *
 * @group settings
 */
class Test_Raw_Wire_Settings extends PHPUnit\Framework\TestCase {

	/**
	 * Test that Raw_Wire_Settings class exists
	 */
	public function test_settings_class_exists() {
		$this->assertTrue(
			class_exists( 'Raw_Wire_Settings' ),
			'Raw_Wire_Settings class should exist'
		);
	}

	/**
	 * Test token_placeholder method exists
	 */
	public function test_token_placeholder_method_exists() {
		$this->assertTrue(
			method_exists( 'Raw_Wire_Settings', 'token_placeholder' ),
			'Raw_Wire_Settings should have token_placeholder method'
		);
	}

	/**
	 * Test token_placeholder returns non-token-looking placeholder
	 */
	public function test_token_placeholder_returns_safe_value() {
		$placeholder = Raw_Wire_Settings::token_placeholder();
		
		// Should return a string
		$this->assertIsString( $placeholder, 'Placeholder should be a string' );
		
		// Should not be empty
		$this->assertNotEmpty( $placeholder, 'Placeholder should not be empty' );
		
		// Should not match GitHub token pattern (ghp_ followed by 36 alphanumeric chars)
		$this->assertThat(
			$placeholder,
			$this->logicalNot(
				$this->matchesRegularExpression( '/ghp_[a-zA-Z0-9]{36}/' )
			),
			'Placeholder should not look like a GitHub token'
		);
		
		// Should be the expected value
		$this->assertEquals( '***', $placeholder, 'Placeholder should be ***' );
	}

	/**
	 * Test render_token_field method exists
	 */
	public function test_render_token_field_method_exists() {
		$this->assertTrue(
			method_exists( 'Raw_Wire_Settings', 'render_token_field' ),
			'Raw_Wire_Settings should have render_token_field method'
		);
	}
}
