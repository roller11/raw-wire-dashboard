<?php

/**
 * AI Provider Manager - Provider-agnostic abstraction layer
 *
 * Wraps any RawWire_Generator_Interface adapter behind a unified interface.
 * Handles provider selection based on settings, budget tier, or fallback chain.
 * Normalizes response format across Venice, OpenAI, Anthropic, Ollama.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\DreamPilot
 * @since 1.0.25
 */

if (!defined('ABSPATH')) {
    exit;
}

class DreamPilot_AI_Provider_Manager
{

    /**
     * Singleton instance
     * @var DreamPilot_AI_Provider_Manager|null
     */
    private static $instance = null;

    /**
     * Cached provider instance
     * @var RawWire_Generator_Interface|null
     */
    private $provider = null;

    /**
     * Provider ID that was resolved
     * @var string
     */
    private $provider_id = '';

    /**
     * Provider priority order (highest priority first)
     * Each entry: ['category' => 'generator', 'adapter' => 'adapter_id']
     *
     * @var array
     */
    private static $fallback_chain = [
        ['category' => 'generator', 'adapter' => 'venice_ai'],
        ['category' => 'generator', 'adapter' => 'openai_standard'],
        ['category' => 'generator', 'adapter' => 'anthropic_claude'],
        ['category' => 'generator', 'adapter' => 'ollama_local'],
    ];

    /**
     * Get singleton instance
     *
     * @return DreamPilot_AI_Provider_Manager
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {}

    /**
     * Get the active AI provider adapter
     *
     * Resolution order:
     * 1. Explicit provider from DreamPilot settings
     * 2. Configured generator from Toolbox Core
     * 3. Fallback chain (first available)
     *
     * @param bool $force_refresh  Skip cache and re-resolve
     * @return RawWire_Generator_Interface|WP_Error
     */
    public function get_provider($force_refresh = false)
    {
        if ($this->provider && !$force_refresh) {
            return $this->provider;
        }

        // 1. Check DreamPilot-specific provider setting
        $dp_settings = get_option('rawwire_dreampilot_settings', []);
        $preferred   = $dp_settings['provider'] ?? '';

        if ($preferred && class_exists('RawWire_Toolbox_Core')) {
            $adapter = RawWire_Toolbox_Core::factory('generator', $preferred);
            if (!is_wp_error($adapter)) {
                $test = $adapter->test_connection();
                if (!empty($test['success'])) {
                    $this->provider    = $adapter;
                    $this->provider_id = $preferred;
                    return $this->provider;
                }
            }
        }

        // 2. Check Toolbox Core configured generator
        if (class_exists('RawWire_Toolbox_Core')) {
            $configured = RawWire_Toolbox_Core::get_configured_adapter('generator');
            if ($configured && !is_wp_error($configured)) {
                $this->provider    = $configured;
                $this->provider_id = 'configured';
                return $this->provider;
            }
        }

        // 3. Fallback chain - try each until one works
        $fallback_chain = apply_filters('dreampilot_provider_fallback_chain', self::$fallback_chain);

        foreach ($fallback_chain as $candidate) {
            if (!class_exists('RawWire_Toolbox_Core')) {
                break;
            }

            $adapter = RawWire_Toolbox_Core::factory($candidate['category'], $candidate['adapter']);
            if (is_wp_error($adapter)) {
                continue;
            }

            $test = $adapter->test_connection();
            if (!empty($test['success'])) {
                $this->provider    = $adapter;
                $this->provider_id = $candidate['adapter'];
                return $this->provider;
            }
        }

        return new WP_Error(
            'dreampilot_no_provider',
            __('No AI provider available. Configure at least one generator in AI Settings.', 'raw-wire-dashboard')
        );
    }

    /**
     * Get the resolved provider ID
     *
     * @return string  Adapter ID or empty if not resolved
     */
    public function get_provider_id()
    {
        return $this->provider_id;
    }

    /**
     * Check if the current provider supports tool calling
     *
     * @return bool
     */
    public function supports_tools()
    {
        $provider = $this->get_provider();
        if (is_wp_error($provider)) {
            return false;
        }

        return $provider->supports('function_calling');
    }

