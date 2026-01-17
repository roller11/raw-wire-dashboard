<?php
/**
 * Run migrations
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
require_once __DIR__ . '/class-migration-service.php';

echo "Running migrations...\n";
RawWire\Dashboard\Services\Migration_Service::run_migrations();
echo "Done!\n";
