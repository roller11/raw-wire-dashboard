<?php
/**
 * RawWire Dashboard Deployment with Module Simulator
 *
 * Comprehensive audit and deployment script for the RawWire Dashboard
 * with built-in module simulator for testing without external modules.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Dashboard Deployment Class
 */
class RawWire_Dashboard_Deploy {

    private $plugin_dir;
    private $deploy_log = array();
    private $errors = array();
    private $warnings = array();

    public function __construct() {
        $this->plugin_dir = plugin_dir_path(dirname(__FILE__));
    }

    /**
     * Run the complete deployment audit
     */
    public function run_deployment_audit() {
        $this->log("🔍 Starting RawWire Dashboard Deployment Audit");
        $this->log("================================================\n");

        // Core plugin audit
        $this->audit_core_plugin();

        // Module system audit
        $this->audit_module_system();

        // Simulator module audit
        $this->audit_simulator_module();

        // Security audit
        $this->audit_security();

        // Performance audit
        $this->audit_performance();

        // Generate deployment report
        $this->generate_deployment_report();

        // Final deployment steps
        $this->final_deployment_steps();
    }

    /**
     * Audit core plugin files and structure
     */
    private function audit_core_plugin() {
        $this->log("📁 Auditing Core Plugin Structure");

        $required_files = array(
            'raw-wire-dashboard.php' => 'Main plugin file',
            'includes/class-dashboard-core.php' => 'Dashboard core class',
            'includes/interface-module.php' => 'Module interface definition',
            'cores/module-core/module-core.php' => 'Module core system',
            'templates/raw-wire-default.json' => 'Default UI template',
            'README.md' => 'Documentation'
        );

        foreach ($required_files as $file => $description) {
            $path = $this->plugin_dir . $file;
            if (file_exists($path)) {
                $this->log("✓ {$description} found: {$file}");
            } else {
                $this->error("✗ Missing {$description}: {$file}");
            }
        }

        $this->log("");
    }

    /**
     * Audit module system
     */
    private function audit_module_system() {
        $this->log("🔧 Auditing Module System");

        // Check module core
        if (class_exists('RawWire_Module_Core')) {
            $this->log("✓ RawWire_Module_Core class available");
        } else {
            $this->error("✗ RawWire_Module_Core class not found");
        }

        // Check modules directory
        $modules_dir = $this->plugin_dir . 'modules';
        if (is_dir($modules_dir)) {
            $this->log("✓ Modules directory exists");

            // Check for simulator module
            $simulator_dir = $modules_dir . '/module-simulator';
            if (is_dir($simulator_dir)) {
                $this->log("✓ Module simulator directory found");

                $simulator_files = array('module.php', 'module.json');
                foreach ($simulator_files as $file) {
                    if (file_exists($simulator_dir . '/' . $file)) {
                        $this->log("✓ Simulator {$file} found");
                    } else {
                        $this->error("✗ Missing simulator {$file}");
                    }
                }
            } else {
                $this->error("✗ Module simulator directory not found");
            }
        } else {
            $this->error("✗ Modules directory not found");
        }

        $this->log("");
    }

    /**
     * Audit simulator module specifically
     */
    private function audit_simulator_module() {
        $this->log("🎭 Auditing Module Simulator");

        // Check simulator class
        if (class_exists('RawWire_Module_Simulator')) {
            $this->log("✓ RawWire_Module_Simulator class available");

            // Check if it implements the interface
            $reflection = new ReflectionClass('RawWire_Module_Simulator');
            if ($reflection->implementsInterface('RawWire_Module_Interface')) {
                $this->log("✓ Implements RawWire_Module_Interface correctly");
            } else {
                $this->error("✗ Does not implement RawWire_Module_Interface");
            }

            // Check required methods
            $required_methods = array('init', 'register_rest_routes', 'register_ajax_handlers', 'get_admin_panels', 'get_metadata', 'handle_rest_request', 'handle_ajax');
            foreach ($required_methods as $method) {
                if ($reflection->hasMethod($method)) {
                    $this->log("✓ Method {$method} implemented");
                } else {
                    $this->error("✗ Missing method: {$method}");
                }
            }
        } else {
            $this->error("✗ RawWire_Module_Simulator class not found");
        }

        // Check assets
        $assets = array(
            'css/simulator.css' => 'Simulator styles',
            'js/simulator.js' => 'Simulator JavaScript'
        );

        foreach ($assets as $asset => $description) {
            $path = $this->plugin_dir . $asset;
            if (file_exists($path)) {
                $this->log("✓ {$description} found");
            } else {
                $this->warning("⚠ {$description} not found: {$asset}");
            }
        }

        $this->log("");
    }

    /**
     * Security audit
     */
    private function audit_security() {
        $this->log("🔒 Security Audit");

        // Check for proper WordPress security practices
        $security_checks = array(
            'wp_enqueue_scripts' => 'Proper script enqueueing',
            'wp_create_nonce' => 'Nonce usage for AJAX',
            'current_user_can' => 'Permission checks',
            'sanitize_text_field' => 'Input sanitization'
        );

        $simulator_file = $this->plugin_dir . 'includes/class-module-simulator.php';
        if (file_exists($simulator_file)) {
            $content = file_get_contents($simulator_file);

            foreach ($security_checks as $check => $description) {
                if (strpos($content, $check) !== false) {
                    $this->log("✓ {$description} implemented");
                } else {
                    $this->warning("⚠ {$description} not found");
                }
            }
        }

        $this->log("");
    }