    /**
     * Send a chat message through the provider
     *
     * Normalizes the response format regardless of which adapter is used.
     *
     * @param string $system_prompt  System instructions
     * @param string $user_message   User's message
     * @param array  $options        Additional options (history, temperature, etc.)
     * @return array{success: bool, content: string, model: string, usage: array, provider: string}
     */
    public function chat($system_prompt, $user_message, $options = [])
    {
        $provider = $this->get_provider();
        if (is_wp_error($provider)) {
            return [
                'success' => false,
                'error'   => $provider->get_error_message(),
            ];
        }

        $result = $provider->chat($system_prompt, $user_message, $options);

        // Normalize response
        return array_merge($result, [
            'provider' => $this->provider_id,
        ]);
    }

    /**
     * Send a chat message with tool-use support
     *
     * Falls back to regular chat() if provider doesn't support tools.
     *
     * @param array    $messages       Chat messages array [{role, content}]
     * @param array    $tools          OpenAI-format tool definitions
     * @param callable $tool_executor  fn(string $name, array $args): string
     * @param array    $options        Additional options
     * @return array{success: bool, content: string, tool_calls: array, model: string, usage: array}
     */
    public function chat_with_tools($messages, $tools, $tool_executor, $options = [])
    {
        $provider = $this->get_provider();
        if (is_wp_error($provider)) {
            return [
                'success' => false,
                'error'   => $provider->get_error_message(),
            ];
        }

        // Check if provider supports tool calling
        if ($this->supports_tools() && method_exists($provider, 'chat_with_tools')) {
            $result = $provider->chat_with_tools($messages, $tools, $tool_executor, $options);
            return array_merge($result, ['provider' => $this->provider_id]);
        }

        // Fallback: inject tool descriptions into system prompt
        $system_content = $messages[0]['content'] ?? '';
        $user_message   = '';
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $user_message = $msg['content'];
            }
        }

        $tool_desc = "\n\nAvailable tools (describe which you would use):\n";
        foreach ($tools as $tool) {
            $fn   = $tool['function'] ?? [];
            $name = $fn['name'] ?? 'unknown';
            $desc = $fn['description'] ?? '';
            $tool_desc .= "- {$name}: {$desc}\n";
        }

        $result = $provider->chat($system_content . $tool_desc, $user_message, $options);
        return array_merge($result, [
            'provider'   => $this->provider_id,
            'tool_calls' => [],
        ]);
    }

    /**
     * Get provider info for display
     *
     * @return array{id: string, name: string, model: string, supports_tools: bool}
     */
    public function get_provider_info()
    {
        $provider = $this->get_provider();
        if (is_wp_error($provider)) {
            return [
                'id'             => '',
                'name'           => 'None',
                'model'          => '',
                'supports_tools' => false,
            ];
        }

        $info = $provider->get_info();
        return [
            'id'             => $this->provider_id,
            'name'           => $info['name'] ?? $this->provider_id,
            'model'          => $info['model'] ?? '',
            'supports_tools' => $this->supports_tools(),
        ];
    }

    /**
     * Get all available providers with their status
     *
     * @return array  List of provider statuses
     */
    public function get_available_providers()
    {
        $providers = [];

        if (!class_exists('RawWire_Toolbox_Core')) {
            return $providers;
        }

        $adapters = RawWire_Toolbox_Core::get_category_adapters('generator');
        if (empty($adapters)) {
            return $providers;
        }

        foreach ($adapters as $adapter_id => $definition) {
            $providers[] = [
                'id'           => $adapter_id,
                'label'        => $definition['label'] ?? $adapter_id,
                'tier'         => $definition['tier'] ?? 'unknown',
                'capabilities' => $definition['capabilities'] ?? [],
                'active'       => ($adapter_id === $this->provider_id),
            ];
        }

        return $providers;
    }

    /**
     * Reset cached provider (useful after settings change)
     */
    public function reset()
    {
        $this->provider    = null;
        $this->provider_id = '';
    }
}
