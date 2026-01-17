#!/bin/bash
# Quick visual check for progress bar functionality

echo "==================================="
echo "RawWire Workflow - Pre-Flight Check"
echo "==================================="
echo ""

echo "1. Checking WordPress container status..."
if docker ps | grep -q "raw-wire-equalizer-wordpress-1"; then
    echo "   ✓ Container is running"
else
    echo "   ✗ Container is NOT running"
    exit 1
fi

echo ""
echo "2. Checking plugin files..."
docker exec raw-wire-equalizer-wordpress-1 ls -la /var/www/html/wp-content/plugins/raw-wire-dashboard/services/ | grep -E "migration|scoring|scraper"

echo ""
echo "3. Checking database tables..."
docker exec raw-wire-equalizer-wordpress-1 php -r "
require_once '/var/www/html/wp-load.php';
global \$wpdb;
\$tables = [
    'candidates' => \$wpdb->prefix . 'rawwire_candidates',
    'archives' => \$wpdb->prefix . 'rawwire_archives',
    'content' => \$wpdb->prefix . 'rawwire_content',
];
foreach (\$tables as \$name => \$table) {
    \$count = \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$table}\");
    echo \"   - {\$name}: {\$count} records\n\";
}
"

echo ""
echo "4. Checking hooks..."
docker exec raw-wire-equalizer-wordpress-1 php -r "
require_once '/var/www/html/wp-load.php';
global \$wp_filter;
\$hooks = ['rawwire_scrape_complete', 'rawwire_content_approved'];
foreach (\$hooks as \$hook) {
    \$count = isset(\$wp_filter[\$hook]) ? count(\$wp_filter[\$hook]->callbacks) : 0;
    echo \"   - {\$hook}: {\$count} callback(s)\n\";
}
"

echo ""
echo "==================================="
echo "Pre-flight check complete!"
echo "==================================="
echo ""
echo "Ready to test workflow. Steps:"
echo "1. Open: http://localhost:8080/wp-admin"
echo "2. Go to: RawWire Dashboard"
echo "3. Clear all data from Settings"
echo "4. Click 'Sync Sources' button"
echo "5. Watch progress bar animate through stages"
echo "6. Check Candidates → Archives → Approvals → Content"
echo ""
