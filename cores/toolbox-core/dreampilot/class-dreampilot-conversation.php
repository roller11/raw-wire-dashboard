<?php

/**
 * DreamPilot Conversation Manager - Session-based conversation management
 *
 * Handles conversation history with session IDs, persistent storage,
 * and memory extraction. Extends the transient approach with session
 * tracking for future multi-agent support.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\DreamPilot
 * @since 1.0.25
 */

if (!defined('ABSPATH')) {
    exit;
}

class DreamPilot_Conversation_Manager
{

    /**
     * Maximum messages to keep in history
     */
    const MAX_HISTORY = 50;

    /**
     * History time-to-live in seconds (12 hours)
     */
    const HISTORY_TTL = 43200;

    /**
     * Singleton instance
     * @var DreamPilot_Conversation_Manager|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return DreamPilot_Conversation_Manager
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
     * Generate transient key for a user's conversation
     *
     * @param string $session_id  Optional session identifier
     * @param int    $user_id     User ID (defaults to current user)
     * @return string  Transient key
     */
    private function history_key($session_id = '', $user_id = 0)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $base = "dreampilot_chat_{$user_id}";

        if ($session_id) {
            $base .= "_{$session_id}";
        }

        return $base;
    }

    /**
     * Get conversation history
     *
     * @param string $session_id  Optional session identifier
     * @return array  Array of {role, content, timestamp?} messages
     */
    public function get_history($session_id = '')
    {
        $key     = $this->history_key($session_id);
        $history = get_transient($key);

        if (!is_array($history)) {
            return [];
        }

        return $history;
    }

    /**
     * Save conversation history
     *
     * Trims to MAX_HISTORY messages, preserving the system message
     * and the most recent exchanges.
     *
     * @param array  $messages    Array of {role, content} messages
     * @param string $session_id  Optional session identifier
     */
    public function save_history($messages, $session_id = '')
    {
        // Trim history while preserving flow
        if (count($messages) > self::MAX_HISTORY) {
            // Keep first message (system) + last MAX_HISTORY-1 messages
            $system = [];
            if (!empty($messages[0]) && $messages[0]['role'] === 'system') {
                $system = [$messages[0]];
            }
            $recent   = array_slice($messages, - (self::MAX_HISTORY - count($system)));
            $messages = array_merge($system, $recent);
        }

        $key = $this->history_key($session_id);
        set_transient($key, $messages, self::HISTORY_TTL);

        // Track active sessions for the user
        $this->track_session($session_id);
    }

    /**
     * Clear conversation history
     *
     * @param string $session_id  Optional session identifier (empty = default)
     */
    public function clear_history($session_id = '')
    {
        $key = $this->history_key($session_id);
        delete_transient($key);

        // Remove from active sessions list
        $this->untrack_session($session_id);
    }

    /**
     * Append a message to history
     *
     * @param string $role        Message role (user, assistant, system, tool)
     * @param string $content     Message content
     * @param string $session_id  Optional session identifier
     * @param array  $meta        Optional metadata (tool_calls, tool_call_id, etc.)
     */
    public function append_message($role, $content, $session_id = '', $meta = [])
    {
        $history = $this->get_history($session_id);

        $message = [
            'role'    => $role,
            'content' => $content,
        ];

        // Include tool-related metadata if present
        if (!empty($meta['tool_calls'])) {
            $message['tool_calls'] = $meta['tool_calls'];
        }
        if (!empty($meta['tool_call_id'])) {
            $message['tool_call_id'] = $meta['tool_call_id'];
        }

        $history[] = $message;

        $this->save_history($history, $session_id);
    }

    /**
     * Get active sessions for the current user
     *
     * @return array  List of session IDs
     */
    public function get_active_sessions()
    {
        $user_id  = get_current_user_id();
        $sessions = get_transient("dreampilot_sessions_{$user_id}");

        return is_array($sessions) ? $sessions : [''];
    }

    /**
     * Track an active session
     *
     * @param string $session_id  Session identifier
     */
    private function track_session($session_id)
    {
        $user_id  = get_current_user_id();
        $sessions = $this->get_active_sessions();

        if (!in_array($session_id, $sessions, true)) {
            $sessions[] = $session_id;
            set_transient("dreampilot_sessions_{$user_id}", $sessions, self::HISTORY_TTL);
        }
    }

    /**
     * Remove a session from tracking
     *
     * @param string $session_id  Session identifier
     */
    private function untrack_session($session_id)
    {
        $user_id  = get_current_user_id();
        $sessions = $this->get_active_sessions();
        $sessions = array_filter($sessions, function ($s) use ($session_id) {
            return $s !== $session_id;
        });
        set_transient("dreampilot_sessions_{$user_id}", array_values($sessions), self::HISTORY_TTL);
    }

    /**
     * Build messages array for API call
     *
     * Combines system prompt + history + current user message.
     *
     * @param string $system_prompt  System instructions
     * @param string $user_message   Current user message
     * @param string $session_id     Optional session identifier
     * @return array  Messages array ready for API call
     */
    public function build_messages($system_prompt, $user_message, $session_id = '')
    {
        $messages = [];

        // System prompt
        if ($system_prompt) {
            $messages[] = [
                'role'    => 'system',
                'content' => $system_prompt,
            ];
        }

        // Previous history (excluding any old system messages)
        $history = $this->get_history($session_id);
        foreach ($history as $msg) {
            if ($msg['role'] !== 'system') {
                $messages[] = $msg;
            }
        }

        // Current user message
        $messages[] = [
            'role'    => 'user',
            'content' => $user_message,
        ];

        return $messages;
    }

    /**
     * Extract memories from a conversation exchange
     *
     * Regex-based extraction of user facts, preferences, and context
     * for persistent memory storage.
     *
     * @param string $user_message      User's message
     * @param string $assistant_reply   Assistant's response
     */
    public function extract_memories($user_message, $assistant_reply)
    {
        if (!class_exists('RawWire_AI_Memory')) {
            return;
        }

        $memory = RawWire_AI_Memory::get_instance();

        // Name patterns
        if (preg_match('/(?:my name is|I\'m|I am|call me)\s+(\w+)/i', $user_message, $matches)) {
            $memory->save("User's name is {$matches[1]}", 'fact', 9);
        }

        // Business type
        if (preg_match('/(?:I run|I own|my business is|I\'m in|we do|my company)\s+(.{5,60})/i', $user_message, $matches)) {
            $memory->save("User's business: {$matches[1]}", 'fact', 8);
        }

        // Preferences
        if (preg_match('/(?:I prefer|I like|I want|I need)\s+(.{5,80})/i', $user_message, $matches)) {
            $memory->save("User preference: {$matches[1]}", 'preference', 6);
        }

        // Location
        if (preg_match('/(?:I\'m (?:in|from|based in|located in))\s+(.{3,40})/i', $user_message, $matches)) {
            $memory->save("User location: {$matches[1]}", 'fact', 7);
        }

        // Allow extensions to add custom extraction patterns
        do_action('dreampilot_extract_memories', $user_message, $assistant_reply, $memory);
    }
}
