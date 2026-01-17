<?php
/**
 * Code Validation Script
 * 
 * Validates PHP code quality and standards for Raw Wire Dashboard
 */

// ANSI color codes for terminal output
define('ANSI_RED', "\033[31m");
define('ANSI_GREEN', "\033[32m");
define('ANSI_YELLOW', "\033[33m");
define('ANSI_BLUE', "\033[34m");
define('ANSI_RESET', "\033[0m");

// Configuration
$base_dir = dirname(__DIR__);
$src_dirs = [
    $base_dir . '/includes',
    $base_dir . '/admin',
    $base_dir . '/public',
];

// Logging function
function log_step($message, $status = 'info') {
    switch ($status) {
        case 'success':
            $prefix = ANSI_GREEN . '[✓]' . ANSI_RESET;
            break;
        case 'error':
            $prefix = ANSI_RED . '[✗]' . ANSI_RESET;
            break;
        case 'warning':
            $prefix = ANSI_YELLOW . '[!]' . ANSI_RESET;
            break;
        default:
            $prefix = ANSI_BLUE . '[•]' . ANSI_RESET;
            break;
    }
    echo "$prefix $message\n";
}

// Check PHP version
log_step("Checking PHP version...");
$php_version = phpversion();
if (version_compare($php_version, '7.4', '<')) {
    log_step("PHP version $php_version is below minimum required 7.4", 'error');
    exit(1);
}
log_step("PHP version $php_version OK", 'success');

// Check for syntax errors
log_step("Checking PHP syntax...");
$has_syntax_errors = false;
foreach ($src_dirs as $dir) {
    if (!is_dir($dir)) {
        log_step("Directory not found: $dir", 'warning');
        continue;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        
        $filepath = $file->getPathname();
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($filepath) . " 2>&1", $output, $return_var);
        
        if ($return_var !== 0) {
            log_step("Syntax error in $filepath", 'error');
            foreach ($output as $line) {
                echo "  $line\n";
            }
            $has_syntax_errors = true;
        }
    }
}

if ($has_syntax_errors) {
    log_step("Syntax validation failed", 'error');
    exit(1);
}
log_step("No syntax errors found", 'success');

// Check for PHPCS (if available)
log_step("Checking for PHPCS...");
$phpcs_path = exec("which phpcs 2>/dev/null");
if (empty($phpcs_path)) {
    log_step("PHPCS not found, skipping code standards check", 'warning');
} else {
    log_step("Running PHPCS...");
    $phpcs_standard = $base_dir . '/phpcs.xml';
    if (!file_exists($phpcs_standard)) {
        $phpcs_standard = 'WordPress';
    }
    
    $phpcs_cmd = sprintf(
        "%s --standard=%s %s",
        escapeshellarg($phpcs_path),
        escapeshellarg($phpcs_standard),
        implode(' ', array_map('escapeshellarg', $src_dirs))
    );
    
    $output = [];
    $return_var = 0;
    exec($phpcs_cmd . " 2>&1", $output, $return_var);
    
    if ($return_var !== 0) {
        log_step("Code standards issues found", 'warning');
        foreach ($output as $line) {
            echo "  $line\n";
        }
    } else {
        log_step("Code standards check passed", 'success');
    }
}

log_step("Validation complete!", 'success');
exit(0);
