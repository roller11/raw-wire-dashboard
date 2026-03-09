<?php

/**
 * OpenClaw Browser Tools Adapter
 *
 * Provides browser automation tools for AI agents via the DreamPilot tool system.
 * Wraps `openclaw browser` CLI commands to enable Venice/DreamPilot models to:
 * - Navigate to URLs
 * - Take page snapshots (accessibility tree / DOM text)
 * - Click elements by selector
 * - Wait for page events (load, networkidle, etc.)
 *
 * These tools enable autonomous investigation workflows where AI can browse
 * websites, fill forms, and extract data from dynamic JavaScript-rendered pages.
 *
 * @package    RawWire_Dashboard
 * @subpackage Toolbox_Core
 * @since      1.0.28
 * @see        https://openclaw.ai
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Browser_OpenClaw
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Browser profile name for OpenClaw
     */
    const DEFAULT_PROFILE = 'openclaw';

    /**
     * Default timeout for browser operations (ms)
     */
    const DEFAULT_TIMEOUT = 30000;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor - registers hooks
     */
    private function __construct()
    {
        // Register browser tools via DreamPilot filter
        add_filter('dreampilot_builtin_tools', array($this, 'register_browser_tools'));

        // Handle browser tool execution
        add_filter('dreampilot_execute_builtin_tool', array($this, 'execute_browser_tool'), 10, 3);
    }

    /**
     * Initialize the browser tools (call early)
     */
    public static function init()
    {
        self::get_instance();
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Get browser profile from OpenClaw settings
     *
     * @return string
     */
    private function get_profile()
    {
        $settings = get_option('rawwire_openclaw_settings', array());
        return $settings['browser_profile'] ?? self::DEFAULT_PROFILE;
    }

    /**
     * Get default timeout from settings
     *
     * @return int Timeout in milliseconds
     */
    private function get_timeout()
    {
        $settings = get_option('rawwire_openclaw_settings', array());
        return intval($settings['browser_timeout'] ?? self::DEFAULT_TIMEOUT);
    }

    // -------------------------------------------------------------------------
    // Tool Registration
    // -------------------------------------------------------------------------

    /**
     * Register browser tools with DreamPilot
     *
     * @param array $tools Existing built-in tools
     * @return array Modified tools array
     */
    public function register_browser_tools($tools)
    {
        // Check if OpenClaw is enabled
        $settings = get_option('rawwire_openclaw_settings', array());
        if (empty($settings['enabled'])) {
            return $tools;
        }

        $browser_tools = array(
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_ping',
                    'description' => 'Check if the OpenClaw browser is running and available',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(),
                        'required'   => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_start',
                    'description' => 'Start the OpenClaw browser if not already running. Call this before other browser operations.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(),
                        'required'   => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_navigate',
                    'description' => 'Navigate the browser to a URL. This loads the page and waits for initial content.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'url' => array(
                                'type'        => 'string',
                                'description' => 'The URL to navigate to',
                            ),
                            'timeout' => array(
                                'type'        => 'integer',
                                'description' => 'Navigation timeout in milliseconds (default: 30000)',
                            ),
                        ),
                        'required' => array('url'),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_snapshot',
                    'description' => 'Take a snapshot of the current page. Returns the accessibility tree / text content that you can analyze.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'selector' => array(
                                'type'        => 'string',
                                'description' => 'Optional CSS selector to snapshot only a specific element',
                            ),
                        ),
                        'required' => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_click',
                    'description' => 'Click an element on the page by CSS selector or visible text',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'selector' => array(
                                'type'        => 'string',
                                'description' => 'CSS selector or text content to find and click',
                            ),
                        ),
                        'required' => array('selector'),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_fill',
                    'description' => 'Fill a form field with text. Use this for input fields, textareas, etc.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'selector' => array(
                                'type'        => 'string',
                                'description' => 'CSS selector for the input field',
                            ),
                            'value' => array(
                                'type'        => 'string',
                                'description' => 'Text to fill into the field',
                            ),
                        ),
                        'required' => array('selector', 'value'),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_wait',
                    'description' => 'Wait for a page event or selector to appear',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'type' => array(
                                'type'        => 'string',
                                'enum'        => array('load', 'networkidle', 'selector', 'time'),
                                'description' => 'What to wait for: load (initial), networkidle (no pending requests), selector (element appears), time (fixed delay)',
                            ),
                            'value' => array(
                                'type'        => 'string',
                                'description' => 'For selector: CSS selector. For time: milliseconds. Ignored for load/networkidle.',
                            ),
                            'timeout' => array(
                                'type'        => 'integer',
                                'description' => 'Maximum wait time in milliseconds',
                            ),
                        ),
                        'required' => array('type'),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'dreampilot_browser_scroll',
                    'description' => 'Scroll the page or an element',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'direction' => array(
                                'type'        => 'string',
                                'enum'        => array('up', 'down', 'top', 'bottom'),
                                'description' => 'Scroll direction or position',
                            ),
                            'amount' => array(
                                'type'        => 'integer',
                                'description' => 'Pixels to scroll (for up/down)',
                            ),
                        ),
                        'required' => array('direction'),
                    ),
                ),
            ),
        );

        return array_merge($tools, $browser_tools);
    }

    // -------------------------------------------------------------------------
    // Tool Execution
    // -------------------------------------------------------------------------

    /**
     * Execute a browser tool
     *
     * @param mixed  $result    Current result (null if not handled)
     * @param string $tool_name Tool name being executed
     * @param array  $args      Tool arguments
     * @return mixed|null Result array or null if not a browser tool
     */
    public function execute_browser_tool($result, $tool_name, $args)
    {
        // Only handle browser tools
        if (strpos($tool_name, 'dreampilot_browser_') !== 0) {
            return $result;
        }

        // Extract method from tool name
        $method = str_replace('dreampilot_browser_', '', $tool_name);

        switch ($method) {
            case 'ping':
                return $this->cmd_ping();

            case 'start':
                return $this->cmd_start();

            case 'navigate':
                return $this->cmd_navigate(
                    $args['url'] ?? '',
                    $args['timeout'] ?? $this->get_timeout()
                );

            case 'snapshot':
                return $this->cmd_snapshot($args['selector'] ?? null);

            case 'click':
                return $this->cmd_click($args['selector'] ?? '');

            case 'fill':
                return $this->cmd_fill(
                    $args['selector'] ?? '',
                    $args['value'] ?? ''
                );

            case 'wait':
                return $this->cmd_wait(
                    $args['type'] ?? 'load',
                    $args['value'] ?? '',
                    $args['timeout'] ?? $this->get_timeout()
                );

            case 'scroll':
                return $this->cmd_scroll(
                    $args['direction'] ?? 'down',
                    $args['amount'] ?? 500
                );

            default:
                return array('error' => "Unknown browser command: {$method}");
        }
    }

    // -------------------------------------------------------------------------
    // CLI Command Wrappers
    // -------------------------------------------------------------------------

    /**
     * Build the base command with profile
     *
     * @return string
     */
    private function base_cmd()
    {
        $profile = escapeshellarg($this->get_profile());
        return "openclaw browser --browser-profile {$profile}";
    }

    /**
     * Execute a CLI command and return output
     *
     * @param string $cmd Command to execute
     * @param bool   $capture_output Whether to capture stdout
     * @return array{success: bool, output?: string, error?: string}
     */
    private function exec_cmd($cmd, $capture_output = true)
    {
        $full_cmd = $this->base_cmd() . ' ' . $cmd;

        if ($capture_output) {
            $output = shell_exec($full_cmd . ' 2>&1');
            if ($output === null) {
                return array(
                    'success' => false,
                    'error'   => 'Command execution failed',
                );
            }
            return array(
                'success' => true,
                'output'  => trim($output),
            );
        } else {
            exec($full_cmd . ' > /dev/null 2>&1', $output, $return_code);
            return array(
                'success' => $return_code === 0,
            );
        }
    }

    /**
     * Ping the browser to check if running
     *
     * @return array
     */
    private function cmd_ping()
    {
        $result = $this->exec_cmd('ping');

        if (!$result['success']) {
            return array(
                'running' => false,
                'message' => 'Browser is not running or not responding',
            );
        }

        $output = strtolower($result['output'] ?? '');
        $running = strpos($output, 'pong') !== false || strpos($output, 'running: true') !== false;

        return array(
            'running' => $running,
            'message' => $running ? 'Browser is running' : 'Browser is not running',
            'raw'     => $result['output'],
        );
    }

    /**
     * Start the browser
     *
     * @return array
     */
    private function cmd_start()
    {
        // First check if already running
        $ping = $this->cmd_ping();
        if ($ping['running']) {
            return array(
                'success' => true,
                'message' => 'Browser is already running',
            );
        }

        // Start the browser
        $this->exec_cmd('start', false);

        // Wait a moment for startup
        usleep(2000000); // 2 seconds

        // Verify it started
        $ping = $this->cmd_ping();

        return array(
            'success' => $ping['running'],
            'message' => $ping['running'] ? 'Browser started successfully' : 'Failed to start browser',
        );
    }

    /**
     * Navigate to a URL
     *
     * @param string $url     URL to navigate to
     * @param int    $timeout Timeout in milliseconds
     * @return array
     */
    private function cmd_navigate($url, $timeout = 30000)
    {
        if (empty($url)) {
            return array('error' => 'URL is required');
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return array('error' => 'Invalid URL format');
        }

        $cmd = 'navigate ' . escapeshellarg($url) . ' --timeout=' . intval($timeout);
        $result = $this->exec_cmd($cmd, false);

        if (!$result['success']) {
            return array(
                'success' => false,
                'error'   => 'Navigation failed',
                'url'     => $url,
            );
        }

        return array(
            'success' => true,
            'message' => 'Navigated to ' . $url,
            'url'     => $url,
        );
    }

    /**
     * Take a page snapshot
     *
     * @param string|null $selector Optional element selector
     * @return array
     */
    private function cmd_snapshot($selector = null)
    {
        // Use temp file to capture output (avoids pipe buffer issues)
        $tmp_file = sys_get_temp_dir() . '/dreampilot_snapshot_' . getmypid() . '.txt';

        $cmd = 'snapshot';
        if (!empty($selector)) {
            $cmd .= ' --selector=' . escapeshellarg($selector);
        }

        $full_cmd = $this->base_cmd() . ' ' . $cmd . ' > ' . escapeshellarg($tmp_file) . ' 2>&1';
        exec($full_cmd);

        if (!file_exists($tmp_file)) {
            return array(
                'success' => false,
                'error'   => 'Failed to capture snapshot',
            );
        }

        $content = file_get_contents($tmp_file);
        @unlink($tmp_file);

        if (empty($content)) {
            return array(
                'success' => false,
                'error'   => 'Snapshot returned empty content',
            );
        }

        // Truncate if very large to avoid token overflow
        $max_length = 50000; // ~12.5k tokens
        $truncated = false;
        if (strlen($content) > $max_length) {
            $content = substr($content, 0, $max_length) . "\n\n[... truncated, use selector for specific elements ...]";
            $truncated = true;
        }

        return array(
            'success'   => true,
            'content'   => $content,
            'length'    => strlen($content),
            'truncated' => $truncated,
        );
    }

    /**
     * Click an element
     *
     * @param string $selector CSS selector or text
     * @return array
     */
    private function cmd_click($selector)
    {
        if (empty($selector)) {
            return array('error' => 'Selector is required');
        }

        $cmd = 'click ' . escapeshellarg($selector);
        $result = $this->exec_cmd($cmd);

        return array(
            'success' => $result['success'],
            'message' => $result['success'] ? "Clicked: {$selector}" : "Failed to click: {$selector}",
            'output'  => $result['output'] ?? null,
        );
    }

    /**
     * Fill a form field
     *
     * @param string $selector Field selector
     * @param string $value    Value to fill
     * @return array
     */
    private function cmd_fill($selector, $value)
    {
        if (empty($selector)) {
            return array('error' => 'Selector is required');
        }

        $cmd = 'fill ' . escapeshellarg($selector) . ' ' . escapeshellarg($value);
        $result = $this->exec_cmd($cmd);

        return array(
            'success' => $result['success'],
            'message' => $result['success'] ? "Filled {$selector} with value" : "Failed to fill: {$selector}",
            'output'  => $result['output'] ?? null,
        );
    }

    /**
     * Wait for an event
     *
     * @param string $type    Wait type (load, networkidle, selector, time)
     * @param string $value   Value for selector/time
     * @param int    $timeout Maximum wait time
     * @return array
     */
    private function cmd_wait($type, $value = '', $timeout = 30000)
    {
        switch ($type) {
            case 'load':
                $cmd = 'wait --load load --timeout=' . intval($timeout);
                break;

            case 'networkidle':
                $cmd = 'wait --load networkidle --timeout=' . intval($timeout);
                break;

            case 'selector':
                if (empty($value)) {
                    return array('error' => 'Selector value is required for wait type "selector"');
                }
                $cmd = 'wait --selector=' . escapeshellarg($value) . ' --timeout=' . intval($timeout);
                break;

            case 'time':
                $ms = intval($value);
                if ($ms > 0) {
                    usleep($ms * 1000);
                }
                return array(
                    'success' => true,
                    'message' => "Waited {$ms}ms",
                );

            default:
                return array('error' => "Unknown wait type: {$type}");
        }

        $result = $this->exec_cmd($cmd, false);

        return array(
            'success' => $result['success'],
            'message' => $result['success'] ? "Wait completed ({$type})" : "Wait failed ({$type})",
        );
    }

    /**
     * Scroll the page
     *
     * @param string $direction Scroll direction (up, down, top, bottom)
     * @param int    $amount    Pixels for up/down
     * @return array
     */
    private function cmd_scroll($direction, $amount = 500)
    {
        switch ($direction) {
            case 'top':
                $cmd = 'scroll --to=top';
                break;

            case 'bottom':
                $cmd = 'scroll --to=bottom';
                break;

            case 'up':
                $cmd = 'scroll --by=-' . abs(intval($amount));
                break;

            case 'down':
            default:
                $cmd = 'scroll --by=' . abs(intval($amount));
                break;
        }

        $result = $this->exec_cmd($cmd, false);

        return array(
            'success' => true,
            'message' => "Scrolled {$direction}",
        );
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', array('RawWire_Browser_OpenClaw', 'init'), 15);