    /**
     * Performance audit
     */
    private function audit_performance() {
        $this->log("⚡ Performance Audit");

        // Check file sizes
        $files_to_check = array(
            'includes/class-module-simulator.php' => 'Simulator class',
            'css/simulator.css' => 'Simulator styles',
            'js/simulator.js' => 'Simulator JavaScript'
        );

        foreach ($files_to_check as $file => $description) {
            $path = $this->plugin_dir . $file;
            if (file_exists($path)) {
                $size = filesize($path);
                $size_kb = round($size / 1024, 2);
                if ($size_kb > 100) {
                    $this->warning("⚠ Large {$description} file: {$size_kb}KB");
                } else {
                    $this->log("✓ {$description} size: {$size_kb}KB");
                }
            }
        }

        $this->log("");
    }

    /**
     * Generate deployment report
     */
    private function generate_deployment_report() {
        $this->log("📊 Deployment Report");
        $this->log("===================");

        $error_count = count($this->errors);
        $warning_count = count($this->warnings);

        if ($error_count === 0 && $warning_count === 0) {
            $this->log("🎉 AUDIT PASSED - Ready for deployment!");
            $this->log("Score: 100/100");
        } elseif ($error_count === 0) {
            $score = max(0, 100 - ($warning_count * 5));
            $this->log("⚠️  AUDIT PASSED WITH WARNINGS");
            $this->log("Score: {$score}/100");
        } else {
            $score = max(0, 100 - ($error_count * 20) - ($warning_count * 5));
            $this->log("❌ AUDIT FAILED - Fix errors before deployment");
            $this->log("Score: {$score}/100");
        }

        if (!empty($this->errors)) {
            $this->log("\n🚨 ERRORS TO FIX:");
            foreach ($this->errors as $error) {
                $this->log("  - {$error}");
            }
        }

        if (!empty($this->warnings)) {
            $this->log("\n⚠️  WARNINGS TO REVIEW:");
            foreach ($this->warnings as $warning) {
                $this->log("  - {$warning}");
            }
        }

        $this->log("");
    }

    /**
     * Final deployment steps
     */
    private function final_deployment_steps() {
        $this->log("🚀 Final Deployment Steps");
        $this->log("========================");

        if (empty($this->errors)) {
            $this->log("1. ✅ Activate the RawWire Dashboard plugin");
            $this->log("2. ✅ The module simulator will auto-register");
            $this->log("3. ✅ Dashboard will be available with mock data");
            $this->log("4. ✅ Test all panels and functionality");
            $this->log("5. ✅ Replace simulator with real modules when ready");

            // Create deployment package
            $this->create_deployment_package();
        } else {
            $this->log("❌ Cannot proceed with deployment due to errors");
            $this->log("   Please fix all errors and re-run this audit");
        }
    }

    /**
     * Create deployment package
     */
    private function create_deployment_package() {
        $this->log("\n📦 Creating Deployment Package");

        $deploy_dir = $this->plugin_dir . '../raw-wire-dashboard-deploy';
        $source_dir = $this->plugin_dir;

        if (!is_dir($deploy_dir)) {
            mkdir($deploy_dir, 0755, true);
        }

        // Copy plugin files
        $this->copy_directory($source_dir, $deploy_dir);

        // Create deployment manifest
        $manifest = array(
            'name' => 'RawWire Dashboard with Module Simulator',
            'version' => '1.0.19-simulator',
            'description' => 'Dashboard with built-in simulator for testing',
            'deployment_date' => date('Y-m-d H:i:s'),
            'includes_simulator' => true,
            'ready_for_production' => empty($this->errors)
        );

        file_put_contents($deploy_dir . '/deployment-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Create README for deployment
        $readme = "# RawWire Dashboard Deployment Package

This package includes the RawWire Dashboard with a built-in module simulator for testing.

## Installation

1. Upload the `raw-wire-dashboard` folder to your WordPress plugins directory
2. Activate the plugin through the WordPress admin
3. The dashboard will be available with mock data for testing

## Features

- Complete dashboard interface
- Module simulator with mock data
- All panels functional for testing
- Secure API endpoints
- Comprehensive logging

## Next Steps

1. Test all dashboard functionality
2. Replace the simulator with real modules
3. Configure production settings
4. Deploy to production environment

## Audit Score: " . (empty($this->errors) ? 'PASSED' : 'FAILED') . "

Deployment Date: " . date('Y-m-d H:i:s') . "
";

        file_put_contents($deploy_dir . '/DEPLOYMENT_README.md', $readme);

        $this->log("✓ Deployment package created: {$deploy_dir}");
        $this->log("✓ Manifest and README generated");
    }

    /**
     * Copy directory recursively
     */
    private function copy_directory($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->copy_directory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }

    /**
     * Log a message
     */
    private function log($message) {
        echo $message . "\n";
        $this->deploy_log[] = $message;
    }

    /**
     * Log an error
     */
    private function error($message) {
        echo $message . "\n";
        $this->errors[] = $message;
        $this->deploy_log[] = $message;
    }

    /**
     * Log a warning
     */
    private function warning($message) {
        echo $message . "\n";
        $this->warnings[] = $message;
        $this->deploy_log[] = $message;
    }
}

// Run the deployment audit
if (defined('ABSPATH')) {
    $deploy = new RawWire_Dashboard_Deploy();
    $deploy->run_deployment_audit();
} else {
    echo "This script must be run within a WordPress environment.\n";
}