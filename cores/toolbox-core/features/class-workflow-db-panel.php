<?php
/**
 * Workflow DB Panel - View all workflow database tables
 * 
 * Read-only view of candidates, approvals, and archives tables
 * for workflow monitoring and fine-tuning.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.23
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_Workflow_DB_Panel
 */
class RawWire_Workflow_DB_Panel {

    /**
     * Singleton instance
     * @var RawWire_Workflow_DB_Panel|null
     */
    private static $instance = null;

    /**
     * Workflow tables configuration
     * @var array
     */
    private $tables = array();

    /**
     * Get singleton instance
     * 
     * @return RawWire_Workflow_DB_Panel
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_submenu'], 35);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rawwire_workflow_db_refresh', [$this, 'ajax_refresh_table']);
        add_action('wp_ajax_rawwire_workflow_db_delete', [$this, 'ajax_delete_record']);
        add_action('wp_ajax_rawwire_workflow_db_truncate', [$this, 'ajax_truncate_table']);
        add_action('wp_ajax_rawwire_workflow_db_approve', [$this, 'ajax_approve_record']);
        add_action('wp_ajax_rawwire_workflow_db_move_to_releases', [$this, 'ajax_move_to_releases']);
        
        // Full 5-table workflow: candidates → approvals → content → releases → (published)
        $this->tables = array(
            'candidates' => array(
                'label' => __('Candidates', 'raw-wire-dashboard'),
                'description' => __('Items awaiting scoring and pipeline processing', 'raw-wire-dashboard'),
                'color' => '#2271b1',
                'icon' => 'dashicons-clipboard',
            ),
            'approvals' => array(
                'label' => __('Approvals', 'raw-wire-dashboard'),
                'description' => __('Top-scored items pending human review', 'raw-wire-dashboard'),
                'color' => '#00a32a',
                'icon' => 'dashicons-yes-alt',
                'actions' => array('approve'),
            ),
            'content' => array(
                'label' => __('Content', 'raw-wire-dashboard'),
                'description' => __('Approved items ready for AI content generation', 'raw-wire-dashboard'),
                'color' => '#8b5cf6',
                'icon' => 'dashicons-media-document',
                'actions' => array('move_to_releases'),
            ),
            'releases' => array(
                'label' => __('Releases', 'raw-wire-dashboard'),
                'description' => __('Generated content pending final approval for publishing', 'raw-wire-dashboard'),
                'color' => '#e11d48',
                'icon' => 'dashicons-megaphone',
            ),
            'archives' => array(
                'label' => __('Archives', 'raw-wire-dashboard'),
                'description' => __('Lower-scored items for dedup reference and analysis', 'raw-wire-dashboard'),
                'color' => '#6b7280',
                'icon' => 'dashicons-archive',
            ),
        );
    }

    /**
     * Add submenu page
     */
    public function add_submenu() {
        add_submenu_page(
            'raw-wire-dashboard',
            __('Workflow DB', 'raw-wire-dashboard'),
            __('Workflow DB', 'raw-wire-dashboard'),
            'manage_options',
            'rawwire-workflow-db',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue assets
     * 
     * @param string $hook Current page hook
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'rawwire-workflow-db') === false) {
            return;
        }

