<?php
/**
 * Generator Adapter Interface
 * Interface for all AI content generation adapters.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/interface-adapter.php';

interface RawWire_Generator_Interface extends RawWire_Adapter_Interface {
    /**
     * Generate content from a prompt
     * 
     * @param string $prompt The generation prompt
     * @param array $options Generation options (model, temperature, max_tokens, etc.)
     * @return array{success: bool, content?: string, usage?: array, error?: string}
     */
    public function generate(string $prompt, array $options = array());

    /**
     * Generate with a system message context
     * 
     * @param string $system_prompt System/context message
     * @param string $user_prompt User message/prompt
     * @param array $options Generation options
     * @return array{success: bool, content?: string, usage?: array, error?: string}
     */
    public function chat(string $system_prompt, string $user_prompt, array $options = array());

    /**
     * Summarize content
     * 
     * @param string $content The content to summarize
     * @param array $options Summary options (length, style, etc.)
     * @return array{success: bool, summary?: string, error?: string}
     */
    public function summarize(string $content, array $options = array());

    /**
     * Analyze content and extract insights
     * 
     * @param string $content The content to analyze
     * @param array $schema Expected output schema
     * @return array{success: bool, analysis?: array, error?: string}
     */
    public function analyze(string $content, array $schema = array());

    /**
     * Get usage/quota information
     * 
     * @return array{used: int, limit: int, cost?: float}
     */
    public function get_usage();
}
