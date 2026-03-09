<?php

/**
 * AI Engine Patch Injector (Toolkit)
 *
 * Applies and reverts AI Engine patch sets on activation/deactivation,
 * and rolls back on Raw Wire initialization failures.
 *
 * @package RawWire\Toolbox\Features
 * @since 1.0.25
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RawWire_AI_Engine_Injector')) {
    class RawWire_AI_Engine_Injector
    {
        private static $instance = null;

        private $patch_id = 'ai_engine_env_models';
        private $state_option = 'rawwire_ai_engine_patch_state';
        private $init_flag_option = 'rawwire_init_in_progress';
        private $target_rel_path = 'ai-engine-pro/classes/core.php';
        private $backup_file = '';
        private $request_id = '';

        private $original_snippet = "";
        private $patched_snippet = "";

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            $this->original_snippet = "      else {\n" .
                "        \$engine['models'] = [];\n" .
                "        foreach ( \$options['ai_models'] as \$model ) {\n" .
                "          if ( \$model['type'] === \$engine['type'] ) {\n" .
                "            \$engine['models'][] = \$model;\n" .
                "          }\n" .
                "        }\n" .
                "      }";

            $this->patched_snippet = "      else {\n" .
                "        \$engine['models'] = [];\n" .
                "        \$model_index = [];\n" .
                "        if ( !empty( \$options['ai_models'] ) ) {\n" .
                "          foreach ( \$options['ai_models'] as \$model ) {\n" .
                "            if ( \$model['type'] === \$engine['type'] ) {\n" .
                "              \$key = ( \$model['envId'] ?? 'global' ) . ':' . ( \$model['model'] ?? '' );\n" .
                "              \$engine['models'][] = \$model;\n" .
                "              \$model_index[\$key] = true;\n" .
                "            }\n" .
                "          }\n" .
                "        }\n" .
                "        if ( !empty( \$options['ai_envs'] ) ) {\n" .
                "          foreach ( \$options['ai_envs'] as \$env ) {\n" .
                "            if ( ( \$env['type'] ?? null ) !== \$engine['type'] ) {\n" .
                "              continue;\n" .
                "            }\n" .
                "            if ( empty( \$env['models'] ) || !is_array( \$env['models'] ) ) {\n" .
                "              continue;\n" .
                "            }\n" .
                "            foreach ( \$env['models'] as \$env_model ) {\n" .
                "              \$key = ( \$env['id'] ?? 'global' ) . ':' . ( \$env_model['model'] ?? '' );\n" .
                "              if ( isset( \$model_index[\$key] ) ) {\n" .
                "                continue;\n" .
                "              }\n" .
                "              \$env_model['envId'] = \$env['id'] ?? null;\n" .
                "              \$env_model['type'] = \$engine['type'];\n" .
                "              \$engine['models'][] = \$env_model;\n" .
                "              \$model_index[\$key] = true;\n" .
                "            }\n" .
                "          }\n" .
                "        }\n" .
                "      }";

            $this->setup_backup_path();
        }

        private function setup_backup_path()
        {
            $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
            $base = is_array($upload) && !empty($upload['basedir']) ? $upload['basedir'] : WP_CONTENT_DIR . '/uploads';
            $dir = trailingslashit($base) . 'rawwire/patches';
            $this->backup_file = $dir . '/ai-engine-pro-core.php.bak';
        }

        public function boot_start()
        {
            // Generate a unique ID for THIS request to prevent race conditions
            // across concurrent requests sharing the same DB option.
            $this->request_id = wp_generate_uuid4();
            update_option($this->init_flag_option, $this->request_id);
            add_action('shutdown', array($this, 'shutdown_check'), 1);
            $this->ensure_patch_state();
        }

        public function boot_complete()
        {
            // Only clear the flag if it still belongs to this request
            $stored = get_option($this->init_flag_option);
            if ($stored === $this->request_id) {
                delete_option($this->init_flag_option);
            }
            // Mark this instance as complete so shutdown_check skips rollback
            $this->request_id = '';
        }

        public function activate()
        {
            $this->apply_patch();
        }

        public function deactivate()
        {
            $this->rollback_patch();
        }

        public function shutdown_check()
        {
            // If boot_complete() already ran for this request, nothing to do
            if (empty($this->request_id)) {
                return;
            }

            // Only act if the DB flag still belongs to THIS request
            $stored = get_option($this->init_flag_option);
            if ($stored !== $this->request_id) {
                return;
            }

            // This request's init never completed — clean up the flag
            delete_option($this->init_flag_option);

            $error = error_get_last();
            if (!empty($error) && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                $this->log('Init failed; rolling back AI Engine patch.', 'error', array('error' => $error));
                $this->rollback_patch();
            } else {
                $this->log('Init incomplete; rolling back AI Engine patch.', 'warning');
                $this->rollback_patch();
            }
        }

        private function ensure_patch_state()
        {
            if (!$this->ai_engine_available()) {
                return;
            }
            $state = $this->get_state();
            $is_patched = $this->is_patched();

            if (empty($state['applied'])) {
                $this->apply_patch();
                return;
            }

            if (!empty($state['applied']) && !$is_patched) {
                $this->apply_patch();
            } elseif (!empty($state['applied']) && $is_patched) {
                $this->set_state(true);
            }
        }

        private function ai_engine_available()
        {
            $target = $this->get_target_path();
            return file_exists($target);
        }

        private function get_target_path()
        {
            return trailingslashit(WP_PLUGIN_DIR) . $this->target_rel_path;
        }

        private function is_patched()
        {
            $target = $this->get_target_path();
            if (!file_exists($target)) {
                return false;
            }
            $contents = file_get_contents($target);
            if ($contents === false) {
                return false;
            }
            return strpos($contents, $this->patched_snippet) !== false;
        }

        private function apply_patch()
        {
            $target = $this->get_target_path();
            if (!file_exists($target)) {
                $this->log('AI Engine target file missing; patch skipped.', 'warning', array('path' => $target));
                return false;
            }

            $contents = file_get_contents($target);
            if ($contents === false) {
                $this->log('AI Engine target file unreadable; patch skipped.', 'error', array('path' => $target));
                return false;
            }

            if (strpos($contents, $this->patched_snippet) !== false) {
                $this->set_state(true);
                return true;
            }

            if (strpos($contents, $this->original_snippet) === false) {
                $this->log('Patch anchor not found; patch skipped.', 'error');
                return false;
            }

            $this->backup_original($contents);

            $patched = str_replace($this->original_snippet, $this->patched_snippet, $contents);
            if ($patched === $contents) {
                $this->log('Patch replacement failed; no changes applied.', 'error');
                return false;
            }

            if (file_put_contents($target, $patched) === false) {
                $this->log('Failed to write patched AI Engine file.', 'error', array('path' => $target));
                return false;
            }

            $this->set_state(true, array('hash' => md5($patched)));
            $this->log('AI Engine patch applied.', 'info');
            return true;
        }

        private function rollback_patch()
        {
            $target = $this->get_target_path();
            if (!file_exists($target)) {
                $this->set_state(false);
                return true;
            }

            if (file_exists($this->backup_file)) {
                $backup = file_get_contents($this->backup_file);
                if ($backup !== false && file_put_contents($target, $backup) !== false) {
                    $this->set_state(false);
                    $this->log('AI Engine patch rolled back from backup.', 'info');
                    return true;
                }
            }

            $contents = file_get_contents($target);
            if ($contents === false) {
                $this->log('AI Engine target file unreadable; rollback skipped.', 'error', array('path' => $target));
                return false;
            }

            if (strpos($contents, $this->patched_snippet) === false) {
                $this->set_state(false);
                return true;
            }

            $reverted = str_replace($this->patched_snippet, $this->original_snippet, $contents);
            if ($reverted === $contents) {
                $this->log('Rollback replacement failed; no changes applied.', 'error');
                return false;
            }

            if (file_put_contents($target, $reverted) === false) {
                $this->log('Failed to write rollback AI Engine file.', 'error', array('path' => $target));
                return false;
            }

            $this->set_state(false);
            $this->log('AI Engine patch rolled back.', 'info');
            return true;
        }

        private function backup_original(string $contents)
        {
            $dir = dirname($this->backup_file);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            if (!file_exists($this->backup_file)) {
                file_put_contents($this->backup_file, $contents);
            }
        }

        private function get_state()
        {
            $state = get_option($this->state_option, array());
            return is_array($state) ? ($state[$this->patch_id] ?? array()) : array();
        }

        private function set_state(bool $applied, array $data = array())
        {
            $state = get_option($this->state_option, array());
            if (!is_array($state)) {
                $state = array();
            }
            if ($applied) {
                $state[$this->patch_id] = array_merge(array(
                    'applied' => true,
                    'updated_at' => time(),
                ), $data);
            } else {
                unset($state[$this->patch_id]);
            }
            update_option($this->state_option, $state);
        }

        private function log(string $message, string $level = 'info', array $context = array())
        {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::log($message, $level, $context);
            } else if (defined('WP_DEBUG') && WP_DEBUG) {
                $ctx = !empty($context) ? ' | ' . wp_json_encode($context) : '';
                error_log("RawWire Injector [{$level}]: {$message}{$ctx}");
            }
        }
    }
}
