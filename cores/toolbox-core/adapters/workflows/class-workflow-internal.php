<?php
/**
 * Internal Pipeline Workflow Adapter (Free Tier)
 * WP Cron-based workflow execution with step-by-step processing.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-workflow.php';

class RawWire_Adapter_Workflow_Internal extends RawWire_Adapter_Base implements RawWire_Workflow_Interface {
    
    protected $name = 'Internal Pipeline';
    protected $version = '1.0.0';
    protected $tier = 'free';
    protected $capabilities = array('sequential_steps', 'error_recovery', 'logging');
    protected $required_fields = array();

    /**
     * Defined workflow steps
     * @var array
     */
    private $steps = array();

    /**
     * Progress callback
     * @var callable|null
     */
    private $progress_callback = null;

    /**
     * Active executions
     * @var array
     */
    private static $executions = array();

    /**
     * Test the internal pipeline
     */
    public function test_connection() {
        return array(
            'success' => true,
            'message' => 'Internal pipeline is ready',
            'details' => array(
                'capabilities' => $this->capabilities,
                'max_execution_time' => $this->get_config('max_execution_time', 30),
                'retry_attempts' => $this->get_config('retry_attempts', 3),
            ),
        );
    }

    /**
     * Trigger a workflow execution
     */
    public function trigger(array $payload, array $options = array()) {
        $execution_id = wp_generate_uuid4();
        $start_time = microtime(true);
        $max_time = $this->get_config('max_execution_time', 30);

        $this->log('Pipeline triggered', 'info', array(
            'execution_id' => $execution_id,
            'steps_count' => count($this->steps),
        ));

        // Store execution state
        self::$executions[$execution_id] = array(
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'current_step' => 0,
            'steps_total' => count($this->steps),
            'payload' => $payload,
            'results' => array(),
            'errors' => array(),
        );

        // Check if async execution requested
        if (!empty($options['async'])) {
            // Schedule via WP Cron
            $this->schedule_async_execution($execution_id, $payload);
            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'status' => 'scheduled',
                'message' => 'Pipeline scheduled for async execution',
            );
        }

        // Synchronous execution
        $context = array('data' => $payload);
        $retry_attempts = $this->get_config('retry_attempts', 3);

        foreach ($this->steps as $index => $step) {
            // Check execution time limit
            if (microtime(true) - $start_time > $max_time) {
                self::$executions[$execution_id]['status'] = 'timeout';
                $this->set_error('timeout', 'Pipeline execution exceeded time limit');
                return array(
                    'success' => false,
                    'execution_id' => $execution_id,
                    'error' => 'Execution timeout',
                    'completed_steps' => $index,
                );
            }

            // Update progress
            self::$executions[$execution_id]['current_step'] = $index + 1;
            $this->notify_progress($execution_id, $index + 1, count($this->steps));

            $this->log("Executing step {$index}", 'debug', array(
                'step' => $step['name'] ?? "step_$index",
                'execution_id' => $execution_id,
            ));

            // Execute step with retry logic
            $result = $this->execute_step_with_retry($step, $context, $retry_attempts);

            if (!$result['success']) {
                self::$executions[$execution_id]['status'] = 'failed';
                self::$executions[$execution_id]['errors'][] = array(
                    'step' => $index,
                    'error' => $result['error'],
                );

                // Check if step is critical
                if ($step['critical'] ?? true) {
                    $this->log('Critical step failed, aborting pipeline', 'error', array(
                        'step' => $index,
                        'error' => $result['error'],
                    ));
                    return array(
                        'success' => false,
                        'execution_id' => $execution_id,
                        'error' => $result['error'],
                        'failed_step' => $index,
                    );
                }

                $this->log('Non-critical step failed, continuing', 'warning', array(
                    'step' => $index,
                    'error' => $result['error'],
                ));
            }

            // Pass result to next step context
            $context['previous_result'] = $result['data'] ?? null;
            $context[$step['output_key'] ?? "step_{$index}_result"] = $result['data'] ?? null;
            self::$executions[$execution_id]['results'][$index] = $result;
        }

        self::$executions[$execution_id]['status'] = 'completed';
        self::$executions[$execution_id]['completed_at'] = current_time('mysql');

        $this->log('Pipeline completed', 'info', array(
            'execution_id' => $execution_id,
            'duration' => microtime(true) - $start_time,
        ));

        return array(
            'success' => true,
            'execution_id' => $execution_id,
            'result' => $context,
            'steps_completed' => count($this->steps),
        );
    }

    /**
     * Execute a single step with retry logic
     */
    private function execute_step_with_retry(array $step, array $context, int $retries) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $retries) {
            $attempt++;

            try {
                $result = $this->execute_step($step, $context);
                
                if ($result['success']) {
                    return $result;
                }

                $last_error = $result['error'] ?? 'Unknown error';
                
            } catch (Exception $e) {
                $last_error = $e->getMessage();
                $this->log("Step attempt $attempt failed", 'warning', array('error' => $last_error));
            }

            // Wait before retry (exponential backoff)
            if ($attempt < $retries) {
                usleep(pow(2, $attempt) * 100000); // 200ms, 400ms, 800ms...
            }
        }

        return array(
            'success' => false,
            'error' => "Failed after $retries attempts: $last_error",
        );
    }

    /**
     * Execute a single pipeline step
     */
    private function execute_step(array $step, array $context) {
        $type = $step['type'] ?? 'callback';

        switch ($type) {
            case 'callback':
                if (!is_callable($step['callback'])) {
                    return array('success' => false, 'error' => 'Invalid callback');
                }
                try {
                    $result = call_user_func($step['callback'], $context);
                    return array('success' => true, 'data' => $result);
                } catch (Exception $e) {
                    return array('success' => false, 'error' => $e->getMessage());
                }

            case 'http':
                return $this->execute_http_step($step, $context);

            case 'transform':
                return $this->execute_transform_step($step, $context);

            case 'condition':
                return $this->execute_condition_step($step, $context);

            case 'delay':
                $delay = $step['delay'] ?? 1;
                sleep($delay);
                return array('success' => true, 'data' => array('delayed' => $delay));

            default:
                return array('success' => false, 'error' => "Unknown step type: $type");
        }
    }

    /**
     * Execute HTTP step
     */
    private function execute_http_step(array $step, array $context) {
        $url = $this->interpolate($step['url'] ?? '', $context);
        $method = strtoupper($step['method'] ?? 'GET');

        $args = array(
            'method' => $method,
            'timeout' => $step['timeout'] ?? 30,
            'headers' => $step['headers'] ?? array(),
        );

        if (in_array($method, array('POST', 'PUT', 'PATCH')) && isset($step['body'])) {
            $args['body'] = is_array($step['body']) 
                ? $this->interpolate_array($step['body'], $context)
                : $this->interpolate($step['body'], $context);
        }

        $response = $this->http_request($url, $args);

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true) ?: $body;

        return array('success' => true, 'data' => $data);
    }

    /**
     * Execute transform step
     */
    private function execute_transform_step(array $step, array $context) {
        $input = $context['previous_result'] ?? $context['data'] ?? null;
        $transforms = $step['transforms'] ?? array();

        foreach ($transforms as $transform) {
            $input = $this->apply_transform($input, $transform);
        }

        return array('success' => true, 'data' => $input);
    }

    /**
     * Execute condition step
     */
    private function execute_condition_step(array $step, array $context) {
        $field = $step['field'] ?? '';
        $operator = $step['operator'] ?? 'equals';
        $value = $step['value'] ?? null;

        $actual = $this->get_nested_value($context, $field);
        $passed = $this->evaluate_condition($actual, $operator, $value);

        return array(
            'success' => true,
            'data' => array(
                'condition_passed' => $passed,
                'field' => $field,
                'actual' => $actual,
                'expected' => $value,
            ),
        );
    }

    /**
     * Apply a single transform
     */
    private function apply_transform($data, array $transform) {
        $type = $transform['type'] ?? '';

        switch ($type) {
            case 'map':
                if (!is_array($data)) return $data;
                return array_map(function($item) use ($transform) {
                    return $this->extract_fields($item, $transform['fields'] ?? array());
                }, $data);

            case 'filter':
                if (!is_array($data)) return $data;
                return array_filter($data, function($item) use ($transform) {
                    return $this->evaluate_condition(
                        $item[$transform['field']] ?? null,
                        $transform['operator'] ?? 'exists',
                        $transform['value'] ?? null
                    );
                });

            case 'pluck':
                if (!is_array($data)) return $data;
                return array_column($data, $transform['field']);

            case 'first':
                return is_array($data) ? reset($data) : $data;

            case 'last':
                return is_array($data) ? end($data) : $data;

            case 'count':
                return is_array($data) ? count($data) : 0;

            case 'json_encode':
                return json_encode($data);

            case 'json_decode':
                return is_string($data) ? json_decode($data, true) : $data;

            default:
                return $data;
        }
    }

    /**
     * Extract fields from item
     */
    private function extract_fields($item, array $fields) {
        $result = array();
        foreach ($fields as $alias => $path) {
            $key = is_numeric($alias) ? $path : $alias;
            $result[$key] = $this->get_nested_value($item, $path);
        }
        return $result;
    }

    /**
     * Evaluate a condition
     */
    private function evaluate_condition($actual, string $operator, $expected) {
        switch ($operator) {
            case 'equals':
            case '==':
                return $actual == $expected;
            case 'strict_equals':
            case '===':
                return $actual === $expected;
            case 'not_equals':
            case '!=':
                return $actual != $expected;
            case 'greater':
            case '>':
                return $actual > $expected;
            case 'less':
            case '<':
                return $actual < $expected;
            case 'contains':
                return is_string($actual) && strpos($actual, $expected) !== false;
            case 'exists':
                return $actual !== null;
            case 'empty':
                return empty($actual);
            case 'not_empty':
                return !empty($actual);
            default:
                return false;
        }
    }

    /**
     * Get nested value from array using dot notation
     */
    private function get_nested_value($data, string $path) {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !isset($data[$key])) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }

    /**
     * Interpolate variables in string
     */
    private function interpolate(string $template, array $context) {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function($matches) use ($context) {
            return $this->get_nested_value($context, trim($matches[1])) ?? '';
        }, $template);
    }

    /**
     * Interpolate variables in array
     */
    private function interpolate_array(array $data, array $context) {
        $result = array();
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->interpolate($value, $context);
            } elseif (is_array($value)) {
                $result[$key] = $this->interpolate_array($value, $context);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Schedule async execution
     */
    private function schedule_async_execution(string $execution_id, array $payload) {
        // Store execution data
        set_transient("rawwire_pipeline_$execution_id", array(
            'steps' => $this->steps,
            'payload' => $payload,
            'config' => $this->config,
        ), HOUR_IN_SECONDS);

        // Schedule WP Cron event
        wp_schedule_single_event(time(), 'rawwire_execute_pipeline', array($execution_id));
    }

    /**
     * Get execution status
     */
    public function get_status(string $execution_id) {
        if (isset(self::$executions[$execution_id])) {
            $exec = self::$executions[$execution_id];
            return array(
                'status' => $exec['status'],
                'progress' => $exec['steps_total'] > 0 
                    ? $exec['current_step'] / $exec['steps_total'] 
                    : 0,
                'current_step' => $exec['current_step'],
                'total_steps' => $exec['steps_total'],
                'errors' => $exec['errors'],
            );
        }

        return array(
            'status' => 'unknown',
            'progress' => 0,
            'error' => 'Execution not found',
        );
    }

    /**
     * Cancel execution
     */
    public function cancel(string $execution_id) {
        if (isset(self::$executions[$execution_id])) {
            self::$executions[$execution_id]['status'] = 'cancelled';
            $this->log('Pipeline cancelled', 'info', array('execution_id' => $execution_id));
            return true;
        }

        // Try to unschedule async execution
        $args = array($execution_id);
        $timestamp = wp_next_scheduled('rawwire_execute_pipeline', $args);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'rawwire_execute_pipeline', $args);
            return true;
        }

        return false;
    }

    /**
     * Define workflow steps
     */
    public function define_steps(array $steps) {
        $this->steps = array();

        foreach ($steps as $step) {
            if (!isset($step['type'])) {
                continue;
            }
            $this->steps[] = $step;
        }

        $this->log('Pipeline steps defined', 'debug', array('count' => count($this->steps)));
        return true;
    }

    /**
     * Register progress callback
     */
    public function on_progress(callable $callback) {
        $this->progress_callback = $callback;
    }

    /**
     * Notify progress
     */
    private function notify_progress(string $execution_id, int $current, int $total) {
        if (is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, array(
                'execution_id' => $execution_id,
                'current' => $current,
                'total' => $total,
                'progress' => $total > 0 ? $current / $total : 0,
            ));
        }
    }
}
