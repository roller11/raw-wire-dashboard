/**
 * Enhanced Sync Flow Manager
 * Provides comprehensive progress tracking, toast notifications, and error handling for sync operations
 * 
 * @package RawWire_Dashboard
 * @since 1.1.0
 */

(function($) {
    'use strict';

    // Enhanced Sync Manager Class
    window.RawWireSyncManager = class RawWireSyncManager {
        constructor() {
            this.syncing = false;
            this.progressInterval = null;
            this.currentStage = null;
            this.stages = {
                'init': { label: 'Initializing', icon: 'admin-generic', duration: 1000 },
                'fetch': { label: 'Fetching sources', icon: 'download', duration: 5000 },
                'analyze': { label: 'Analyzing with AI (this may take a few minutes)', icon: 'search', duration: 0 },
                'store': { label: 'Storing results', icon: 'database', duration: 1000 },
                'complete': { label: 'Complete', icon: 'yes-alt', duration: 0 }
            };
            this.retryCount = 0;
            this.maxRetries = 3;
            
            this.config = this.loadConfig();
        }

        /**
         * Load sync configuration from localStorage or defaults
         */
        loadConfig() {
            const saved = localStorage.getItem('rawwire_sync_config');
            if (saved) {
                try {
                    return JSON.parse(saved);
                } catch (e) {
                    console.warn('Failed to parse saved config, using defaults');
                }
            }
            
            return {
                sources: {
                    'federal_register_rules': true,
                    'federal_register_notices': true,
                    'whitehouse_briefings': true,
                    'whitehouse_statements': true,
                    'fda_news': true,
                    'epa_releases': true,
                    'doj_releases': true,
                    'sec_releases': true
                },
                limits: {
                    items_per_source: 20,
                    date_range: '7d' // '24h', '7d', '30d', 'all'
                },
                keywords: '',
                ai: {
                    weights: {
                        shocking: 25,
                        unbelievable: 25,
                        newsworthy: 25,
                        unique: 25
                    },
                    custom_instructions: '',
                    model: 'llama2',
                    temperature: 0.3
                }
            };
        }

        /**
         * Save configuration
         */
        saveConfig() {
            localStorage.setItem('rawwire_sync_config', JSON.stringify(this.config));
        }

        /**
         * Start sync with progress tracking
         */
        async startSync(button) {
            if (this.syncing) {
                showToast('⚠ Sync already in progress', 'warning');
                return;
            }

            this.syncing = true;
            this.retryCount = 0;
            const $btn = $(button);
            
            // Disable button
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Syncing...');
            
            // Show initial toast with accurate message
            showToast('🚀 Starting sync (scraping + AI analysis)...', 'info');
            
            try {
                // The fetch endpoint handles everything: scrape + AI analysis + storage
                // This is a synchronous operation that may take several minutes
                const fetchResult = await this.executeFetch();
                
                if (!fetchResult.success) {
                    throw new Error(fetchResult.message || 'Fetch failed');
                }
                
                // Show success message
                const count = fetchResult.count || fetchResult.stored || 0;
                window.showToast('✅ Sync complete - ' + count + ' items added to approvals', 'success');
                
                // Show warning if items were queued due to AI failures
                if (fetchResult.queued && fetchResult.queued > 0) {
                    window.showToast('⚠ ' + fetchResult.queued + ' items queued for retry (AI analysis failed)', 'warning');
                }
                
                // Refresh UI
                this.refreshDashboard();
                
            } catch (error) {
                console.error('Sync error:', error);
                
                // Retry logic
                if (this.retryCount < this.maxRetries) {
                    this.retryCount++;
                    window.showToast('Sync failed, retrying (' + this.retryCount + '/' + this.maxRetries + ')...', 'warning');
                    setTimeout(() => this.startSync(button), 2000);
                    return;
                }
                
                // Final failure
                window.showToast('❌ Sync failed: ' + error.message, 'error');
                
            } finally {
                this.syncing = false;
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Sources');
                this.stopProgressTracking();
            }
        }

        /**
         * Execute a sync stage with toast notification
         */
        async executeStage(stageName) {
            const stage = this.stages[stageName];
            if (!stage) return;
            
            this.currentStage = stageName;
            
            // Show stage toast - pass plain text, showToast will handle icons
            window.showToast(stage.label + '...', 'info');
            
            // Simulate stage duration (in real implementation, this would track actual progress)
            if (stage.duration > 0) {
                await new Promise(resolve => setTimeout(resolve, stage.duration));
            }
        }

        /**
         * Execute fetch with configuration
         */
        async executeFetch() {
            return new Promise((resolve, reject) => {
                const payload = {
                    config: this.config,
                    blocking: false // Use non-blocking for progress updates
                };

                $.ajax({
                    url: RawWireCfg.rest + '/fetch-data',
                    method: 'POST',
                    beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
                    data: JSON.stringify(payload),
                    contentType: 'application/json',
                    success: (response) => {
                        console.log('Fetch response:', response);
                        
                        if (response && response.success !== false) {
                            // Start progress polling if background job
                            if (!response.count) {
                                this.startProgressTracking();
                            }
                            resolve(response);
                        } else {
                            reject(new Error(response.message || 'Unknown error'));
                        }
                    },
                    error: (xhr) => {
                        const msg = (xhr.responseJSON && xhr.responseJSON.message) || 
                                   xhr.statusText || 
                                   'Network error';
                        reject(new Error(msg));
                    }
                });
            });
        }

        /**
         * Start progress tracking via polling
         */
        startProgressTracking() {
            if (this.progressInterval) {
                clearInterval(this.progressInterval);
            }

            // Create progress UI element if it doesn't exist
            this.ensureProgressUI();

            this.progressInterval = setInterval(() => {
                this.updateProgress();
            }, 2000); // Poll every 2 seconds
        }

        /**
         * Stop progress tracking
         */
        stopProgressTracking() {
            if (this.progressInterval) {
                clearInterval(this.progressInterval);
                this.progressInterval = null;
            }
            this.removeProgressUI();
        }

        /**
         * Update progress display
         */
        async updateProgress() {
            try {
                const response = await $.ajax({
                    url: RawWireCfg.rest + '/fetch-progress',
                    method: 'GET',
                    beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce)
                });

                if (response) {
                    this.displayProgress(response);
                    
                    // Check if complete
                    if (response.status === 'complete') {
                        this.stopProgressTracking();
                    }
                }
            } catch (error) {
                console.warn('Progress update failed:', error);
            }
        }

        /**
         * Display progress information
         */
        displayProgress(progress) {
            const $counter = $('#rawwire-progress-counter');
            if ($counter.length) {
                const percent = progress.total > 0 ? 
                    Math.round((progress.progress / progress.total) * 100) : 0;
                
                $counter.html(`
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${percent}%"></div>
                    </div>
                    <div class="progress-text">
                        ${progress.progress} / ${progress.total} items
                        <span class="progress-status">${progress.status}</span>
                    </div>
                `);
            }
        }

        /**
         * Ensure progress UI element exists
         */
        ensureProgressUI() {
            if ($('#rawwire-progress-counter').length === 0) {
                $('.rawwire-header').after(`
                    <div id="rawwire-progress-counter" class="rawwire-progress-counter">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%"></div>
                        </div>
                        <div class="progress-text">Initializing...</div>
                    </div>
                `);
            }
        }

        /**
         * Remove progress UI
         */
        removeProgressUI() {
            $('#rawwire-progress-counter').fadeOut(400, function() {
                $(this).remove();
            });
        }

        /**
         * Refresh dashboard data
         */
        refreshDashboard() {
            // Invalidate activity logs cache
            if (typeof activityLogsModule !== 'undefined' && activityLogsModule) {
                activityLogsModule.cache.info = null;
                activityLogsModule.cache.debug = null;
                if (['info', 'debug'].includes(activityLogsModule.currentTab)) {
                    activityLogsModule.loadTab(activityLogsModule.currentTab);
                }
            }

            // Update sync status
            if (typeof updateSyncStatus === 'function') {
                updateSyncStatus();
            }

            // Reload page after delay (fallback)
            setTimeout(() => {
                if (confirm('Sync complete! Reload page to see new content?')) {
                    location.reload();
                }
            }, 2000);
        }

        /**
         * Update configuration value
         */
        updateConfig(path, value) {
            const keys = path.split('.');
            let obj = this.config;
            
            for (let i = 0; i < keys.length - 1; i++) {
                if (!obj[keys[i]]) obj[keys[i]] = {};
                obj = obj[keys[i]];
            }
            
            obj[keys[keys.length - 1]] = value;
            this.saveConfig();
        }

        /**
         * Get configuration value
         */
        getConfig(path) {
            const keys = path.split('.');
            let obj = this.config;
            
            for (const key of keys) {
                if (obj === undefined) return undefined;
                obj = obj[key];
            }
            
            return obj;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Create global sync manager instance
        window.rawwireSyncManager = new RawWireSyncManager();
        
        // NOTE: Sync button handler is in dashboard.js (the authoritative handler)
        // This manager provides config management and helper methods only.
        // To use the enhanced sync: window.rawwireSyncManager.startSync(buttonElement)
        
        console.log('✅ Enhanced Sync Manager initialized (config-only mode)');
    });

})(jQuery);
