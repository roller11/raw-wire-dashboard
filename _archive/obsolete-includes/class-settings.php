<?php
if (!defined("ABSPATH")) { exit; }

class Raw_Wire_Settings {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }
    
    public static function add_settings_page() {
        add_submenu_page(
            'raw-wire-dashboard',
            'Raw-Wire Settings',
            'Settings',
            'manage_options',
            'raw-wire-settings',
            [__CLASS__, 'render_settings_page']
        );
    }
    
    public static function register_settings() {
        register_setting('rawwire_settings', 'rawwire_github_token');
        register_setting('rawwire_settings', 'rawwire_github_repo');
        
        add_settings_section(
            'rawwire_github_section',
            'GitHub Integration',
            [__CLASS__, 'render_github_section'],
            'rawwire_settings'
        );
        
        add_settings_field(
            'rawwire_github_token',
            'GitHub Personal Access Token',
            [__CLASS__, 'render_token_field'],
            'rawwire_settings',
            'rawwire_github_section'
        );
        
        add_settings_field(
            'rawwire_github_repo',
            'GitHub Repository',
            [__CLASS__, 'render_repo_field'],
            'rawwire_settings',
            'rawwire_github_section'
        );
    }
    
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['settings-updated'])) {
            add_settings_error('rawwire_messages', 'rawwire_message', 'Settings Saved', 'updated');
        }
        
        settings_errors('rawwire_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('rawwire_settings');
                do_settings_sections('rawwire_settings');
                submit_button('Save Settings');
                ?>
            </form>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>How to Get a GitHub Token</h2>
                <ol>
                    <li>Go to <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings → Developer Settings → Personal Access Tokens</a></li>
                    <li>Click "Generate new token (classic)"</li>
                    <li>Give it a name like "Raw-Wire Dashboard"</li>
                    <li>Select scopes: <code>repo</code> (full control of private repositories)</li>
                    <li>Click "Generate token" and copy it</li>
                    <li>Paste it in the field above</li>
                </ol>
                <p><strong>Repository format:</strong> <code>owner/repo-name</code> (e.g., <code>raw-wire-dao-llc/raw-wire-core</code>)</p>
            </div>
        </div>
        <?php
    }
    
    public static function render_github_section() {
        echo '<p>Configure your GitHub integration to sync issues automatically.</p>';
    }
    
    /**
     * Get a generic placeholder for GitHub token input field.
     * 
     * Returns a non-token-looking placeholder to avoid triggering security scans.
     * 
     * @since 1.0.0
     * @return string A generic placeholder string.
     */
    public static function token_placeholder() {
        // Generic, non-token-looking placeholder
        return '***';
    }
    
    public static function render_token_field() {
        $token = get_option('rawwire_github_token', '');
        $masked = !empty($token) ? substr($token, 0, 4) . '****' . substr($token, -4) : '';
        ?>
        <input type="password" 
               name="rawwire_github_token" 
               id="rawwire_github_token" 
               value="<?php echo esc_attr($token); ?>" 
               class="regular-text"
               placeholder="<?php echo esc_attr(self::token_placeholder()); ?>">
        <?php if (!empty($masked)): ?>
            <p class="description">Current token: <?php echo esc_html($masked); ?></p>
        <?php endif; ?>
        <p class="description">Generate a token at <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings</a> with <code>repo</code> scope.</p>
        <?php
    }
    
    public static function render_repo_field() {
        $repo = get_option('rawwire_github_repo', 'raw-wire-dao-llc/raw-wire-core');
        ?>
        <input type="text" 
               name="rawwire_github_repo" 
               id="rawwire_github_repo" 
               value="<?php echo esc_attr($repo); ?>" 
               class="regular-text"
               placeholder="owner/repository">
        <p class="description">Format: <code>owner/repo-name</code> (e.g., <code>raw-wire-dao-llc/raw-wire-core</code>)</p>
        <?php
    }
}
