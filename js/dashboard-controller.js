/**
 * @ai-context Search Instinct MCP for "Dashboard Controller Function Map v3" before modifying this file.
 */

/**
 * Dashboard Controller - Mode switching, workflow launch, dynamic stats
 *
 * Manages the dashboard master switch (Collection / Information),
 * wires the progress bar workflow selector to the Socrata import tool,
 * and dynamically updates statistics panel based on active mode + workflow.
 *
 * Dependencies: jQuery, RawWireCfg (localized globals)
 *
 * @package RawWire_Dashboard
 * @since   1.0.15
 */
(function ($) {
    'use strict';

    // =========================================================================
    // DASHBOARD CONTROLLER
    // =========================================================================
    var DashboardController = {

        // Current state
        mode: 'collection',
        activeWorkflow: null,
        isRunning: false,
        pollTimer: null,

        // DOM cache
        $wrap: null,
        $modeSwitch: null,
        $statsPanel: null,
        $workflowBar: null,
        $logPanel: null,

        // =====================================================================
        // INITIALIZATION
        // =====================================================================
        init: function () {
            this.$wrap = $('.soothsayer-dashboard-wrap');
            if (!this.$wrap.length) {
                return; // Not on dashboard page
            }

            this.$modeSwitch = this.$wrap.find('.soothsayer-mode-switch');
            this.$statsPanel = this.$wrap.find('[data-stats-panel]');
            this.$workflowBar = this.$wrap.find('.rawwire-workflow-bar');
            this.$logPanel = this.$wrap.find('.rawwire-log-panel');

            // Read initial mode from wrapper
            this.mode = this.$wrap.attr('data-dashboard-mode') || 'collection';

            this.bindModeSwitch();
            this.bindWorkflowControls();
            this.applyMode(this.mode, false);

            // If a workflow is currently running, start polling
            if (this.$workflowBar.attr('data-status') === 'running') {
                this.isRunning = true;
                this.startPolling();
            }
        },

        // =====================================================================
        // MODE SWITCH (Collection / Information)
        // =====================================================================
        bindModeSwitch: function () {
            var self = this;
            this.$modeSwitch.on('click', '.soothsayer-mode-btn', function (e) {
                e.preventDefault();
                var newMode = $(this).attr('data-mode');
                if (newMode === self.mode) return;
                self.applyMode(newMode, true);
            });
        },

        /**
         * Apply dashboard mode - updates UI visibility and refreshes stats
         *
         * @param {string}  mode       'collection' or 'information'
         * @param {boolean} persist    Whether to save preference via AJAX
         */
        applyMode: function (mode, persist) {
            this.mode = mode;

            // Update wrapper attribute (CSS rules key off this)
            this.$wrap.attr('data-dashboard-mode', mode);

            // Update button active states
            this.$modeSwitch.attr('data-active-mode', mode);
            this.$modeSwitch.find('.soothsayer-mode-btn').removeClass('active');
            this.$modeSwitch.find('[data-mode="' + mode + '"]').addClass('active');

            // Refresh statistics for new mode
            this.refreshStats();

            // Persist choice via AJAX (silent, non-blocking)
            if (persist) {
                $.post(RawWireDashboardCtrl.ajaxurl, {
                    action: 'rawwire_set_dashboard_mode',
                    nonce: RawWireDashboardCtrl.nonce,
                    mode: mode
                });
            }
        },

        // =====================================================================
        // WORKFLOW CONTROLS (Progress bar Run/Stop + dropdown)
        // =====================================================================
        bindWorkflowControls: function () {
            var self = this;

            // Workflow selection change
            this.$wrap.on('change', '.rawwire-workflow-select', function () {
                var $opt = $(this).find(':selected');
                var workflowId = $(this).val();
                var toolId = $opt.attr('data-tool') || '';

                self.$workflowBar.attr('data-active-tool', toolId);
                self.$workflowBar.find('.rawwire-workflow-run, .rawwire-workflow-stop')
                    .attr('data-automation', workflowId)
                    .attr('data-tool', toolId);

                // Refresh stats when workflow changes
                self.activeWorkflow = workflowId;
                self.refreshStats();
            });

            // Run workflow button
            this.$wrap.on('click', '.rawwire-workflow-run', function (e) {
                e.preventDefault();
                if (self.isRunning) return;

                var automationId = $(this).attr('data-automation');
                var toolId = $(this).attr('data-tool') || '';
                self.launchWorkflow(automationId, toolId);
            });

            // Stop workflow button
            this.$wrap.on('click', '.rawwire-workflow-stop', function (e) {
                e.preventDefault();
                var automationId = $(this).attr('data-automation');
                var toolId = $(this).attr('data-tool') || '';
                self.stopWorkflow(automationId, toolId);
            });
        },

        /**
         * Launch a workflow - dispatches to the correct AJAX action
         * based on the automation's configured action
         *
         * @param {string} automationId  e.g. 'records_collection'
         * @param {string} toolId        e.g. 'lead_generator'
         */
        launchWorkflow: function (automationId, toolId) {
            var self = this;
            this.isRunning = true;
            this.activeWorkflow = automationId;
            this.updateProgressUI('running', 0, 'Launching...');

            // Start polling IMMEDIATELY so we can track progress
            // while the synchronous PHP workflow is executing.
            // The poll reads transients that are updated mid-execution.
            this.startPolling();

            $.ajax({
                url: RawWireDashboardCtrl.ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_dashboard_run_workflow',
                    nonce: RawWireDashboardCtrl.nonce,
                    automation_id: automationId,
                    tool_id: toolId
                },
                success: function (response) {
                    if (response.success) {
                        // Workflow finished — poll will catch the final state
                        // and stop itself. Just refresh stats.
                        self.refreshStats();
                    } else {
                        self.isRunning = false;
                        self.stopPolling();
                        self.updateProgressUI('error', 0, response.data && response.data.message ? response.data.message : 'Launch failed');
                        self.showToast('error', response.data && response.data.message ? response.data.message : 'Workflow failed to start');
                    }
                },
                error: function () {
                    self.isRunning = false;
                    self.stopPolling();
                    self.updateProgressUI('error', 0, 'Network error');
                    self.showToast('error', 'Network error launching workflow');
                }
            });
        },

        /**
         * Stop a running workflow
         */
        stopWorkflow: function (automationId, toolId) {
            var self = this;
            $.post(RawWireDashboardCtrl.ajaxurl, {
                action: 'rawwire_dashboard_stop_workflow',
                nonce: RawWireDashboardCtrl.nonce,
                automation_id: automationId,
                tool_id: toolId
            }, function (response) {
                self.isRunning = false;
                self.stopPolling();
                self.updateProgressUI('idle', 0, 'Ready');
                self.refreshStats();
            });
        },

        // =====================================================================
        // PROGRESS POLLING
        // =====================================================================
        startPolling: function () {
            var self = this;
            this.stopPolling();
            this.pollTimer = setInterval(function () {
                self.pollProgress();
            }, 3000);
        },

        stopPolling: function () {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        pollProgress: function () {
            var self = this;
            $.post(RawWireDashboardCtrl.ajaxurl, {
                action: 'rawwire_dashboard_poll_progress',
                nonce: RawWireDashboardCtrl.nonce
            }, function (response) {
                if (!response.success) return;

                var d = response.data;
                self.updateProgressUI(d.status, d.percent, d.label);

                // Also update stats with fresh counts
                if (d.stats) {
                    self.applyStatsData(d.stats);
                }

                // Done or error = stop polling
                if (d.status === 'complete' || d.status === 'error' || d.status === 'idle') {
                    self.isRunning = false;
                    self.stopPolling();
                    self.refreshStats();

                    if (d.status === 'complete') {
                        self.showToast('success', d.message || 'Workflow completed');
                    } else if (d.status === 'error') {
                        self.showToast('error', d.message || 'Workflow error');
                    }
                }
            });
        },

        /**
         * Update the progress bar UI
         */
        updateProgressUI: function (status, percent, label) {
            var statusLabels = {
                idle: 'Ready',
                running: 'Running',
                complete: 'Done',
                error: 'Error',
                paused: 'Paused'
            };

            this.$workflowBar.attr('data-status', status);
            this.$workflowBar.find('.rawwire-workflow-fill')
                .css('width', percent + '%');
            this.$workflowBar.find('.rawwire-workflow-status')
                .text(label || statusLabels[status] || status)
                .attr('class', 'rawwire-workflow-status rawwire-status-' + status);

            // Swap Run/Stop buttons
            var $runBtn = this.$workflowBar.find('.rawwire-workflow-run');
            var $stopBtn = this.$workflowBar.find('.rawwire-workflow-stop');
            if (status === 'running') {
                $runBtn.hide();
                if (!$stopBtn.length) {
                    var automationId = $runBtn.attr('data-automation') || '';
                    var toolId = $runBtn.attr('data-tool') || '';
                    $('<button type="button" class="rawwire-workflow-btn rawwire-workflow-stop" data-action="stop_workflow" data-automation="' + automationId + '" data-tool="' + toolId + '"><span class="dashicons dashicons-controls-pause"></span> Stop</button>')
                        .insertAfter($runBtn);
                } else {
                    $stopBtn.show();
                }
            } else {
                $stopBtn.hide();
                $runBtn.show().prop('disabled', false);
            }
        },

        // =====================================================================
        // DYNAMIC STATS
        // =====================================================================

        /**
         * Refresh stats panel via AJAX based on current mode + workflow
         */
        refreshStats: function () {
            var self = this;
            $.post(RawWireDashboardCtrl.ajaxurl, {
                action: 'rawwire_dashboard_get_stats',
                nonce: RawWireDashboardCtrl.nonce,
                mode: this.mode,
                workflow: this.activeWorkflow || ''
            }, function (response) {
                if (response.success && response.data) {
                    self.applyStatsData(response.data);
                }
            });
        },

        /**
         * Apply stats data to the DOM
         *
         * @param {Array} stats  Array of {id, label, value, icon, highlight}
         */
        applyStatsData: function (stats) {
            if (!stats || !stats.length) return;

            var $bar = this.$statsPanel;
            if (!$bar.length) return;

            // Update existing cards or rebuild
            stats.forEach(function (metric) {
                var $card = $bar.find('[data-metric-id="' + metric.id + '"]');
                if ($card.length) {
                    // Update existing card
                    $card.find('h3').text(metric.value);
                    $card.find('p').text(metric.label);
                } else {
                    // Mark cards not in new set for cleanup
                }
            });

            // If the metric set changed entirely, rebuild cards
            var existingIds = [];
            $bar.find('.rawwire-stat-card').each(function () {
                existingIds.push($(this).attr('data-metric-id'));
            });

            var newIds = stats.map(function (m) { return m.id; });
            var sameSet = existingIds.length === newIds.length &&
                existingIds.every(function (id, i) { return id === newIds[i]; });

            if (!sameSet) {
                // Rebuild stat cards
                $bar.find('.rawwire-stat-card').remove();
                var $actions = $bar.find('.rawwire-stats-actions');
                stats.forEach(function (metric) {
                    var classes = 'rawwire-stat-card';
                    if (metric.highlight) classes += ' rawwire-highlight-' + metric.highlight;

                    var html = '<div class="' + classes + '" data-metric-id="' + (metric.id || '') + '">';
                    if (metric.icon) {
                        html += '<span class="dashicons ' + metric.icon + '"></span>';
                    }
                    html += '<h3 data-metric="' + (metric.id || '') + '">' + (metric.value || '0') + '</h3>';
                    html += '<p>' + (metric.label || '') + '</p>';
                    html += '</div>';

                    if ($actions.length) {
                        $actions.before(html);
                    } else {
                        $bar.append(html);
                    }
                });
            }
        },

        // =====================================================================
        // UI HELPERS
        // =====================================================================

        /**
         * Show a toast notification
         */
        showToast: function (type, message) {
            // Reuse existing toast function if available
            if (typeof window.showToast === 'function') {
                window.showToast(type, message);
                return;
            }

            var $toast = $('<div class="rawwire-dashboard-toast rawwire-toast-' + type + '">' +
                '<span class="dashicons dashicons-' + (type === 'success' ? 'yes' : 'warning') + '"></span>' +
                '<span>' + message + '</span>' +
                '</div>');
            $('body').append($toast);
            setTimeout(function () { $toast.addClass('visible'); }, 50);
            setTimeout(function () {
                $toast.removeClass('visible');
                setTimeout(function () { $toast.remove(); }, 300);
            }, 4000);
        }
    };

    // =========================================================================
    // INIT ON DOCUMENT READY
    // =========================================================================
    $(document).ready(function () {
        DashboardController.init();
    });

})(jQuery);