        wp_enqueue_style('rawwire-admin');
        wp_enqueue_script('rawwire-admin');
    }

    /**
     * Get table data with pagination
     * 
     * @param string $table_key Table key (candidates, approvals, archives)
     * @param int    $page      Page number (1-indexed)
     * @param int    $per_page  Items per page
     * @return array
     */
    private function get_table_data($table_key, $page = 1, $per_page = 20) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_' . $table_key;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array(
                'rows' => array(),
                'total' => 0,
                'columns' => array(),
            );
        }
        
        // Get column info
        $columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
        
        // Get total count
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        
        // Get paginated rows
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        
        return array(
            'rows' => $rows,
            'total' => $total,
            'columns' => $columns,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        );
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        global $wpdb;
        
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        ?>
        <div class="wrap rawwire-workflow-db">
            <h1><?php _e('Workflow Database', 'raw-wire-dashboard'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=rawwire-workflow-db&tab=overview'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Overview', 'raw-wire-dashboard'); ?>
                </a>
                <?php foreach ($this->tables as $key => $config): ?>
                    <a href="<?php echo admin_url('admin.php?page=rawwire-workflow-db&tab=' . $key); ?>" 
                       class="nav-tab <?php echo $current_tab === $key ? 'nav-tab-active' : ''; ?>"
                       style="border-bottom-color: <?php echo esc_attr($config['color']); ?>;">
                        <?php echo esc_html($config['label']); ?>
                        <span class="count">(<?php echo $this->get_table_count($key); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <?php wp_nonce_field('rawwire_workflow_db', 'workflow_db_nonce'); ?>
            
            <?php if ($current_tab === 'overview'): ?>
                <?php $this->render_overview(); ?>
            <?php else: ?>
                <?php $this->render_table_view($current_tab, $current_page); ?>
            <?php endif; ?>
        </div>
        
        <style>
            .rawwire-workflow-db .nav-tab .count {
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                margin-left: 5px;
            }
            .rawwire-workflow-db .nav-tab-active .count {
                background: #2271b1;
                color: #fff;
            }
            .rawwire-overview-grid {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 20px;
                margin: 20px 0;
            }
            .rawwire-overview-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                border-left: 4px solid;
            }
            .rawwire-overview-card h2 {
                margin: 0 0 5px 0;
                font-size: 16px;
            }
            .rawwire-overview-card .count {
                font-size: 48px;
                font-weight: 600;
                margin: 10px 0;
            }
            .rawwire-overview-card .description {
                color: #666;
                font-size: 13px;
            }
            .rawwire-overview-card .actions {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }
            .rawwire-table-panel {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                margin: 20px 0;
            }
            .rawwire-table-header {
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .rawwire-table-header h2 {
                margin: 0;
            }
            .rawwire-table-actions {
                display: flex;
                gap: 10px;
            }
            .rawwire-db-table {
                width: 100%;
                border-collapse: collapse;
            }
            .rawwire-db-table th,
            .rawwire-db-table td {
                padding: 10px 15px;
                text-align: left;
                border-bottom: 1px solid #eee;
                font-size: 13px;
            }
            .rawwire-db-table th {
                background: #f9f9f9;
                font-weight: 600;
                position: sticky;
                top: 0;
            }
            .rawwire-db-table tr:hover {
                background: #f5f5f5;
            }
            .rawwire-db-table .col-id {
                width: 60px;
            }
            .rawwire-db-table .col-title {
                max-width: 250px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .rawwire-db-table .col-content {
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .rawwire-db-table .col-link {
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .rawwire-db-table .col-score {
                width: 70px;
                text-align: center;
            }
            .rawwire-db-table .col-date {
                width: 140px;
            }
            .rawwire-db-table .col-actions {
                width: 80px;
                text-align: center;
            }
            .rawwire-db-table .score-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-weight: 600;
            }
            .rawwire-db-table .score-high {
                background: #d4edda;
                color: #155724;
            }
            .rawwire-db-table .score-medium {
                background: #fff3cd;
                color: #856404;
            }
            .rawwire-db-table .score-low {
                background: #f8d7da;
                color: #721c24;
            }
            .rawwire-pagination {
                padding: 15px 20px;
                border-top: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .rawwire-pagination-info {
                color: #666;
            }
            .rawwire-pagination-links a,
            .rawwire-pagination-links span {
                padding: 5px 10px;
                margin: 0 2px;
                border: 1px solid #ccc;
                border-radius: 3px;
                text-decoration: none;
            }
            .rawwire-pagination-links a:hover {
                background: #f0f0f0;
            }
            .rawwire-pagination-links .current {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }
            .rawwire-empty-table {
                padding: 40px;
                text-align: center;
                color: #666;
            }
            .rawwire-table-scroll {
                max-height: 600px;
                overflow-y: auto;
            }
            @media (max-width: 1600px) {
                .rawwire-overview-grid {
                    grid-template-columns: repeat(3, 1fr);
                }
            }
            @media (max-width: 1200px) {
                .rawwire-overview-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
            @media (max-width: 768px) {
                .rawwire-overview-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Refresh table
            $('.rawwire-refresh-table').on('click', function() {
                location.reload();
            });
            
            // Delete record
            $('.rawwire-delete-record').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Delete this record?', 'raw-wire-dashboard')); ?>')) {
                    return;
                }
                
                var $btn = $(this);
                var table = $btn.data('table');
                var id = $btn.data('id');
                
                $.post(ajaxurl, {
                    action: 'rawwire_workflow_db_delete',
                    nonce: $('#workflow_db_nonce').val(),
                    table: table,
                    id: id
                }, function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data?.message || 'Error deleting record');
                    }
                });
            });
            
            // Truncate table
            $('.rawwire-truncate-table').on('click', function() {
                var table = $(this).data('table');
                var label = $(this).data('label');
                
                if (!confirm('<?php echo esc_js(__('Delete ALL records from', 'raw-wire-dashboard')); ?> ' + label + '?')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'rawwire_workflow_db_truncate',
                    nonce: $('#workflow_db_nonce').val(),
                    table: table
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data?.message || 'Error truncating table');
                    }
                });
            });
            
            // Approve record (move from approvals to content)
            $(document).on('click', '.rawwire-approve-record', function() {
                if (!confirm('<?php echo esc_js(__('Approve this record and move to Content for AI generation?', 'raw-wire-dashboard')); ?>')) {
                    return;
                }
                
                var $btn = $(this);
                var id = $btn.data('id');
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Moving...', 'raw-wire-dashboard')); ?>');
                
                $.post(ajaxurl, {
                    action: 'rawwire_workflow_db_approve',
                    nonce: $('#workflow_db_nonce').val(),
                    id: id
                }, function(response) {
                    if (response.success) {
                        $btn.closest('tr').css('background', '#d4edda').fadeOut(500, function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data?.message || 'Error approving record');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Approve', 'raw-wire-dashboard')); ?>');
                    }
                });
            });
            
            // Move to releases (from content to releases)
            $(document).on('click', '.rawwire-move-to-releases', function() {
                if (!confirm('<?php echo esc_js(__('Move this content to Releases for final approval?', 'raw-wire-dashboard')); ?>')) {
                    return;
                }
                
                var $btn = $(this);
                var id = $btn.data('id');
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Moving...', 'raw-wire-dashboard')); ?>');
                
                $.post(ajaxurl, {
                    action: 'rawwire_workflow_db_move_to_releases',
                    nonce: $('#workflow_db_nonce').val(),
                    id: id
                }, function(response) {
                    if (response.success) {
                        $btn.closest('tr').css('background', '#d4edda').fadeOut(500, function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data?.message || 'Error moving to releases');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('To Releases', 'raw-wire-dashboard')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render overview tab
     */
    private function render_overview() {
        ?>
        <div class="rawwire-overview-grid">
            <?php foreach ($this->tables as $key => $config): 
                $count = $this->get_table_count($key);
            ?>
            <div class="rawwire-overview-card" style="border-left-color: <?php echo esc_attr($config['color']); ?>;">
                <h2><?php echo esc_html($config['label']); ?></h2>
                <div class="count" style="color: <?php echo esc_attr($config['color']); ?>;">
                    <?php echo number_format($count); ?>
                </div>
                <div class="description"><?php echo esc_html($config['description']); ?></div>
                <div class="actions">
                    <a href="<?php echo admin_url('admin.php?page=rawwire-workflow-db&tab=' . $key); ?>" class="button">
                        <?php _e('View Records', 'raw-wire-dashboard'); ?>
                    </a>
                    <?php if ($count > 0): ?>
                    <button type="button" class="button rawwire-truncate-table" 
                            data-table="<?php echo esc_attr($key); ?>"
                            data-label="<?php echo esc_attr($config['label']); ?>">
                        <?php _e('Clear All', 'raw-wire-dashboard'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="rawwire-overview-summary">
            <h3><?php _e('Workflow Summary', 'raw-wire-dashboard'); ?></h3>
            <?php $this->render_workflow_stats(); ?>
        </div>
        <?php
    }

    /**
     * Render workflow statistics
     */
    private function render_workflow_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Get stats for each table
        foreach ($this->tables as $key => $config) {
            $table = $wpdb->prefix . 'rawwire_' . $key;
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                continue;
            }
            
            $stats[$key] = array(
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
                'avg_score' => (float) $wpdb->get_var("SELECT AVG(score) FROM {$table}"),
                'sources' => $wpdb->get_results(
                    "SELECT source, COUNT(*) as count FROM {$table} GROUP BY source ORDER BY count DESC LIMIT 5",
                    ARRAY_A
                ),
                'recent' => $wpdb->get_var(
                    "SELECT created_at FROM {$table} ORDER BY created_at DESC LIMIT 1"
                ),
            );
        }
        
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Table', 'raw-wire-dashboard'); ?></th>
                    <th><?php _e('Records', 'raw-wire-dashboard'); ?></th>
                    <th><?php _e('Avg Score', 'raw-wire-dashboard'); ?></th>
                    <th><?php _e('Top Sources', 'raw-wire-dashboard'); ?></th>
                    <th><?php _e('Last Updated', 'raw-wire-dashboard'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->tables as $key => $config): 
                    $data = $stats[$key] ?? array('count' => 0, 'avg_score' => 0, 'sources' => array(), 'recent' => null);
                ?>
                <tr>
                    <td><strong style="color: <?php echo esc_attr($config['color']); ?>;"><?php echo esc_html($config['label']); ?></strong></td>
                    <td><?php echo number_format($data['count']); ?></td>
                    <td><?php echo $data['avg_score'] ? number_format($data['avg_score'], 1) : '-'; ?></td>
                    <td>
                        <?php 
                        if (!empty($data['sources'])) {
                            $source_list = array_map(function($s) {
                                return esc_html($s['source']) . ' (' . $s['count'] . ')';
                            }, array_slice($data['sources'], 0, 3));
                            echo implode(', ', $source_list);
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($data['recent']) {
                            echo human_time_diff(strtotime($data['recent'])) . ' ' . __('ago', 'raw-wire-dashboard');
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render table view tab
     * 
     * @param string $table_key  Table key
     * @param int    $page       Current page
     */
    private function render_table_view($table_key, $page = 1) {
        if (!isset($this->tables[$table_key])) {
            echo '<p>' . __('Invalid table', 'raw-wire-dashboard') . '</p>';
            return;
        }
        
        $config = $this->tables[$table_key];
        $data = $this->get_table_data($table_key, $page, 25);
        
        ?>
        <div class="rawwire-table-panel">
            <div class="rawwire-table-header">
                <h2 style="color: <?php echo esc_attr($config['color']); ?>;">
                    <?php echo esc_html($config['label']); ?>
                    <span style="font-weight: normal; color: #666; font-size: 14px;">
                        — <?php echo esc_html($config['description']); ?>
                    </span>
                </h2>
                <div class="rawwire-table-actions">
                    <button type="button" class="button rawwire-refresh-table">
                        <?php _e('Refresh', 'raw-wire-dashboard'); ?>
                    </button>
                    <?php if ($data['total'] > 0): ?>
                    <button type="button" class="button rawwire-truncate-table" 
                            data-table="<?php echo esc_attr($table_key); ?>"
                            data-label="<?php echo esc_attr($config['label']); ?>">
                        <?php _e('Clear All', 'raw-wire-dashboard'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($data['rows'])): ?>
                <div class="rawwire-empty-table">
                    <p><?php _e('No records in this table.', 'raw-wire-dashboard'); ?></p>
                </div>
            <?php else: ?>
                <div class="rawwire-table-scroll">
                    <table class="rawwire-db-table">
                        <thead>
                            <tr>
                                <th class="col-id"><?php _e('ID', 'raw-wire-dashboard'); ?></th>
                                <th class="col-title"><?php _e('Title', 'raw-wire-dashboard'); ?></th>
                                <th class="col-content"><?php _e('Content', 'raw-wire-dashboard'); ?></th>
                                <th class="col-link"><?php _e('Link', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Source', 'raw-wire-dashboard'); ?></th>
                                <th class="col-score"><?php _e('Score', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Reasoning', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Status', 'raw-wire-dashboard'); ?></th>
                                <th class="col-date"><?php _e('Created', 'raw-wire-dashboard'); ?></th>
                                <th class="col-actions"><?php _e('Actions', 'raw-wire-dashboard'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['rows'] as $row): ?>
                            <tr>
                                <td class="col-id"><?php echo esc_html($row['id']); ?></td>
                                <td class="col-title" title="<?php echo esc_attr($row['title'] ?? ''); ?>">
                                    <?php echo esc_html($row['title'] ?? ''); ?>
                                </td>
                                <td class="col-content" title="<?php echo esc_attr(substr($row['content'] ?? '', 0, 200)); ?>">
                                    <?php echo esc_html(substr($row['content'] ?? '', 0, 50)); ?>
                                    <?php if (strlen($row['content'] ?? '') > 50) echo '...'; ?>
                                </td>
                                <td class="col-link">
                                    <?php if (!empty($row['link'])): ?>
                                        <a href="<?php echo esc_url($row['link']); ?>" target="_blank" title="<?php echo esc_attr($row['link']); ?>">
                                            <?php echo esc_html(substr($row['link'], 0, 30)); ?>...
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($row['source'] ?? ''); ?></td>
                                <td class="col-score">
                                    <?php 
                                    $score = floatval($row['score'] ?? 0);
                                    $score_class = $score >= 70 ? 'score-high' : ($score >= 40 ? 'score-medium' : 'score-low');
                                    ?>
                                    <span class="score-badge <?php echo $score_class; ?>">
                                        <?php echo number_format($score, 0); ?>
                                    </span>
                                </td>
                                <td title="<?php echo esc_attr($row['reasoning'] ?? $row['ai_reason'] ?? ''); ?>">
                                    <?php 
                                    $reasoning = $row['reasoning'] ?? $row['ai_reason'] ?? '';
                                    echo esc_html(substr($reasoning, 0, 40));
                                    if (strlen($reasoning) > 40) echo '...';
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = $row['status'] ?? '';
                                    $result = $row['result'] ?? '';
                                    echo esc_html($status ?: $result ?: '-');
                                    ?>
                                </td>
                                <td class="col-date">
                                    <?php echo esc_html($row['created_at'] ?? ''); ?>
                                </td>
                                <td class="col-actions">
                                    <?php if ($table_key === 'approvals'): ?>
                                    <button type="button" class="button button-small button-primary rawwire-approve-record" 
                                            data-id="<?php echo esc_attr($row['id']); ?>"
                                            title="<?php echo esc_attr__('Approve and move to Content for AI generation', 'raw-wire-dashboard'); ?>">
                                        <?php _e('Approve', 'raw-wire-dashboard'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($table_key === 'content'): ?>
                                    <button type="button" class="button button-small button-primary rawwire-move-to-releases" 
                                            data-id="<?php echo esc_attr($row['id']); ?>"
                                            title="<?php echo esc_attr__('Move to Releases for final approval', 'raw-wire-dashboard'); ?>">
                                        <?php _e('Release', 'raw-wire-dashboard'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small rawwire-delete-record" 
                                            data-table="<?php echo esc_attr($table_key); ?>"
                                            data-id="<?php echo esc_attr($row['id']); ?>">
                                        <?php _e('Delete', 'raw-wire-dashboard'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($data['total_pages'] > 1): ?>
                <div class="rawwire-pagination">
                    <div class="rawwire-pagination-info">
                        <?php printf(
                            __('Showing %d-%d of %d records', 'raw-wire-dashboard'),
                            (($page - 1) * $data['per_page']) + 1,
                            min($page * $data['per_page'], $data['total']),
                            $data['total']
                        ); ?>
                    </div>
                    <div class="rawwire-pagination-links">
                        <?php
                        $base_url = admin_url('admin.php?page=rawwire-workflow-db&tab=' . $table_key);
                        
                        if ($page > 1) {
                            echo '<a href="' . esc_url($base_url . '&paged=' . ($page - 1)) . '">&laquo; ' . __('Prev', 'raw-wire-dashboard') . '</a>';
                        }
                        
                        for ($i = max(1, $page - 2); $i <= min($data['total_pages'], $page + 2); $i++) {
                            if ($i == $page) {
                                echo '<span class="current">' . $i . '</span>';
                            } else {
                                echo '<a href="' . esc_url($base_url . '&paged=' . $i) . '">' . $i . '</a>';
                            }
                        }
                        
                        if ($page < $data['total_pages']) {
                            echo '<a href="' . esc_url($base_url . '&paged=' . ($page + 1)) . '">' . __('Next', 'raw-wire-dashboard') . ' &raquo;</a>';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get table record count
     * 
     * @param string $table_key Table key
     * @return int
     */
    private function get_table_count($table_key) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_' . $table_key;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return 0;
        }
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * AJAX: Refresh table data
     */
    public function ajax_refresh_table() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_workflow_db')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $table_key = sanitize_key($_POST['table'] ?? '');
        $page = max(1, intval($_POST['page'] ?? 1));
        
        if (!isset($this->tables[$table_key])) {
            wp_send_json_error(['message' => 'Invalid table']);
        }
        
        $data = $this->get_table_data($table_key, $page);
        wp_send_json_success($data);
    }

    /**
     * AJAX: Delete record
     */
    public function ajax_delete_record() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_workflow_db')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $table_key = sanitize_key($_POST['table'] ?? '');
        $id = intval($_POST['id'] ?? 0);
        
        if (!isset($this->tables[$table_key]) || !$id) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_' . $table_key;
        
        $result = $wpdb->delete($table, ['id' => $id], ['%d']);
        
        if ($result) {
            wp_send_json_success(['message' => 'Record deleted']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete record']);
        }
    }

    /**
     * AJAX: Truncate table
     */
    public function ajax_truncate_table() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_workflow_db')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $table_key = sanitize_key($_POST['table'] ?? '');
        
        if (!isset($this->tables[$table_key])) {
            wp_send_json_error(['message' => 'Invalid table']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_' . $table_key;
        
        $wpdb->query("TRUNCATE TABLE {$table}");
        
        wp_send_json_success(['message' => 'Table cleared']);
    }

    /**
     * AJAX: Approve record (move from approvals to content)
     * This triggers the content generation workflow
     */
    public function ajax_approve_record() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_workflow_db')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error(['message' => 'Invalid record ID']);
        }
        
        global $wpdb;
        $approvals_table = $wpdb->prefix . 'rawwire_approvals';
        $content_table = $wpdb->prefix . 'rawwire_content';
        
        // Get the record from approvals
        $record = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$approvals_table} WHERE id = %d", $id),
            ARRAY_A
        );
        
        if (!$record) {
            wp_send_json_error(['message' => 'Record not found']);
        }
        
        // Generate summary for generational AI (50-200 words target)
        $summary = $this->generate_summary_for_ai($record);
        
        // Prepare data for content table
        $current_user = wp_get_current_user();
        $content_data = array(
            'title'            => $record['title'],
            'content'          => $record['content'],
            'summary'          => $summary,
            'link'             => $record['link'] ?? '',
            'source'           => $record['source'],
            'copyright_status' => $record['copyright_status'] ?? 'unknown',
            'score'            => $record['score'] ?? 0,
            'ai_reason'        => $record['reasoning'] ?? $record['ai_reason'] ?? '',
            'approved_by'      => $current_user->ID,
            'approved_at'      => current_time('mysql'),
            'status'           => 'pending_generation',
            'created_at'       => current_time('mysql'),
        );
        
        // Insert into content table
        $result = $wpdb->insert($content_table, $content_data);
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to move record to content: ' . $wpdb->last_error]);
        }
        
        $new_id = $wpdb->insert_id;
        
        // Delete from approvals
        $wpdb->delete($approvals_table, ['id' => $id], ['%d']);
        
        // Log the approval
        error_log(sprintf(
            '[RawWire Workflow] Record #%d approved by %s, moved to content #%d: %s',
            $id,
            $current_user->user_login,
            $new_id,
            $record['title']
        ));
        
        wp_send_json_success([
            'message' => 'Record approved and moved to Content',
            'new_id' => $new_id
        ]);
    }

    /**
     * AJAX: Move content to releases (after AI generation)
     */
    public function ajax_move_to_releases() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_workflow_db')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error(['message' => 'Invalid record ID']);
        }
        
        global $wpdb;
        $content_table = $wpdb->prefix . 'rawwire_content';
        $releases_table = $wpdb->prefix . 'rawwire_releases';
        
        // Get the record from content
        $record = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$content_table} WHERE id = %d", $id),
            ARRAY_A
        );
        
        if (!$record) {
            wp_send_json_error(['message' => 'Record not found']);
        }
        
        // Prepare data for releases table
        $release_data = array(
            'title'             => $record['title'],
            'original_content'  => $record['content'],
            'generated_content' => $record['ai_analysis'] ?? '', // AI-generated content if available
            'link'              => $record['link'] ?? '',
            'source'            => $record['source'],
            'copyright_status'  => $record['copyright_status'] ?? 'unknown',
            'score'             => $record['score'] ?? 0,
            'status'            => 'pending_review',
            'approved_at'       => $record['approved_at'] ?? current_time('mysql'),
            'generated_at'      => current_time('mysql'),
            'created_at'        => current_time('mysql'),
        );
        
        // Insert into releases table
        $result = $wpdb->insert($releases_table, $release_data);
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to move record to releases: ' . $wpdb->last_error]);
        }
        
        $new_id = $wpdb->insert_id;
        
        // Update content status instead of deleting (keep history)
        $wpdb->update(
            $content_table,
            ['status' => 'released', 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        
        // Log the release
        error_log(sprintf(
            '[RawWire Workflow] Content #%d moved to releases #%d: %s',
            $id,
            $new_id,
            $record['title']
        ));
        
        wp_send_json_success([
            'message' => 'Content moved to Releases for final approval',
            'new_id' => $new_id
        ]);
    }

    /**
     * Generate a summary (50-200 words) for generational AI from record data
     * 
     * @param array $record The approval record
     * @return string Summary text for AI content generation
     */
    private function generate_summary_for_ai(array $record): string {
        $title = $record['title'] ?? '';
        $content = $record['content'] ?? '';
        $source = $record['source'] ?? '';
        $link = $record['link'] ?? '';
        $reasoning = $record['reasoning'] ?? $record['ai_reason'] ?? '';
        
        // Word count helper
        $word_count = function($text) {
            return str_word_count(strip_tags($text));
        };
        
        // If content already has 50-200 words, use it directly
        $content_words = $word_count($content);
        if ($content_words >= 50 && $content_words <= 200) {
            return $content;
        }
        
        // Build a comprehensive summary
        $parts = [];
        
        // Add title context
        if (!empty($title)) {
            $parts[] = "Title: " . $title;
        }
        
        // Add source context
        if (!empty($source)) {
            $parts[] = "Source: " . ucfirst($source);
        }
        
        // Add main content (truncate or pad as needed)
        if (!empty($content)) {
            if ($content_words > 200) {
                // Truncate to ~180 words, leaving room for context
                $words = explode(' ', strip_tags($content));
                $content = implode(' ', array_slice($words, 0, 180)) . '...';
            }
            $parts[] = $content;
        }
        
        // Add AI scoring reasoning for context
        if (!empty($reasoning)) {
            $reasoning_words = $word_count($reasoning);
            if ($reasoning_words <= 50) {
                $parts[] = "Relevance: " . $reasoning;
            }
        }
        
        // Add link for reference
        if (!empty($link)) {
            $parts[] = "Reference: " . $link;
        }
        
        $summary = implode("\n\n", $parts);
        
        // Ensure minimum length (pad with context if too short)
        $summary_words = $word_count($summary);
        if ($summary_words < 50) {
            $summary .= "\n\nThis content has been approved for AI-powered social media post generation. ";
            $summary .= "The generational AI should create engaging posts that highlight the key value proposition ";
            $summary .= "and encourage audience interaction.";
        }
        
        return $summary;
    }
}

// Initialize
RawWire_Workflow_DB_Panel::get_instance();
