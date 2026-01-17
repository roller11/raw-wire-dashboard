<?php
/**
 * Candidates Admin Page
 * Displays scraped items awaiting AI scoring
 */

class RawWire_Candidates_Page {
    
    /**
     * Render the candidates page
     */
    public function render() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_candidates';
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        // Get candidates grouped by source
        $candidates_by_source = $wpdb->get_results("
            SELECT source, COUNT(*) as count 
            FROM {$table_name} 
            GROUP BY source 
            ORDER BY source
        ", ARRAY_A);
        
        // Get recent candidates
        $candidates = $wpdb->get_results("
            SELECT * FROM {$table_name} 
            ORDER BY created_at DESC 
            LIMIT 50
        ", ARRAY_A);
        
        ?>
        <div class="wrap rawwire-candidates">
            <h1><?php echo esc_html__('Candidates', 'raw-wire-dashboard'); ?></h1>
            <p class="description">Scraped items awaiting AI scoring</p>
            
            <div class="rawwire-stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo esc_html($total); ?></div>
                    <div class="stat-label">Total Candidates</div>
                </div>
                
                <?php if ($candidates_by_source): ?>
                    <?php foreach ($candidates_by_source as $source_stat): ?>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo esc_html($source_stat['count']); ?></div>
                            <div class="stat-label"><?php echo esc_html($source_stat['source']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($candidates): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Title</th>
                            <th style="width: 15%;">Source</th>
                            <th style="width: 15%;">Copyright</th>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 15%;">Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($candidate['title']); ?></strong>
                                    <?php if (!empty($candidate['content'])): ?>
                                        <div class="candidate-excerpt"><?php echo esc_html(wp_trim_words($candidate['content'], 30)); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($candidate['source']); ?></td>
                                <td><?php echo esc_html($candidate['copyright_status'] ?? 'unknown'); ?></td>
                                <td><?php echo esc_html(date('M j, Y', strtotime($candidate['created_at']))); ?></td>
                                <td>
                                    <?php if (!empty($candidate['link'])): ?>
                                        <a href="<?php echo esc_url($candidate['link']); ?>" target="_blank" rel="noopener">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html__('No candidates found. Run the scraper to populate this table.', 'raw-wire-dashboard'); ?></p>
                </div>
            <?php endif; ?>
            
            <style>
                .rawwire-candidates {
                    margin: 20px;
                }
                .rawwire-stats {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                    gap: 20px;
                    margin: 30px 0;
                }
                .stat-card {
                    background: white;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                }
                .stat-value {
                    font-size: 36px;
                    font-weight: bold;
                    color: #2271b1;
                    margin-bottom: 8px;
                }
                .stat-label {
                    font-size: 14px;
                    color: #666;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .candidate-excerpt {
                    color: #666;
                    font-size: 13px;
                    margin-top: 5px;
                    line-height: 1.4;
                }
            </style>
        </div>
        <?php
    }
}
