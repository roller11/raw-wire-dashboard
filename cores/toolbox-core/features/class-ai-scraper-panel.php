<?php
/**
 * AI Scraper Admin Panel
 * 
 * Admin interface for managing AI-powered semantic scraping.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.21
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_AI_Scraper_Panel
 */
class RawWire_AI_Scraper_Panel {

    /**
     * Singleton instance
     * @var RawWire_AI_Scraper_Panel|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * 
     * @return RawWire_AI_Scraper_Panel
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_submenu'], 25);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rawwire_ai_scraper_config', [$this, 'ajax_save_config']);
        add_action('wp_ajax_rawwire_ai_scraper_test', [$this, 'ajax_test_source']);
        add_action('wp_ajax_rawwire_ai_scraper_preview', [$this, 'ajax_preview_analysis']);
        add_action('wp_ajax_rawwire_save_scoring_weights', [$this, 'ajax_save_scoring_weights']);
    }

    /**
     * Add submenu page
     */
    public function add_submenu() {
        add_submenu_page(
            'raw-wire-dashboard',
            __('AI Scraper', 'raw-wire-dashboard'),
            __('AI Scraper', 'raw-wire-dashboard'),
            'manage_options',
            'rawwire-ai-scraper',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue assets
     * 
     * @param string $hook Current page hook
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'rawwire-ai-scraper') === false) {
            return;
        }

        wp_enqueue_style('rawwire-admin');
        wp_enqueue_script('rawwire-admin');
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        $scraper = function_exists('rawwire_ai_scraper') ? rawwire_ai_scraper() : null;
        
        // Get AI-native sources (require semantic analysis)
        $ai_sources = $scraper ? $scraper->get_available_sources() : [];
        
        // Get Scraper Toolkit sources (user-configured) - use central getter for fresh data
        $toolkit_sources = class_exists('RawWire_Scraper_Settings') 
            ? RawWire_Scraper_Settings::get_sources() 
            : [];
        
        $concepts = $scraper ? $scraper->get_concepts() : [];
        $config = $this->get_config();
        
