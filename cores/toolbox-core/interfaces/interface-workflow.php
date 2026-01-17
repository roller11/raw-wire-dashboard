<?php
/**
 * Workflow Adapter Interface
 * Interface for all workflow orchestration adapters.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/interface-adapter.php';

interface RawWire_Workflow_Interface extends RawWire_Adapter_Interface {
    /**
     * Trigger a workflow with payload
     * 
     * @param array $payload Data to send to the workflow
     * @param array $options Execution options (async, timeout, etc.)
     * @return array{success: bool, execution_id?: string, result?: mixed, error?: string}
     */
    public function trigger(array $payload, array $options = array());

    /**
     * Get the status of a workflow execution
     * 
     * @param string $execution_id The execution ID returned from trigger()
     * @return array{status: string, progress?: float, result?: mixed, error?: string}
     */
    public function get_status(string $execution_id);

    /**
     * Cancel a running workflow
     * 
     * @param string $execution_id The execution ID
     * @return bool Success or failure
     */
    public function cancel(string $execution_id);

    /**
     * Define workflow steps for internal pipelines
     * 
     * @param array $steps Array of step definitions
     * @return bool True if steps are valid
     */
    public function define_steps(array $steps);

    /**
     * Register a callback for workflow progress updates
     * 
     * @param callable $callback Function to call on updates
     * @return void
     */
    public function on_progress(callable $callback);
}
