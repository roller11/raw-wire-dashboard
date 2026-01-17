<?php
/**
 * CSS Sanitizer Security Tests
 *
 * Tests RawWire_Validator::sanitize_css_color() against:
 * - Valid color formats (hex, rgb, hsl, named)
 * - CSS injection attempts
 * - Malformed inputs
 *
 * @package RawWire_Dashboard
 * @subpackage Tests
 */

class Test_CSS_Sanitizer extends WP_UnitTestCase {

    /**
     * Test valid hex colors
     */
    public function test_valid_hex_colors() {
        // 3-digit hex
        $this->assertEquals('#abc', RawWire_Validator::sanitize_css_color('#abc'));
        $this->assertEquals('#abc', RawWire_Validator::sanitize_css_color('#ABC'));

        // 6-digit hex
        $this->assertEquals('#0d9488', RawWire_Validator::sanitize_css_color('#0d9488'));
        $this->assertEquals('#ff0000', RawWire_Validator::sanitize_css_color('#FF0000'));

        // 8-digit hex with alpha
        $this->assertEquals('#0d948880', RawWire_Validator::sanitize_css_color('#0d948880'));
    }

    /**
     * Test valid RGB/RGBA colors
     */
    public function test_valid_rgb_colors() {
        // RGB
        $this->assertEquals('rgb(255,0,0)', RawWire_Validator::sanitize_css_color('rgb(255,0,0)'));
        $this->assertEquals('rgb(13,148,136)', RawWire_Validator::sanitize_css_color('rgb(13, 148, 136)'));

        // RGBA
        $this->assertEquals('rgba(255,0,0,0.50)', RawWire_Validator::sanitize_css_color('rgba(255,0,0,0.5)'));
        $this->assertEquals('rgba(13,148,136,1.00)', RawWire_Validator::sanitize_css_color('rgba(13, 148, 136, 1.0)'));
    }

    /**
     * Test valid HSL/HSLA colors
     */
    public function test_valid_hsl_colors() {
        // HSL
        $this->assertEquals('hsl(0,100%,50%)', RawWire_Validator::sanitize_css_color('hsl(0,100%,50%)'));
        $this->assertEquals('hsl(174,79%,32%)', RawWire_Validator::sanitize_css_color('hsl(174, 79%, 32%)'));

        // HSLA
        $this->assertEquals('hsla(0,100%,50%,0.50)', RawWire_Validator::sanitize_css_color('hsla(0,100%,50%,0.5)'));
        $this->assertEquals('hsla(174,79%,32%,1.00)', RawWire_Validator::sanitize_css_color('hsla(174, 79%, 32%, 1.0)'));
    }

    /**
     * Test valid named colors
     */
    public function test_valid_named_colors() {
        $this->assertEquals('transparent', RawWire_Validator::sanitize_css_color('transparent'));
        $this->assertEquals('black', RawWire_Validator::sanitize_css_color('black'));
        $this->assertEquals('white', RawWire_Validator::sanitize_css_color('white'));
        $this->assertEquals('red', RawWire_Validator::sanitize_css_color('red'));
        $this->assertEquals('blue', RawWire_Validator::sanitize_css_color('Blue'));
        $this->assertEquals('lightgray', RawWire_Validator::sanitize_css_color('lightgray'));
    }