        // Workflow tables for output destination
        $workflow_tables = [
            'candidates' => __('Candidates (New Items)', 'raw-wire-dashboard'),
            'approvals'  => __('Approvals (Review Queue)', 'raw-wire-dashboard'),
            'content'    => __('Content (Drafts)', 'raw-wire-dashboard'),
            'releases'   => __('Releases (Scheduled)', 'raw-wire-dashboard'),
            'published'  => __('Published (Live)', 'raw-wire-dashboard'),
            'archives'   => __('Archives (Historical)', 'raw-wire-dashboard'),
        ];
        ?>
        <div class="wrap rawwire-dashboard">
            <div class="rawwire-hero">
                <div class="rawwire-hero-content">
                    <span class="eyebrow"><?php _e('Data Collection', 'raw-wire-dashboard'); ?></span>
                    <h1>
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('AI-Powered Semantic Scraper', 'raw-wire-dashboard'); ?>
                    </h1>
                    <p class="lede"><?php _e('Intelligent content discovery using AI to analyze abstract concepts like "shocking", "controversial", or "high impact".', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-hero-actions"></div>
            </div>
            
            <div class="rawwire-alert rawwire-alert-info">
                <p>
                    <strong><?php _e('Intelligent Content Discovery', 'raw-wire-dashboard'); ?></strong><br>
                    <?php _e('This scraper uses AI to analyze content for abstract concepts like "shocking", "controversial", or "high impact" — even when those exact words don\'t appear in the text.', 'raw-wire-dashboard'); ?>
                </p>
                <p>
                    <em><?php _e('Sources: AI-native sources below use built-in government API integrations. Additional sources can be configured in the', 'raw-wire-dashboard'); ?>
                    <a href="<?php echo admin_url('admin.php?page=rawwire-scraper-settings'); ?>"><?php _e('Scraper Toolkit', 'raw-wire-dashboard'); ?></a>.</em>
                </p>
            </div>

            <div class="rawwire-admin-grid">
                <!-- Run Scraper Panel -->
                <div class="rawwire-admin-card">
                    <h2><?php _e('Run AI Scraper', 'raw-wire-dashboard'); ?></h2>
                    
                    <form id="rawwire-ai-scraper-form" class="rawwire-form-horizontal">
                        <?php wp_nonce_field('rawwire_ai_scraper', '_wpnonce'); ?>
                        
                        <div class="rawwire-form-row">
                            <label for="source_type"><?php _e('Source', 'raw-wire-dashboard'); ?></label>
                            <select name="source_type" id="source_type">
                                <optgroup label="<?php _e('AI-Native Sources (Built-in APIs)', 'raw-wire-dashboard'); ?>">
                                    <?php foreach ($ai_sources as $key => $source): ?>
                                        <option value="<?php echo esc_attr($key); ?>" 
                                                data-requires-key="<?php echo $source['requires_key'] ? '1' : '0'; ?>"
                                                data-source-group="ai">
                                            <?php echo esc_html($source['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($toolkit_sources)): ?>
                                <optgroup label="<?php _e('Scraper Toolkit Sources', 'raw-wire-dashboard'); ?>">
                                    <?php foreach ($toolkit_sources as $source): ?>
                                        <option value="toolkit_<?php echo esc_attr($source['id'] ?? sanitize_title($source['name'])); ?>" 
                                                data-requires-key="0"
                                                data-source-group="toolkit"
                                                data-url="<?php echo esc_attr($source['url'] ?? ''); ?>">
                                            <?php echo esc_html($source['name'] ?? 'Unknown'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                            <span class="rawwire-form-description" id="source-description"></span>
                        </div>
                        
                        <div class="rawwire-form-row">
                            <label for="output_table"><?php _e('Output To', 'raw-wire-dashboard'); ?></label>
                            <select name="output_table" id="output_table">
                                <?php foreach ($workflow_tables as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($key, 'candidates'); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rawwire-form-row">
                            <label for="threshold"><?php _e('Quality Threshold', 'raw-wire-dashboard'); ?></label>
                            <select name="threshold" id="threshold">
                                <option value="3"><?php _e('Low (3+) - More results, less filtering', 'raw-wire-dashboard'); ?></option>
                                <option value="5" selected><?php _e('Medium (5+) - Balanced filtering', 'raw-wire-dashboard'); ?></option>
                                <option value="7"><?php _e('High (7+) - Only significant items', 'raw-wire-dashboard'); ?></option>
                                <option value="8"><?php _e('Very High (8+) - Major stories only', 'raw-wire-dashboard'); ?></option>
                            </select>
                        </div>

                        <div class="rawwire-form-row">
                            <label for="limit"><?php _e('Max Records', 'raw-wire-dashboard'); ?></label>
                            <input type="number" name="limit" id="limit" value="50" min="10" max="100">
                        </div>

                        <div class="rawwire-form-row">
                            <label for="days"><?php _e('Date Range (Days)', 'raw-wire-dashboard'); ?></label>
                            <input type="number" name="days" id="days" value="7" min="1" max="30">
                        </div>

                        <div class="rawwire-form-actions">
                            <button type="submit" class="button button-primary" id="run-ai-scraper">
                                <?php _e('Run AI Scraper', 'raw-wire-dashboard'); ?>
                            </button>
                            <button type="button" class="button" id="test-source">
                                <?php _e('Test Source', 'raw-wire-dashboard'); ?>
                            </button>
                            <span class="spinner"></span>
                        </div>
                    </form>

                    <div id="scraper-results" class="rawwire-results-panel" style="display:none;">
                        <h3><?php _e('Results', 'raw-wire-dashboard'); ?></h3>
                        <div class="rawwire-results-summary"></div>
                        <div class="rawwire-results-items"></div>
                    </div>
                </div>

                <!-- Concepts Panel -->
                <div class="rawwire-admin-card">
                    <h2><?php _e('Evaluation Concepts', 'raw-wire-dashboard'); ?></h2>
                    <p class="description">
                        <?php _e('The AI evaluates content against these abstract concepts, scoring 0-10 for each:', 'raw-wire-dashboard'); ?>
                    </p>
                    
                    <table class="rawwire-concepts-table widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Concept', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Description', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Active', 'raw-wire-dashboard'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($concepts as $key => $description): 
                                $enabled = !in_array($key, $config['disabled_concepts'] ?? []);
                            ?>
                                <tr>
                                    <td><code><?php echo esc_html($key); ?></code></td>
                                    <td><?php echo esc_html($description); ?></td>
                                    <td>
                                        <input type="checkbox" 
                                               name="concepts[]" 
                                               value="<?php echo esc_attr($key); ?>"
                                               <?php checked($enabled); ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3><?php _e('Custom Concepts', 'raw-wire-dashboard'); ?></h3>
                    <div id="custom-concepts">
                        <?php 
                        $custom = $config['custom_concepts'] ?? [];
                        foreach ($custom as $key => $desc): 
                        ?>
                            <div class="rawwire-custom-concept-row">
                                <input type="text" name="custom_concept_key[]" value="<?php echo esc_attr($key); ?>" placeholder="<?php _e('Concept key', 'raw-wire-dashboard'); ?>">
                                <input type="text" name="custom_concept_desc[]" value="<?php echo esc_attr($desc); ?>" placeholder="<?php _e('Description for AI', 'raw-wire-dashboard'); ?>">
                                <button type="button" class="button remove-concept">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="add-concept">
                        <?php _e('+ Add Custom Concept', 'raw-wire-dashboard'); ?>
                    </button>
                </div>

                <!-- Preview Panel -->
                <div class="rawwire-admin-card rawwire-full-width">
                    <h2><?php _e('Test Analysis', 'raw-wire-dashboard'); ?></h2>
                    <p class="description">
                        <?php _e('Paste content below to see how the AI would score and analyze it:', 'raw-wire-dashboard'); ?>
                    </p>
                    
                    <form id="rawwire-preview-form">
                        <?php wp_nonce_field('rawwire_ai_analyze', '_analyze_nonce'); ?>
                        
                        <div class="rawwire-form-row">
                            <label for="preview_title"><?php _e('Title', 'raw-wire-dashboard'); ?></label>
                            <input type="text" name="preview_title" id="preview_title" class="regular-text" placeholder="<?php _e('Document title', 'raw-wire-dashboard'); ?>">
                        </div>

                        <div class="rawwire-form-row">
                            <label for="preview_content"><?php _e('Content / Abstract', 'raw-wire-dashboard'); ?></label>
                            <textarea name="preview_content" id="preview_content" rows="5" class="large-text" placeholder="<?php _e('Paste document content or abstract here...', 'raw-wire-dashboard'); ?>"></textarea>
                        </div>

                        <div class="rawwire-form-actions">
                            <button type="submit" class="button button-secondary" id="run-preview">
                                <?php _e('Analyze with AI', 'raw-wire-dashboard'); ?>
                            </button>
                            <span class="spinner"></span>
                        </div>
                    </form>

                    <div id="preview-results" class="rawwire-analysis-results" style="display:none;">
                        <h3><?php _e('AI Analysis', 'raw-wire-dashboard'); ?></h3>
                        <div class="rawwire-analysis-score">
                            <span class="score-badge">--</span>
                            <span class="recommendation">--</span>
                        </div>
                        <div class="rawwire-analysis-headline"></div>
                        <div class="rawwire-analysis-concepts"></div>
                        <div class="rawwire-analysis-details"></div>
                    </div>
                </div>

                <!-- API Keys Panel -->
                <div class="rawwire-admin-card">
                    <h2><?php _e('Government API Keys', 'raw-wire-dashboard'); ?></h2>
                    <p class="description">
                        <?php _e('API keys are stored securely (encrypted) and shared across all Raw Wire components.', 'raw-wire-dashboard'); ?>
                    </p>
                    
                    <?php 
                    // Get key status from centralized manager
                    $key_manager = function_exists('rawwire_keys') ? rawwire_keys() : null;
                    $key_status = $key_manager ? $key_manager->get_all_key_status('ai_scraper') : [];
                    ?>
                    
                    <form id="rawwire-api-config-form">
                        <?php wp_nonce_field('rawwire_key_manager', 'key_manager_nonce'); ?>
                        
                        <?php foreach (['regulations_gov', 'congress_gov'] as $key_id): 
                            $status = $key_status[$key_id] ?? null;
                            $is_configured = $status && $status['configured'];
                            $masked = $status ? $status['masked_key'] : '';
                            $def = $key_manager ? $key_manager->get_key_definitions()[$key_id] : null;
                        ?>
                        <div class="rawwire-form-row rawwire-key-row" data-key-id="<?php echo esc_attr($key_id); ?>">
                            <label for="key_<?php echo esc_attr($key_id); ?>">
                                <?php echo esc_html($def['name'] ?? ucwords(str_replace('_', ' ', $key_id))); ?>
                                <?php if ($is_configured): ?>
                                    <span class="rawwire-key-status rawwire-key-configured" title="<?php esc_attr_e('Key configured', 'raw-wire-dashboard'); ?>">✓</span>
                                <?php else: ?>
                                    <span class="rawwire-key-status rawwire-key-missing" title="<?php esc_attr_e('Key not configured', 'raw-wire-dashboard'); ?>">⚠</span>
                                <?php endif; ?>
                            </label>
                            <div class="rawwire-key-input-group">
                                <input type="password" 
                                       name="key_value" 
                                       id="key_<?php echo esc_attr($key_id); ?>" 
                                       placeholder="<?php echo $is_configured ? esc_attr($masked) : esc_attr__('Enter API key...', 'raw-wire-dashboard'); ?>"
                                       class="regular-text rawwire-key-input"
                                       autocomplete="new-password"
                                       data-key-id="<?php echo esc_attr($key_id); ?>">
                                <button type="button" class="button rawwire-save-key" data-key-id="<?php echo esc_attr($key_id); ?>">
                                    <?php _e('Save', 'raw-wire-dashboard'); ?>
                                </button>
                                <button type="button" class="button rawwire-test-key" data-key-id="<?php echo esc_attr($key_id); ?>" <?php echo $is_configured ? '' : 'disabled'; ?>>
                                    <?php _e('Test', 'raw-wire-dashboard'); ?>
                                </button>
                            </div>
                            <div class="rawwire-key-info">
                                <a href="<?php echo esc_url($def['signup_url'] ?? '#'); ?>" target="_blank" class="rawwire-help-link">
                                    <?php _e('Get API Key', 'raw-wire-dashboard'); ?> →
                                </a>
                                <?php if ($status && $status['last_tested']): ?>
                                    <span class="rawwire-key-tested <?php echo $status['test_result'] === 'valid' ? 'valid' : 'invalid'; ?>">
                                        <?php printf(__('Last tested: %s', 'raw-wire-dashboard'), human_time_diff(strtotime($status['last_tested']))); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div class="rawwire-form-row">
                            <label for="ai_batch_size"><?php _e('AI Batch Size', 'raw-wire-dashboard'); ?></label>
                            <input type="number" 
                                   name="ai_batch_size" 
                                   id="ai_batch_size" 
                                   value="<?php echo intval(get_option('rawwire_ai_batch_size', 5)); ?>"
                                   min="1" max="20">
                            <span class="description"><?php _e('Items to analyze per AI request', 'raw-wire-dashboard'); ?></span>
                        </div>

                        <div class="rawwire-form-actions">
                            <button type="button" class="button" id="save-batch-size">
                                <?php _e('Save Batch Size', 'raw-wire-dashboard'); ?>
                            </button>
                            <span class="spinner"></span>
                        </div>
                    </form>
                </div>

                <!-- Scoring Weights Panel -->
                <div class="rawwire-admin-card">
                    <h2><?php _e('Scoring Weights', 'raw-wire-dashboard'); ?></h2>
                    <p class="description">
                        <?php _e('Adjust how different factors influence the final content score. Higher weights mean more importance.', 'raw-wire-dashboard'); ?>
                    </p>
                    
                    <?php 
                    $weights = get_option('rawwire_scoring_weights', array(
                        'relevance' => 30,
                        'timeliness' => 20,
                        'quality' => 25,
                    ));
                    ?>
                    
                    <form id="rawwire-weights-form">
                        <?php wp_nonce_field('rawwire_weights', 'weights_nonce'); ?>
                        
                        <div class="rawwire-form-row rawwire-weight-row">
                            <label for="weight_relevance">
                                <?php _e('Keyword Relevance', 'raw-wire-dashboard'); ?>
                                <span class="rawwire-weight-help" title="<?php esc_attr_e('How well content matches your campaign keywords. Higher = stricter keyword matching.', 'raw-wire-dashboard'); ?>">?</span>
                            </label>
                            <input type="range" name="weights[relevance]" id="weight_relevance" 
                                   min="0" max="100" step="5" 
                                   value="<?php echo esc_attr($weights['relevance'] ?? 30); ?>"
                                   class="rawwire-weight-slider">
                            <span class="rawwire-weight-value"><?php echo esc_html($weights['relevance'] ?? 30); ?>%</span>
                        </div>
                        
                        <div class="rawwire-form-row rawwire-weight-row">
                            <label for="weight_timeliness">
                                <?php _e('Timeliness/Freshness', 'raw-wire-dashboard'); ?>
                                <span class="rawwire-weight-help" title="<?php esc_attr_e('How recent the content is. Higher = prefer newer content.', 'raw-wire-dashboard'); ?>">?</span>
                            </label>
                            <input type="range" name="weights[timeliness]" id="weight_timeliness" 
                                   min="0" max="100" step="5" 
                                   value="<?php echo esc_attr($weights['timeliness'] ?? 20); ?>"
                                   class="rawwire-weight-slider">
                            <span class="rawwire-weight-value"><?php echo esc_html($weights['timeliness'] ?? 20); ?>%</span>
                        </div>
                        
                        <div class="rawwire-form-row rawwire-weight-row">
                            <label for="weight_quality">
                                <?php _e('Content Quality', 'raw-wire-dashboard'); ?>
                                <span class="rawwire-weight-help" title="<?php esc_attr_e('Content length and substance. Higher = prefer longer, more detailed content.', 'raw-wire-dashboard'); ?>">?</span>
                            </label>
                            <input type="range" name="weights[quality]" id="weight_quality" 
                                   min="0" max="100" step="5" 
                                   value="<?php echo esc_attr($weights['quality'] ?? 25); ?>"
                                   class="rawwire-weight-slider">
                            <span class="rawwire-weight-value"><?php echo esc_html($weights['quality'] ?? 25); ?>%</span>
                        </div>
                        
                        <div class="rawwire-weights-total">
                            <strong><?php _e('Total Weight:', 'raw-wire-dashboard'); ?></strong>
                            <span id="weights-total"><?php echo array_sum($weights); ?>%</span>
                            <span class="rawwire-weights-hint"><?php _e('(Values are normalized automatically)', 'raw-wire-dashboard'); ?></span>
                        </div>
                        
                        <div class="rawwire-form-row" style="margin-top: 15px;">
                            <label for="top_per_source">
                                <?php _e('Top Items Per Source', 'raw-wire-dashboard'); ?>
                                <span class="rawwire-weight-help" title="<?php esc_attr_e('How many top-scoring items per source go to Approvals queue.', 'raw-wire-dashboard'); ?>">?</span>
                            </label>
                            <select name="top_per_source" id="top_per_source">
                                <option value="1" <?php selected(get_option('rawwire_top_per_source', 2), 1); ?>><?php _e('1 per source', 'raw-wire-dashboard'); ?></option>
                                <option value="2" <?php selected(get_option('rawwire_top_per_source', 2), 2); ?>><?php _e('2 per source', 'raw-wire-dashboard'); ?></option>
                                <option value="3" <?php selected(get_option('rawwire_top_per_source', 2), 3); ?>><?php _e('3 per source', 'raw-wire-dashboard'); ?></option>
                                <option value="5" <?php selected(get_option('rawwire_top_per_source', 2), 5); ?>><?php _e('5 per source', 'raw-wire-dashboard'); ?></option>
                            </select>
                            <span class="description"><?php _e('Remainder goes to Archives for review', 'raw-wire-dashboard'); ?></span>
                        </div>

                        <div class="rawwire-form-actions">
                            <button type="button" class="button button-primary" id="save-weights">
                                <?php _e('Save Weights', 'raw-wire-dashboard'); ?>
                            </button>
                            <button type="button" class="button" id="reset-weights">
                                <?php _e('Reset to Defaults', 'raw-wire-dashboard'); ?>
                            </button>
                            <span class="spinner"></span>
                        </div>
                    </form>
                </div>

                <!-- Scheduling Panel -->
                <div class="rawwire-admin-card">
                    <h2><?php _e('Automated Scraping', 'raw-wire-dashboard'); ?></h2>
                    
                    <form id="rawwire-schedule-form">
                        <div class="rawwire-form-row">
                            <label>
                                <input type="checkbox" 
                                       name="schedule_enabled" 
                                       value="1"
                                       <?php checked(get_option('rawwire_ai_scraper_schedule', false)); ?>>
                                <?php _e('Enable automated scraping', 'raw-wire-dashboard'); ?>
                            </label>
                        </div>

                        <div class="rawwire-form-row">
                            <label for="schedule_frequency"><?php _e('Frequency', 'raw-wire-dashboard'); ?></label>
                            <select name="schedule_frequency" id="schedule_frequency">
                                <option value="hourly"><?php _e('Hourly', 'raw-wire-dashboard'); ?></option>
                                <option value="twicedaily" selected><?php _e('Twice Daily', 'raw-wire-dashboard'); ?></option>
                                <option value="daily"><?php _e('Daily', 'raw-wire-dashboard'); ?></option>
                            </select>
                        </div>

                        <div class="rawwire-form-row">
                            <label for="schedule_sources"><?php _e('Sources to Scrape', 'raw-wire-dashboard'); ?></label>
                            <select name="schedule_sources[]" id="schedule_sources" multiple>
                                <optgroup label="<?php _e('AI-Native Sources', 'raw-wire-dashboard'); ?>">
                                    <?php foreach ($ai_sources as $key => $source): ?>
                                        <option value="<?php echo esc_attr($key); ?>">
                                            <?php echo esc_html($source['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($toolkit_sources)): ?>
                                <optgroup label="<?php _e('Scraper Toolkit Sources', 'raw-wire-dashboard'); ?>">
                                    <?php foreach ($toolkit_sources as $source): ?>
                                        <option value="toolkit_<?php echo esc_attr($source['id'] ?? sanitize_title($source['name'])); ?>">
                                            <?php echo esc_html($source['name'] ?? 'Unknown'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="rawwire-form-row">
                            <label for="schedule_output"><?php _e('Output To', 'raw-wire-dashboard'); ?></label>
                            <select name="schedule_output" id="schedule_output">
                                <?php foreach ($workflow_tables as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($key, 'candidates'); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rawwire-form-actions">
                            <button type="submit" class="button button-primary">
                                <?php _e('Save Schedule', 'raw-wire-dashboard'); ?>
                            </button>
                        </div>
                    </form>

                    <?php 
                    $next_run = wp_next_scheduled('rawwire_ai_scraper_scheduled');
                    if ($next_run): 
                    ?>
                        <p class="rawwire-schedule-info">
                            <?php printf(
                                __('Next scheduled run: %s', 'raw-wire-dashboard'),
                                '<strong>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run) . '</strong>'
                            ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <style>
            .rawwire-admin-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-top: 20px;
            }
            .rawwire-admin-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
            }
            .rawwire-admin-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .rawwire-full-width {
                grid-column: span 2;
            }
            .rawwire-form-horizontal .rawwire-form-row {
                display: flex;
                align-items: center;
                margin-bottom: 15px;
                gap: 10px;
            }
            .rawwire-form-horizontal .rawwire-form-row label {
                min-width: 140px;
                font-weight: 500;
            }
            .rawwire-form-horizontal .rawwire-form-row select,
            .rawwire-form-horizontal .rawwire-form-row input[type="number"] {
                flex: 1;
                max-width: 300px;
            }
            .rawwire-form-actions {
                margin-top: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .rawwire-concepts-table {
                margin: 15px 0;
            }
            .rawwire-concepts-table code {
                background: #f0f0f1;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .rawwire-custom-concept-row {
                display: flex;
                gap: 10px;
                margin-bottom: 10px;
            }
            .rawwire-custom-concept-row input[type="text"] {
                flex: 1;
            }
            .rawwire-results-panel {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            .rawwire-results-summary {
                background: #f6f7f7;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 15px;
            }
            .rawwire-results-items {
                max-height: 400px;
                overflow-y: auto;
            }
            .rawwire-result-item {
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                margin-bottom: 10px;
            }
            .rawwire-result-item.score-high {
                border-left: 4px solid #d63638;
            }
            .rawwire-result-item.score-medium {
                border-left: 4px solid #dba617;
            }
            .rawwire-result-item.score-low {
                border-left: 4px solid #00a32a;
            }
            .rawwire-analysis-results {
                margin-top: 20px;
                padding: 20px;
                background: #f6f7f7;
                border-radius: 4px;
            }
            .rawwire-analysis-score {
                display: flex;
                align-items: center;
                gap: 15px;
                margin-bottom: 15px;
            }
            .score-badge {
                font-size: 24px;
                font-weight: bold;
                width: 60px;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                background: #0073aa;
                color: #fff;
            }
            .score-badge.high { background: #d63638; }
            .score-badge.medium { background: #dba617; }
            .score-badge.low { background: #00a32a; }
            .rawwire-analysis-headline {
                font-size: 16px;
                font-style: italic;
                color: #50575e;
                margin-bottom: 15px;
            }
            .rawwire-analysis-concepts {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                margin-bottom: 15px;
            }
            .concept-score {
                background: #fff;
                padding: 8px 12px;
                border-radius: 4px;
                border: 1px solid #ddd;
            }
            .concept-score .name {
                font-weight: 500;
                font-size: 12px;
                text-transform: uppercase;
            }
            .concept-score .value {
                font-size: 18px;
                font-weight: bold;
            }
            .rawwire-notice {
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .rawwire-notice-info {
                background: #e7f3ff;
                border: 1px solid #72aee6;
            }
            .rawwire-help-link {
                font-size: 12px;
            }
            /* Key Management Styles */
            .rawwire-key-row {
                padding: 12px;
                margin-bottom: 10px;
                background: #fafafa;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .rawwire-key-row label {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                font-weight: 600;
            }
            .rawwire-key-status {
                font-size: 14px;
            }
            .rawwire-key-configured {
                color: #00a32a;
            }
            .rawwire-key-missing {
                color: #dba617;
            }
            .rawwire-key-input-group {
                display: flex;
                gap: 8px;
                margin-bottom: 8px;
            }
            .rawwire-key-input {
                flex: 1;
            }
            .rawwire-key-info {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 12px;
            }
            .rawwire-key-tested {
                padding: 2px 6px;
                border-radius: 3px;
            }
            .rawwire-key-tested.valid {
                background: #d4edda;
                color: #155724;
            }
            .rawwire-key-tested.invalid {
                background: #f8d7da;
                color: #721c24;
            }
            /* Weights Styles */
            .rawwire-weight-row {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .rawwire-weight-row label {
                min-width: 160px;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            .rawwire-weight-help {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 16px;
                height: 16px;
                background: #ddd;
                border-radius: 50%;
                font-size: 11px;
                cursor: help;
            }
            .rawwire-weight-slider {
                flex: 1;
                max-width: 200px;
            }
            .rawwire-weight-value {
                min-width: 45px;
                text-align: right;
                font-weight: bold;
            }
            .rawwire-weights-total {
                margin: 15px 0;
                padding: 10px;
                background: #f5f5f5;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .rawwire-weights-hint {
                color: #666;
                font-size: 12px;
            }
            @media (max-width: 1200px) {
                .rawwire-admin-grid {
                    grid-template-columns: 1fr;
                }
                .rawwire-full-width {
                    grid-column: span 1;
                }
                .rawwire-analysis-concepts {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Source descriptions (combine AI and toolkit sources)
            var sourceDescriptions = <?php echo json_encode(array_map(function($s) {
                return $s['description'] ?? '';
            }, $ai_sources ?? [])); ?>;

            $('#source_type').on('change', function() {
                var desc = sourceDescriptions[$(this).val()] || '';
                $('#source-description').text(desc);
            }).trigger('change');

            // Run scraper
            $('#rawwire-ai-scraper-form').on('submit', function(e) {
                e.preventDefault();
                
                var $btn = $('#run-ai-scraper');
                var $spinner = $(this).find('.spinner');
                var $results = $('#scraper-results');
                
                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                
                $.post(ajaxurl, {
                    action: 'rawwire_ai_scraper_run',
                    _wpnonce: $(this).find('[name="_wpnonce"]').val(),
                    source_type: $('#source_type').val(),
                    threshold: $('#threshold').val(),
                    limit: $('#limit').val(),
                    days: $('#days').val(),
                    output_table: $('#output_table').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    
                    if (response.success) {
                        displayResults(response.data);
                        $results.show();
                    } else {
                        alert('Error: ' + (response.data.error || 'Unknown error'));
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    alert('Request failed');
                });
            });

            function displayResults(data) {
                var $summary = $('.rawwire-results-summary');
                var $items = $('.rawwire-results-items');
                
                $summary.html(
                    '<strong>Scrape Complete</strong><br>' +
                    'Fetched: ' + data.raw_count + ' records<br>' +
                    'Passed AI filter: ' + data.passed + ' records<br>' +
                    'Stored to: ' + (data.output_table || 'candidates') + ' table<br>' +
                    'Stored: ' + data.stored + ' new items'
                );
                
                $items.empty();
                
                if (data.top_items && data.top_items.length) {
                    data.top_items.forEach(function(item) {
                        var scoreClass = item.ai_score >= 7 ? 'high' : (item.ai_score >= 5 ? 'medium' : 'low');
                        $items.append(
                            '<div class="rawwire-result-item score-' + scoreClass + '">' +
                            '<strong>' + escapeHtml(item.title) + '</strong>' +
                            '<span style="float:right;font-weight:bold;color:#0073aa;">' + item.ai_score + '/10</span>' +
                            '<br><small>' + (item.ai_headline || item.abstract || '').substring(0, 200) + '...</small>' +
                            '</div>'
                        );
                    });
                } else {
                    $items.html('<p>No items passed the quality threshold.</p>');
                }
            }

            // Test analysis
            $('#rawwire-preview-form').on('submit', function(e) {
                e.preventDefault();
                
                var $btn = $('#run-preview');
                var $spinner = $(this).find('.spinner');
                var $results = $('#preview-results');
                
                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                
                $.post(ajaxurl, {
                    action: 'rawwire_ai_scraper_analyze',
                    _wpnonce: $(this).find('[name="_analyze_nonce"]').val(),
                    title: $('#preview_title').val(),
                    content: $('#preview_content').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    
                    if (response.success) {
                        displayAnalysis(response.data);
                        $results.show();
                    } else {
                        alert('Error: ' + (response.data.message || 'Analysis failed'));
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    alert('Request failed');
                });
            });

            function displayAnalysis(data) {
                var scoreClass = data.ai_score >= 7 ? 'high' : (data.ai_score >= 5 ? 'medium' : 'low');
                
                $('.rawwire-analysis-score .score-badge')
                    .text(data.ai_score)
                    .removeClass('high medium low')
                    .addClass(scoreClass);
                
                $('.rawwire-analysis-score .recommendation')
                    .text('Recommendation: ' + (data.recommendation || 'N/A').toUpperCase());
                
                $('.rawwire-analysis-headline').text(data.ai_headline || '');
                
                var $concepts = $('.rawwire-analysis-concepts').empty();
                if (data.concept_scores) {
                    for (var key in data.concept_scores) {
                        var cs = data.concept_scores[key];
                        $concepts.append(
                            '<div class="concept-score">' +
                            '<div class="name">' + key + '</div>' +
                            '<div class="value">' + cs.score + '</div>' +
                            '</div>'
                        );
                    }
                }
                
                var $details = $('.rawwire-analysis-details').empty();
                if (data.ai_concerns && data.ai_concerns.length) {
                    $details.append('<p><strong>Key Concerns:</strong> ' + data.ai_concerns.join(', ') + '</p>');
                }
                if (data.ai_affected && data.ai_affected.length) {
                    $details.append('<p><strong>Affected Parties:</strong> ' + data.ai_affected.join(', ') + '</p>');
                }
            }

            // Add custom concept
            $('#add-concept').on('click', function() {
                $('#custom-concepts').append(
                    '<div class="rawwire-custom-concept-row">' +
                    '<input type="text" name="custom_concept_key[]" placeholder="<?php echo esc_js(__('Concept key', 'raw-wire-dashboard')); ?>">' +
                    '<input type="text" name="custom_concept_desc[]" placeholder="<?php echo esc_js(__('Description for AI', 'raw-wire-dashboard')); ?>">' +
                    '<button type="button" class="button remove-concept">&times;</button>' +
                    '</div>'
                );
            });

            $(document).on('click', '.remove-concept', function() {
                $(this).closest('.rawwire-custom-concept-row').remove();
            });

            // =====================================================
            // API Key Management (Centralized Key Manager)
            // =====================================================
            
            // Save individual key
            $(document).on('click', '.rawwire-save-key', function() {
                var $btn = $(this);
                var keyId = $btn.data('key-id');
                var $input = $('#key_' + keyId);
                var value = $input.val();
                
                if (!value) {
                    alert('Please enter an API key');
                    return;
                }
                
                $btn.prop('disabled', true).text('Saving...');
                
                $.post(ajaxurl, {
                    action: 'rawwire_save_api_key',
                    _wpnonce: $('#rawwire-api-config-form [name="key_manager_nonce"]').val(),
                    key_id: keyId,
                    value: value,
                    test: 1
                }, function(response) {
                    $btn.prop('disabled', false).text('Save');
                    
                    if (response.success) {
                        var $row = $btn.closest('.rawwire-key-row');
                        
                        // Update status indicator
                        $row.find('.rawwire-key-status')
                            .removeClass('rawwire-key-missing')
                            .addClass('rawwire-key-configured')
                            .html('✓');
                        
                        // Clear input and show masked value as placeholder
                        $input.val('').attr('placeholder', response.data.masked_key);
                        
                        // Enable test button
                        $row.find('.rawwire-test-key').prop('disabled', false);
                        
                        // Show test result if available
                        if (response.data.test_result) {
                            var testMsg = response.data.test_result.success 
                                ? '✓ Key validated' 
                                : '⚠ ' + response.data.test_result.message;
                            alert(testMsg);
                        } else {
                            alert('Key saved successfully');
                        }
                    } else {
                        alert('Error: ' + (response.data.message || 'Failed to save key'));
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Save');
                    alert('Request failed');
                });
            });
            
            // Test individual key
            $(document).on('click', '.rawwire-test-key', function() {
                var $btn = $(this);
                var keyId = $btn.data('key-id');
                
                $btn.prop('disabled', true).text('Testing...');
                
                $.post(ajaxurl, {
                    action: 'rawwire_test_api_key',
                    _wpnonce: $('#rawwire-api-config-form [name="key_manager_nonce"]').val(),
                    key_id: keyId
                }, function(response) {
                    $btn.prop('disabled', false).text('Test');
                    
                    if (response.success) {
                        alert('✓ ' + response.data.message);
                    } else {
                        alert('✗ ' + (response.data.message || 'Test failed'));
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Test');
                    alert('Request failed');
                });
            });

            // Save batch size
            $('#save-batch-size').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'rawwire_ai_scraper_config',
                    _wpnonce: $('#rawwire-api-config-form [name="key_manager_nonce"]').val(),
                    ai_batch_size: $('#ai_batch_size').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        alert('Batch size saved!');
                    } else {
                        alert('Error saving');
                    }
                });
            });

            // Scoring Weights
            function updateWeightsTotal() {
                var total = 0;
                $('.rawwire-weight-slider').each(function() {
                    total += parseInt($(this).val()) || 0;
                });
                $('#weights-total').text(total + '%');
            }
            
            $('.rawwire-weight-slider').on('input change', function() {
                $(this).siblings('.rawwire-weight-value').text($(this).val() + '%');
                updateWeightsTotal();
            });
            
            $('#save-weights').on('click', function() {
                var $btn = $(this);
                var $spinner = $btn.siblings('.spinner');
                
                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                
                var weights = {};
                $('.rawwire-weight-slider').each(function() {
                    var name = $(this).attr('name').match(/\[(\w+)\]/)[1];
                    weights[name] = parseInt($(this).val());
                });
                
                $.post(ajaxurl, {
                    action: 'rawwire_save_scoring_weights',
                    weights_nonce: $('[name="weights_nonce"]').val(),
                    weights: weights,
                    top_per_source: $('#top_per_source').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    if (response.success) {
                        alert('Scoring weights saved!');
                    } else {
                        alert('Error saving: ' + (response.data?.message || 'Unknown error'));
                    }
                });
            });
            
            $('#reset-weights').on('click', function() {
                if (!confirm('Reset scoring weights to defaults?')) return;
                
                $('#weight_relevance').val(30).siblings('.rawwire-weight-value').text('30%');
                $('#weight_timeliness').val(20).siblings('.rawwire-weight-value').text('20%');
                $('#weight_quality').val(25).siblings('.rawwire-weight-value').text('25%');
                $('#top_per_source').val(2);
                updateWeightsTotal();
            });

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        });
        </script>
        <?php
    }

    /**
     * Get scraper configuration
     * 
     * @return array
     */
    private function get_config() {
        return get_option('rawwire_ai_scraper_config', [
            'disabled_concepts' => [],
            'custom_concepts'   => [],
        ]);
    }

    /**
     * AJAX: Save configuration (batch size only - keys handled by Key Manager)
     */
    public function ajax_save_config() {
        // Accept either nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rawwire_key_manager') &&
            !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rawwire_ai_scraper_config')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Only handle batch size now - keys are managed by RawWire_Key_Manager
        if (isset($_POST['ai_batch_size'])) {
            update_option('rawwire_ai_batch_size', intval($_POST['ai_batch_size']));
        }

        wp_send_json_success(['message' => 'Configuration saved']);
    }

    /**
     * AJAX: Test source connection
     */
    public function ajax_test_source() {
        check_ajax_referer('rawwire_ai_scraper', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $source_type = sanitize_text_field($_POST['source_type'] ?? 'federal_register');
        $scraper = rawwire_ai_scraper();
        
        $result = $scraper->fetch_from_source($source_type, 5, 7);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }

        wp_send_json_success([
            'message' => sprintf('Successfully fetched %d records', count($result)),
            'sample'  => array_slice($result, 0, 3),
        ]);
    }

    /**
     * AJAX: Preview analysis
     */
    public function ajax_preview_analysis() {
        check_ajax_referer('rawwire_ai_analyze', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Handled by scraper class
        wp_send_json_error(['message' => 'Use rawwire_ai_scraper_analyze action']);
    }

    /**
     * AJAX: Save scoring weights
     */
    public function ajax_save_scoring_weights() {
        if (!wp_verify_nonce($_POST['weights_nonce'] ?? '', 'rawwire_weights')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $weights = $_POST['weights'] ?? [];
        $sanitized_weights = [];
        
        // Sanitize and validate weights
        foreach (['relevance', 'timeliness', 'quality'] as $key) {
            $value = isset($weights[$key]) ? intval($weights[$key]) : 0;
            $sanitized_weights[$key] = max(0, min(100, $value));
        }
        
        // Save weights
        update_option('rawwire_scoring_weights', $sanitized_weights);
        
        // Save top per source setting
        if (isset($_POST['top_per_source'])) {
            $top_per_source = intval($_POST['top_per_source']);
            $top_per_source = max(1, min(10, $top_per_source));
            update_option('rawwire_top_per_source', $top_per_source);
        }
        
        wp_send_json_success([
            'message' => 'Scoring weights saved',
            'weights' => $sanitized_weights,
        ]);
    }
}

// Initialize
RawWire_AI_Scraper_Panel::get_instance();
