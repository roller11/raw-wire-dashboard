/**
 * Scraper & AI Control Panels UI Component
 * Provides interactive configuration for sync operations
 * 
 * @package RawWire_Dashboard
 * @since 1.1.0
 */

(function($) {
    'use strict';

    window.RawWireControlPanels = {
        
        /**
         * Initialize control panels
         */
        init: function() {
            this.renderScraperControls();
            this.renderAIControls();
            this.bindEvents();
            this.loadSavedSettings();
        },

        /**
         * Render scraper configuration panel
         */
        renderScraperControls: function() {
            const html = `
                <div class="rawwire-control-panel" id="scraper-controls-panel">
                    <div class="panel-header">
                        <h3>
                            <span class="dashicons dashicons-admin-settings"></span>
                            Scraper Configuration
                        </h3>
                        <button class="panel-toggle" data-target="scraper-controls-body">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                        </button>
                    </div>
                    <div class="panel-body" id="scraper-controls-body">
                        <div class="control-section">
                            <h4>Data Sources</h4>
                            <p class="description">Select which sources to include in the sync</p>
                            <div class="source-toggles">
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="federal_register_rules" checked>
                                    <span>Federal Register - Rules</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="federal_register_notices" checked>
                                    <span>Federal Register - Notices</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="whitehouse_briefings" checked>
                                    <span>White House Press Briefings</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="whitehouse_statements" checked>
                                    <span>White House Statements</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="fda_news" checked>
                                    <span>FDA News & Events</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="epa_releases" checked>
                                    <span>EPA News Releases</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="doj_releases" checked>
                                    <span>DOJ Press Releases</span>
                                </label>
                                <label class="source-toggle">
                                    <input type="checkbox" name="source" value="sec_releases" checked>
                                    <span>SEC Press Releases</span>
                                </label>
                            </div>
                        </div>

                        <div class="control-section">
                            <h4>Collection Limits</h4>
                            <div class="control-row">
                                <label>
                                    <span>Items per source:</span>
                                    <input type="number" id="items-per-source" min="5" max="100" value="20" step="5">
                                    <span class="hint">5-100 items</span>
                                </label>
                            </div>
                            <div class="control-row">
                                <label>
                                    <span>Date range:</span>
                                    <select id="date-range">
                                        <option value="24h">Last 24 hours</option>
                                        <option value="7d" selected>Last 7 days</option>
                                        <option value="30d">Last 30 days</option>
                                        <option value="all">All time</option>
                                    </select>
                                </label>
                            </div>
                        </div>

                        <div class="control-section">
                            <h4>Keyword Filter</h4>
                            <p class="description">Filter results by keywords (comma-separated)</p>
                            <input type="text" id="keyword-filter" placeholder="e.g., enforcement, regulation, ban" class="widefat">
                            <p class="hint">Leave empty to collect all content</p>
                        </div>

                        <div class="control-actions">
                            <button class="button button-secondary" id="reset-scraper-config">Reset to Defaults</button>
                            <button class="button button-primary" id="save-scraper-config">Save Configuration</button>
                        </div>
                    </div>
                </div>
            `;

            $('.rawwire-panels').before(html);
        },

        /**
         * Render AI scoring configuration panel
         */
        renderAIControls: function() {
            const html = `
                <div class="rawwire-control-panel" id="ai-controls-panel">
                    <div class="panel-header">
                        <h3>
                            <span class="dashicons dashicons-search"></span>
                            AI Scoring Configuration
                        </h3>
                        <button class="panel-toggle" data-target="ai-controls-body">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                        </button>
                    </div>
                    <div class="panel-body" id="ai-controls-body">
                        <div class="control-section">
                            <h4>Scoring Weights</h4>
                            <p class="description">Adjust importance of each scoring factor (must total 100%)</p>
                            
                            <div class="weight-slider">
                                <label>
                                    <span class="weight-label">Shocking</span>
                                    <span class="weight-value" id="weight-shocking-value">25%</span>
                                </label>
                                <input type="range" id="weight-shocking" min="0" max="100" value="25" step="5">
                                <p class="hint">How surprising or shocking is the content?</p>
                            </div>

                            <div class="weight-slider">
                                <label>
                                    <span class="weight-label">Unbelievable</span>
                                    <span class="weight-value" id="weight-unbelievable-value">25%</span>
                                </label>
                                <input type="range" id="weight-unbelievable" min="0" max="100" value="25" step="5">
                                <p class="hint">How hard to believe or unprecedented?</p>
                            </div>

                            <div class="weight-slider">
                                <label>
                                    <span class="weight-label">Newsworthy</span>
                                    <span class="weight-value" id="weight-newsworthy-value">25%</span>
                                </label>
                                <input type="range" id="weight-newsworthy" min="0" max="100" value="25" step="5">
                                <p class="hint">How newsworthy for major publications?</p>
                            </div>

                            <div class="weight-slider">
                                <label>
                                    <span class="weight-label">Unique</span>
                                    <span class="weight-value" id="weight-unique-value">25%</span>
                                </label>
                                <input type="range" id="weight-unique" min="0" max="100" value="25" step="5">
                                <p class="hint">How unique or rare is this type of action?</p>
                            </div>

                            <div class="weight-total">
                                <strong>Total:</strong> <span id="weight-total">100%</span>
                                <span class="weight-warning" id="weight-warning" style="display:none;">
                                    ⚠ Must equal 100%
                                </span>
                            </div>
                        </div>

                        <div class="control-section">
                            <h4>Custom AI Instructions</h4>
                            <p class="description">Additional instructions to guide the AI scoring (optional)</p>
                            <textarea id="custom-ai-instructions" rows="4" class="widefat" 
                                placeholder="Example: Focus on actions that directly impact small businesses..."></textarea>
                            <p class="hint">These instructions will be added to the AI prompt</p>
                        </div>

                        <div class="control-section">
                            <h4>Model Settings</h4>
                            <div class="control-row">
                                <label>
                                    <span>AI Model:</span>
                                    <select id="ai-model">
                                        <option value="llama2" selected>Llama 2 (Fast)</option>
                                        <option value="llama3">Llama 3 (Balanced)</option>
                                        <option value="mistral">Mistral (Precise)</option>
                                        <option value="mixtral">Mixtral (Advanced)</option>
                                    </select>
                                </label>
                            </div>
                            <div class="control-row">
                                <label>
                                    <span>Temperature:</span>
                                    <input type="number" id="ai-temperature" min="0" max="2" step="0.1" value="0.3">
                                    <span class="hint">0.0 (deterministic) to 2.0 (creative)</span>
                                </label>
                            </div>
                        </div>

                        <div class="control-actions">
                            <button class="button button-secondary" id="reset-ai-config">Reset to Defaults</button>
                            <button class="button button-primary" id="save-ai-config">Save Configuration</button>
                        </div>
                    </div>
                </div>
            `;

            $('#scraper-controls-panel').after(html);
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Panel toggle
            $(document).on('click', '.panel-toggle', function() {
                const target = $(this).data('target');
                $('#' + target).slideToggle(200);
                $(this).find('.dashicons').toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
            });

            // Weight sliders - update display and totals
            $('[id^="weight-"]').on('input', function() {
                const id = $(this).attr('id');
                const value = $(this).val();
                $('#' + id + '-value').text(value + '%');
                self.updateWeightTotal();
            });

            // Save scraper config
            $('#save-scraper-config').on('click', function() {
                self.saveScraperConfig();
                showToast('✅ Scraper configuration saved', 'success');
            });

            // Reset scraper config
            $('#reset-scraper-config').on('click', function() {
                if (confirm('Reset scraper configuration to defaults?')) {
                    self.resetScraperConfig();
                    showToast('↺ Scraper configuration reset', 'info');
                }
            });

            // Save AI config
            $('#save-ai-config').on('click', function() {
                self.saveAIConfig();
                showToast('✅ AI configuration saved', 'success');
            });

            // Reset AI config
            $('#reset-ai-config').on('click', function() {
                if (confirm('Reset AI configuration to defaults?')) {
                    self.resetAIConfig();
                    showToast('↺ AI configuration reset', 'info');
                }
            });
        },

        /**
         * Update weight total display
         */
        updateWeightTotal: function() {
            const total = parseInt($('#weight-shocking').val()) +
                         parseInt($('#weight-unbelievable').val()) +
                         parseInt($('#weight-newsworthy').val()) +
                         parseInt($('#weight-unique').val());
            
            $('#weight-total').text(total + '%');
            
            if (total !== 100) {
                $('#weight-warning').show();
                $('#weight-total').css('color', '#dc3545');
            } else {
                $('#weight-warning').hide();
                $('#weight-total').css('color', '#28a745');
            }
        },

        /**
         * Save scraper configuration
         */
        saveScraperConfig: function() {
            const config = {
                sources: {},
                limits: {
                    items_per_source: parseInt($('#items-per-source').val()),
                    date_range: $('#date-range').val()
                },
                keywords: $('#keyword-filter').val()
            };

            $('input[name="source"]').each(function() {
                config.sources[$(this).val()] = $(this).is(':checked');
            });

            if (window.rawwireSyncManager) {
                window.rawwireSyncManager.config.sources = config.sources;
                window.rawwireSyncManager.config.limits = config.limits;
                window.rawwireSyncManager.config.keywords = config.keywords;
                window.rawwireSyncManager.saveConfig();
            }
        },

        /**
         * Reset scraper configuration
         */
        resetScraperConfig: function() {
            $('input[name="source"]').prop('checked', true);
            $('#items-per-source').val(20);
            $('#date-range').val('7d');
            $('#keyword-filter').val('');
            this.saveScraperConfig();
        },

        /**
         * Save AI configuration
         */
        saveAIConfig: function() {
            const config = {
                weights: {
                    shocking: parseInt($('#weight-shocking').val()),
                    unbelievable: parseInt($('#weight-unbelievable').val()),
                    newsworthy: parseInt($('#weight-newsworthy').val()),
                    unique: parseInt($('#weight-unique').val())
                },
                custom_instructions: $('#custom-ai-instructions').val(),
                model: $('#ai-model').val(),
                temperature: parseFloat($('#ai-temperature').val())
            };

            if (window.rawwireSyncManager) {
                window.rawwireSyncManager.config.ai = config;
                window.rawwireSyncManager.saveConfig();
            }
        },

        /**
         * Reset AI configuration
         */
        resetAIConfig: function() {
            $('#weight-shocking').val(25).trigger('input');
            $('#weight-unbelievable').val(25).trigger('input');
            $('#weight-newsworthy').val(25).trigger('input');
            $('#weight-unique').val(25).trigger('input');
            $('#custom-ai-instructions').val('');
            $('#ai-model').val('llama2');
            $('#ai-temperature').val(0.3);
            this.saveAIConfig();
        },

        /**
         * Load saved settings
         */
        loadSavedSettings: function() {
            if (!window.rawwireSyncManager) return;

            const config = window.rawwireSyncManager.config;

            // Load scraper settings
            if (config.sources) {
                Object.keys(config.sources).forEach(source => {
                    $(`input[name="source"][value="${source}"]`).prop('checked', config.sources[source]);
                });
            }

            if (config.limits) {
                $('#items-per-source').val(config.limits.items_per_source || 20);
                $('#date-range').val(config.limits.date_range || '7d');
            }

            $('#keyword-filter').val(config.keywords || '');

            // Load AI settings
            if (config.ai) {
                if (config.ai.weights) {
                    $('#weight-shocking').val(config.ai.weights.shocking || 25).trigger('input');
                    $('#weight-unbelievable').val(config.ai.weights.unbelievable || 25).trigger('input');
                    $('#weight-newsworthy').val(config.ai.weights.newsworthy || 25).trigger('input');
                    $('#weight-unique').val(config.ai.weights.unique || 25).trigger('input');
                }

                $('#custom-ai-instructions').val(config.ai.custom_instructions || '');
                $('#ai-model').val(config.ai.model || 'llama2');
                $('#ai-temperature').val(config.ai.temperature || 0.3);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Wait for sync manager to be initialized
        setTimeout(function() {
            RawWireControlPanels.init();
            console.log('✅ Control Panels initialized');
        }, 500);
    });

})(jQuery);