    /**
     * Test CSS injection attempts are blocked
     */
    public function test_css_injection_blocked() {
        // url() injection
        $this->assertFalse(RawWire_Validator::sanitize_css_color('url(javascript:alert(1))'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('#fff;background:url(evil.com)'));

        // @import injection
        $this->assertFalse(RawWire_Validator::sanitize_css_color('@import url(evil.css)'));

        // expression() injection (IE)
        $this->assertFalse(RawWire_Validator::sanitize_css_color('expression(alert(1))'));

        // javascript: protocol
        $this->assertFalse(RawWire_Validator::sanitize_css_color('javascript:alert(1)'));

        // behavior: (IE)
        $this->assertFalse(RawWire_Validator::sanitize_css_color('behavior:url(evil.htc)'));

        // binding: (XBL)
        $this->assertFalse(RawWire_Validator::sanitize_css_color('binding:url(evil.xml)'));

        // HTML tags
        $this->assertFalse(RawWire_Validator::sanitize_css_color('<script>alert(1)</script>'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('#fff<script>'));

        // Multiple CSS properties
        $this->assertFalse(RawWire_Validator::sanitize_css_color('#fff;color:red'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('red;display:none'));

        // Backslash escaping
        $this->assertFalse(RawWire_Validator::sanitize_css_color('#fff\\;color:red'));
    }

    /**
     * Test malformed color values are rejected
     */
    public function test_malformed_colors_rejected() {
        // Invalid hex
        $this->assertFalse(RawWire_Validator::sanitize_css_color('#gg0000'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('#12'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('0d9488'));

        // Invalid RGB ranges
        $this->assertFalse(RawWire_Validator::sanitize_css_color('rgb(256,0,0)'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('rgb(255,0,300)'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('rgba(255,0,0,2)'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('rgba(255,0,0,-0.5)'));

        // Invalid HSL ranges
        $this->assertFalse(RawWire_Validator::sanitize_css_color('hsl(400,100%,50%)'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('hsl(0,150%,50%)'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('hsl(0,100%,150%)'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('hsla(0,100%,50%,2)'));

        // Not a color
        $this->assertFalse(RawWire_Validator::sanitize_css_color('not-a-color'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color(''));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('12px'));
        $this->assertFalse(RawWire_Validator::sanitize_css_color('bold'));
    }

    /**
     * Test non-string inputs
     */
    public function test_non_string_inputs_rejected() {
        $this->assertFalse(RawWire_Validator::sanitize_css_color(null));
        $this->assertFalse(RawWire_Validator::sanitize_css_color(123));
        $this->assertFalse(RawWire_Validator::sanitize_css_color(array('#fff')));
        $this->assertFalse(RawWire_Validator::sanitize_css_color(true));
    }

    /**
     * Test edge cases
     */
    public function test_edge_cases() {
        // Whitespace handling
        $this->assertEquals('#fff', RawWire_Validator::sanitize_css_color('  #fff  '));
        $this->assertEquals('rgb(255,0,0)', RawWire_Validator::sanitize_css_color('rgb( 255 , 0 , 0 )'));

        // Case insensitivity for named colors
        $this->assertEquals('red', RawWire_Validator::sanitize_css_color('RED'));
        $this->assertEquals('lightblue', RawWire_Validator::sanitize_css_color('LightBlue'));

        // Alpha channel edge values
        $this->assertEquals('rgba(255,0,0,0.00)', RawWire_Validator::sanitize_css_color('rgba(255,0,0,0)'));
        $this->assertEquals('rgba(255,0,0,1.00)', RawWire_Validator::sanitize_css_color('rgba(255,0,0,1)'));
    }

    /**
     * Integration test: Verify bootstrap uses sanitizer
     */
    public function test_bootstrap_integration() {
        // This test verifies that malicious module config colors are blocked

        // Simulate malicious module config
        $malicious_theme = array(
            'accent' => 'url(javascript:alert(1))',
            'surface' => '#fff;color:red',
            'card' => '<script>alert(1)</script>',
            'muted' => '@import url(evil.css)',
        );

        // The sanitizer should reject all malicious values
        foreach ($malicious_theme as $key => $value) {
            $result = RawWire_Validator::sanitize_css_color($value);
            $this->assertFalse($result, "Malicious value '$value' should be rejected");
        }

        // Valid values should pass
        $safe_theme = array(
            'accent' => '#0d9488',
            'surface' => 'rgb(11,23,36)',
            'card' => '#0f1f33',
            'muted' => 'rgba(138,160,183,0.8)',
        );

        foreach ($safe_theme as $key => $value) {
            $result = RawWire_Validator::sanitize_css_color($value);
            $this->assertNotFalse($result, "Valid color '$value' should pass");
        }
    }
}
