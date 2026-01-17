/**
 * Activity Logs JavaScript
 *
 * Handles tabbed interface and AJAX operations for activity logs
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/js
 * @since      1.0.11
 */

(function($) {
    'use strict';

    /**
     * RawWire Activity Logs Manager
     */
    var ActivityLogsManager = {

        /**
         * Initialize the activity logs interface
         */
        init: function() {
            this.bindEvents();
            this.loadInitialLogs();
            this.loadInfoPanel();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Tab switching
            $('.tab-button').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                self.switchTab(tab);
            });

            // Refresh logs
            $('#refresh-logs').on('click', function(e) {
                e.preventDefault();
                self.refreshCurrentTab();
            });

            // Clear logs
            $('#clear-logs').on('click', function(e) {
                e.preventDefault();
                self.confirmClearLogs();
            });

            // Handle pagination (if implemented)
            $(document).on('click', '.log-pagination a', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                self.loadLogsPage(page);
            });
        },

        /**
         * Switch to a different tab
         */
        switchTab: function(tab) {
            // Update tab buttons
            $('.tab-button').removeClass('active');
            $('.tab-button[data-tab="' + tab + '"]').addClass('active');

            // Update tab content
            $('.tab-pane').removeClass('active');
            $('#' + tab + '-tab').addClass('active');

            // Load logs for this tab
            this.loadLogs(tab);
        },

        /**
         * Load logs for a specific tab
         */
        loadLogs: function(type, page) {
            page = page || 1;

            var container = $('.logs-container[data-type="' + type + '"]');
            var loadingText = (typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.loading) ? RawWireLogsConfig.strings.loading : 'Loading logs...';
            var loadingHtml = '<div class="logs-loading">' +
                '<span class="spinner is-active"></span> ' +
                loadingText +
                '</div>';

            // Show loading state
            container.html(loadingHtml);

            console.log('ActivityLogs.loadLogs called with type:', type, 'RawWireLogsConfig:', typeof RawWireLogsConfig !== 'undefined' ? 'defined' : 'undefined');
            console.log('AJAX URL:', (typeof RawWireLogsConfig !== 'undefined' ? RawWireLogsConfig.ajax_url : 'undefined'));

            // Make AJAX request
            $.ajax({
                url: (typeof RawWireLogsConfig !== 'undefined' ? RawWireLogsConfig.ajax_url : ajaxurl),
                type: 'POST',
                data: {
                    action: 'rawwire_get_activity_logs',
                    nonce: (typeof RawWireLogsConfig !== 'undefined' ? RawWireLogsConfig.nonce : ''),
                    type: type,
                    page: page,
                    per_page: 50
                },
                success: function(response) {
                    console.log('AJAX success response:', response);
                    if (response && response.success) {
                            var logs = (response.data && Array.isArray(response.data.logs)) ? response.data.logs : [];
                            ActivityLogsManager.renderLogs(container, logs, type);
                        } else {
                            console.error('AJAX error in response:', response && response.data ? response.data : response);
                            var errMsg = 'Error loading logs.';
                            if (response && response.data && response.data.message) {
                                errMsg = response.data.message;
                            } else if (typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.error_loading) {
                                errMsg = RawWireLogsConfig.strings.error_loading;
                            }
                            ActivityLogsManager.showError(container, errMsg);
                        }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error, 'Status:', status, 'XHR:', xhr);
                    ActivityLogsManager.showError(container, (typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.error_loading) ? RawWireLogsConfig.strings.error_loading : 'Error loading logs.');
                }
            });
        },

        /**
         * Load the info panel data via AJAX
         */
        loadInfoPanel: function() {
            var panel = $('#rawwire-info-panel');
            if (!panel.length) { 
                console.log('Info panel not found');
                return; 
            }

            console.log('Loading info panel...');

            // Show provisional loading
            panel.find('.info-grid').html('<div class="info-item">Loading...</div>');

            $.ajax({
                url: (typeof RawWireLogsConfig !== 'undefined' ? RawWireLogsConfig.ajax_url : ajaxurl),
                type: 'POST',
                data: {
                    action: 'rawwire_get_activity_info',
                    nonce: (typeof RawWireLogsConfig !== 'undefined' ? RawWireLogsConfig.nonce : '')
                },
                success: function(response) {
                    console.log('Info panel AJAX response:', response);
                    if (response.success) {
                        var data = response.data;
                        panel.find('.info-item[data-key="wp_version"]').text('WordPress: ' + (data.wp_version || 'unknown'));
                        panel.find('.info-item[data-key="php_version"]').text('PHP: ' + (data.php_version || 'unknown'));
                        panel.find('.info-item[data-key="db_version"]').text('MySQL: ' + (data.db_version || 'unknown'));
                        panel.find('.info-item[data-key="plugin_version"]').text('Plugin: ' + (data.plugin_version || 'unknown'));
                        panel.find('.info-item[data-key="last_migration"]').text('Last Migration: ' + (data.last_migration || '0'));
                        panel.find('.info-item[data-key="recent_errors"]').text('Recent Errors (24h): ' + (data.recent_errors || 0));
                    } else {
                        console.error('Info panel error:', response.data);
                        panel.find('.info-grid').html('<div class="info-item">Error loading info.</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Info panel AJAX error:', error, 'Status:', status);
                    panel.find('.info-grid').html('<div class="info-item">Error loading info.</div>');
                }
            });
        },

        /**
         * Render logs in the container
         */
        renderLogs: function(container, logs, type) {
            if (!logs || logs.length === 0) {
                var noLogsText = (typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.no_logs) ? RawWireLogsConfig.strings.no_logs : 'No logs found.';
                container.html('<div class="log-empty">' +
                    '<div class="dashicons dashicons-info"></div>' +
                    '<p>' + noLogsText + '</p>' +
                    '</div>');
                return;
            }

            var html = '<div class="log-entries">';

            logs.forEach(function(log) {
                html += ActivityLogsManager.renderLogEntry(log);
            });

            html += '</div>';
            container.html(html);
        },

        /**
         * Render a single log entry
         */
        renderLogEntry: function(log) {
            var severityClass = log.level ? log.level.toLowerCase() : 'info';
            var badgeClass = 'log-badge ' + severityClass;
            var timeDisplay = log.time_ago || log.timestamp || 'Unknown';
            var message = log.message || '';
            var level = (log.level || 'info').toUpperCase();
            var contextHtml = '';

            // Format context if it exists
            if (log.context && typeof log.context === 'object' && Object.keys(log.context).length > 0) {
                contextHtml = '<div class="log-context"><small><strong>Context:</strong> ' + JSON.stringify(log.context).substring(0, 100) + '...</small></div>';
            }

            // Escape HTML and format
            var safeMessage = $('<div>').text(message).html();
            var safeLevel = $('<div>').text(level).html();

            var html = '<div class="log-entry">' +
                '<div class="log-time">' + timeDisplay + '</div>' +
                '<div class="log-level">' +
                    '<span class="' + badgeClass + '">' + safeLevel + '</span>' +
                '</div>' +
                '<div class="log-message">' +
                    safeMessage +
                    contextHtml +
                '</div>' +
            '</div>';

            return html;
        },

        /**
         * Show error state
         */
        showError: function(container, message) {
            container.html('<div class="log-empty">' +
                '<div class="dashicons dashicons-warning"></div>' +
                '<p>' + message + '</p>' +
                '</div>');
        },

        /**
         * Refresh the current active tab
         */
        refreshCurrentTab: function() {
            var activeTab = $('.tab-button.active').data('tab');
            if (activeTab) {
                this.loadLogs(activeTab);
            }
        },

        /**
         * Load initial logs on page load
         */
        loadInitialLogs: function() {
            // Load the default (info) tab
            this.loadLogs('info');
        },

        /**
         * Load a specific page of logs
         */
        loadLogsPage: function(page) {
            var activeTab = $('.tab-button.active').data('tab');
            if (activeTab) {
                this.loadLogs(activeTab, page);
            }
        },

        /**
         * Confirm and clear all logs
         */
        confirmClearLogs: function() {
            var clearConfirm = (typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.clear_confirm) ? RawWireLogsConfig.strings.clear_confirm : 'Are you sure you want to clear all activity logs?';
            if (confirm(clearConfirm)) {
                this.clearLogs();
            }
        },

        /**
         * Clear all logs via AJAX
         */
        clearLogs: function() {
            var self = this;
            var button = $('#clear-logs');
            var originalText = button.html();

            // Show loading state
            button.prop('disabled', true).html('<span class="spinner is-active"></span> Clearing...');

            $.ajax({
                url: RawWireLogsConfig.ajax_url,
                type: 'POST',
                data: {
                    action: 'rawwire_clear_activity_logs',
                    nonce: (typeof RawWireLogsConfig !== 'undefined' ? RawWireLogsConfig.nonce : '')
                },
                success: function(response) {
                    if (response.success) {
                        // Refresh the current tab
                        self.refreshCurrentTab();

                        // Show success message (you could implement a toast notification here)
                        console.log((typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.clear_success) ? RawWireLogsConfig.strings.clear_success : 'Logs cleared successfully.');
                    } else {
                        alert(response.data.message || ((typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.clear_error) ? RawWireLogsConfig.strings.clear_error : 'Error clearing logs.'));
                    }
                },
                error: function(xhr, status, error) {
                    alert((typeof RawWireLogsConfig !== 'undefined' && RawWireLogsConfig.strings && RawWireLogsConfig.strings.clear_error) ? RawWireLogsConfig.strings.clear_error : 'Error clearing logs.');
                    console.error('Clear logs AJAX error:', error);
                },
                complete: function() {
                    // Restore button state
                    button.prop('disabled', false).html(originalText);
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        ActivityLogsManager.init();
    });

    // Expose manager to global scope for debugging without overwriting localized config
    window.RawWireActivityLogsManager = ActivityLogsManager;

})(jQuery);