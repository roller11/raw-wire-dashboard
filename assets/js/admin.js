/**
 * Admin JavaScript for RawWire Dashboard
 *
 * @since 1.0.18
 */

(function ($) {
    'use strict';

    /**
     * RawWire Admin
     */
    var RawWireAdmin = {

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.loadDashboardData();
            this.initAISettings();
            this.initApprovalsBatchPolling();
        },

        /**
         * Bind events
         */
        bindEvents: function () {
            // Sync button - handled by dashboard.js via REST API
            // $(document).on('click', '#rawwire-sync-btn', this.sync.bind(this));
            $(document).on('click', '#rawwire-clear-cache-btn', this.clearCache.bind(this));

            // Source toggle handlers - for supplementary dashboard controls
            $(document).on('change', '.source-checkbox', this.handleSourceToggle.bind(this));
            $(document).on('change', '.toolkit-source-checkbox', this.handleToolkitSourceToggle.bind(this));

            // Template panel action buttons (for Configured Sources panel)
            $(document).on('click', 'button[data-action="toggle_scraper_source"]', this.handleTemplateToggle.bind(this));
            $(document).on('click', 'button[data-action="test_scraper_source"]', this.handleTemplateTest.bind(this));

            // Content management - handled by dashboard.js via REST API
            // $(document).on('click', '.approve-btn, .reject-btn', this.updateContent.bind(this));

            // Panel functionality
            $(document).on('click', '.panel-header', this.togglePanel.bind(this));
            $(document).on('change', '.panel-control-toggle', this.handlePanelControl.bind(this));

            // Chat — handled by chat-panel.js + Venice Chat Handler
            // Legacy bindings removed in v1.0.26

            // Workflows — handled by workflow-handlers.php + dashboard.js
            // Legacy bindings removed in v1.0.26

            // Modules page
            $(document).on('click', '.configure-toolkit', this.openToolkitModal.bind(this));
            $(document).on('click', '.modal-close', this.closeToolkitModal.bind(this));
            $(document).on('change', '.toolkit-adapter-select', this.loadAdapterForm.bind(this));
            $(document).on('submit', '.toolkit-config-form', this.saveToolkitConfig.bind(this));
        },

        /**
         * Initialize AI settings behaviors
         */
        initAISettings: function () {
            var $envSelect = $('#default_env_id');
            var $modelInput = $('#default_model');
            var $modelList = $('#rawwire-model-list');
            var $refreshBtn = $('#rawwire-refresh-models');
            var $status = $('#rawwire-models-status');

            if (!$envSelect.length || typeof rawwire_ai_settings === 'undefined') {
                return;
            }

            var fetchModels = function () {
                var envId = $envSelect.val();
                $status.text('Loading models...');

                $.ajax({
                    url: rawwire_ai_settings.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'rawwire_ai_get_models',
                        nonce: rawwire_ai_settings.nonce,
                        envId: envId
                    },
                    success: function (response) {
                        $modelList.empty();
                        if (response.success && response.data && response.data.models) {
                            response.data.models.forEach(function (model) {
                                var modelId = model.model || model;
                                if (!modelId) return;
                                var label = model.name || modelId;
                                $modelList.append($('<option>', { value: modelId, text: label }));
                            });
                            $status.text('Models updated');
                        } else {
                            $status.text('No models found');
                        }
                    },
                    error: function () {
                        $status.text('Failed to load models');
                    }
                });
            };

            $envSelect.on('change', function () {
                fetchModels();
            });

            $refreshBtn.on('click', function () {
                fetchModels();
            });
        },

        /**
         * Approval batch polling — handled by workflow-handlers.php via REST.
         * Legacy 5s polling removed in v1.0.26.
         */
        initApprovalsBatchPolling: function () {
            // No-op: rawwire_get_last_batch is handled server-side now
        },

        /**
         * Load dashboard data
         */
        loadDashboardData: function () {
            this.updateStats();
            this.loadContentTable();
            this.loadPanelData();
        },

        /**
         * Generic module AJAX helper
         * @param {string} module
         * @param {string} action
         * @param {object} data
         * @param {function} success
         * @param {function} error
         * @param {function} complete
         */
        moduleAjax: function (module, action, data, success, error, complete) {
            var payload = data || {};
            $.ajax({
                url: rawwire_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rawwire_module_action',
                    nonce: rawwire_ajax.nonce,
                    module: module,
                    module_action: action,
                    data: payload
                },
                success: function (response) {
                    if (typeof success === 'function') success(response);
                },
                error: function (xhr, status, err) {
                    if (typeof error === 'function') error(xhr, status, err);
                },
                complete: function () {
                    if (typeof complete === 'function') complete();
                }
            });
        },

        /**
         * Update stats display
         */
        updateStats: function () {
            RawWireAdmin.moduleAjax('core', 'get_stats', {}, function (response) {
                if (response.success) {
                    // legacy fields fallback
                    var data = response.data || response;
                    $('.stat-total-content').text(data.total || data.total_content || 0);
                    $('.stat-active-sources').text(data.active_sources || 0);
                    $('.stat-pending-queue').text(data.pending || data.pending_queue || 0);
                    $('.stat-last-sync').text(data.last_sync || 'Never');
                } else {
                    RawWireAdmin.showError('Failed to load statistics: ' + (response.data || response.message || ''));
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showError('AJAX error loading stats: ' + err);
            });
        },

        /**
         * Load content table
         */
        loadContentTable: function () {
            RawWireAdmin.moduleAjax('core', 'get_content', { limit: 10 }, function (response) {
                if (response.success) {
                    RawWireAdmin.renderContentTable(response.data);
                } else {
                    RawWireAdmin.showError('Failed to load content: ' + (response.data || response.message || ''));
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showError('AJAX error loading content: ' + err);
            });
        },

        /**
         * Render content table
         */
        renderContentTable: function (data) {
            var tbody = $('.rawwire-content tbody');
            tbody.empty();

            if (data.length === 0) {
                tbody.append('<tr><td colspan="5">No content available</td></tr>');
                return;
            }

            data.forEach(function (item) {
                var row = '<tr>' +
                    '<td>' + RawWireAdmin.escapeHtml(item.title) + '</td>' +
                    '<td>' + RawWireAdmin.escapeHtml(item.source) + '</td>' +
                    '<td>' + RawWireAdmin.formatDate(item.created_at) + '</td>' +
                    '<td><span class="status-' + item.status + '">' + item.status + '</span></td>' +
                    '<td><button class="button button-small" data-id="' + item.id + '">View</button></td>' +
                    '</tr>';
                tbody.append(row);
            });
        },

        /**
         * Load panel data
         */
        loadPanelData: function () {
            this.loadOverviewData();
            this.loadSourcesData();
            this.loadQueueData();
            this.loadLogsData();
            this.loadInsightsData();
            this.loadDynamicPanels();
        },

        /**
         * Load dynamic panels that declare data-module and data-action on the server-rendered container
         */
        loadDynamicPanels: function () {
            var self = this;
            $('.panel-body-content[data-module][data-action]').each(function () {
                var container = $(this);
                var module = container.data('module');
                var action = container.data('action');
                var panelEl = container.closest('.panel');
                var panelId = panelEl.attr('id') || '';

                if (!module || !action) return;

                self.moduleAjax(module, action, { panel_id: panelId }, function (response) {
                    if (response.success) {
                        // If response.data contains HTML, render it, otherwise stringify
                        var out = response.data !== undefined ? response.data : response;
                        if (typeof out === 'string') {
                            container.html(out);
                        } else if (typeof out === 'object' && out.html) {
                            container.html(out.html);
                        } else {
                            container.html(JSON.stringify(out));
                        }
                    } else {
                        container.html('<div class="notice notice-error"><p>' + (response.data || response.message || 'Error loading panel') + '</p></div>');
                    }
                }, function (xhr, status, err) {
                    container.html('<div class="notice notice-error"><p>Error: ' + err + '</p></div>');
                });
            });
        },

        /**
         * Load overview data
         */
        loadOverviewData: function () {
            RawWireAdmin.moduleAjax('core', 'get_overview', {}, function (response) {
                if (response.success) {
                    RawWireAdmin.updateOverviewPanel(response.data);
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showPanelError('overview', err);
            });
        },

        /**
         * Update overview panel
         */
        updateOverviewPanel: function (data) {
            $('#overview-total-processed').text(data.total_processed || 0);
            $('#overview-active-workflows').text(data.active_workflows || 0);
            $('#overview-success-rate').text(data.success_rate || '0%');
            $('#overview-avg-response').text(data.avg_response || '0ms');
        },

        /**
         * Load sources data
         */
        loadSourcesData: function () {
            RawWireAdmin.moduleAjax('core', 'get_sources', {}, function (response) {
                if (response.success) {
                    RawWireAdmin.updateSourcesPanel(response.data);
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showPanelError('sources', err);
            });
        },

        /**
         * Update sources panel
         */
        updateSourcesPanel: function (data) {
            var list = $('#sources-list');
            list.empty();

            // Ensure data is an array. If module returned HTML or an object with HTML, render that.
            if (!Array.isArray(data)) {
                if (typeof data === 'string') {
                    list.html(data);
                    return;
                }
                if (data && typeof data.html === 'string') {
                    list.html(data.html);
                    return;
                }
                console.warn('updateSourcesPanel: data is not an array', data);
                list.text('Error: Invalid sources data');
                return;
            }

            if (data.length === 0) {
                list.text('No sources configured');
                return;
            }

            data.forEach(function (source) {
                var item = '<div class="source-item">' +
                    '<strong>' + RawWireAdmin.escapeHtml(source.name) + '</strong> - ' +
                    '<span class="status-' + source.status + '">' + source.status + '</span>' +
                    '</div>';
                list.append(item);
            });
        },

        /**
         * Handle template source toggle
         */
        handleSourceToggle: function (e) {
            var $checkbox = $(e.target);
            var sourceId = $checkbox.data('source-id');
            var enabled = $checkbox.is(':checked');
            var $item = $checkbox.closest('.source-item');

            this.toggleTemplateSource(sourceId, enabled, $item);
        },

        /**
         * Handle scraper toolkit source toggle
         */
        handleToolkitSourceToggle: function (e) {
            var $checkbox = $(e.target);
            var sourceId = $checkbox.data('source-id');
            var enabled = $checkbox.is(':checked');
            var $item = $checkbox.closest('.source-item');

            this.toggleScraperSource(sourceId, enabled, $item);
        },

        /**
         * Load queue data
         */
        loadQueueData: function () {
            RawWireAdmin.moduleAjax('core', 'get_queue', {}, function (response) {
                if (response.success) {
                    RawWireAdmin.updateQueuePanel(response.data);
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showPanelError('queue', err);
            });
        },

        /**
         * Update queue panel
         */
        updateQueuePanel: function (data) {
            $('#queue-pending').text(data.pending || 0);
            $('#queue-processing').text(data.processing || 0);
            $('#queue-completed').text(data.completed || 0);
            $('#queue-failed').text(data.failed || 0);
        },

        /**
         * Load logs data
         */
        loadLogsData: function () {
            RawWireAdmin.moduleAjax('core', 'get_logs', { limit: 20 }, function (response) {
                if (response.success) {
                    RawWireAdmin.updateLogsPanel(response.data);
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showPanelError('logs', err);
            });
        },

        /**
         * Update logs panel
         */
        updateLogsPanel: function (data) {
            var logs = $('#logs-container');
            logs.empty();

            // Ensure data is an array. Allow HTML or object-with-html fallbacks.
            if (!Array.isArray(data)) {
                if (typeof data === 'string') {
                    logs.html(data);
                    return;
                }
                if (data && typeof data.html === 'string') {
                    logs.html(data.html);
                    return;
                }
                console.warn('updateLogsPanel: data is not an array', data);
                logs.text('Error: Invalid log data');
                return;
            }

            if (data.length === 0) {
                logs.text('No recent activity');
                return;
            }

            data.forEach(function (log) {
                var logEntry = '[' + RawWireAdmin.formatDate(log.created_at) + '] ' +
                    log.level.toUpperCase() + ': ' + RawWireAdmin.escapeHtml(log.message) + '\n';
                logs.append(logEntry);
            });

            // Scroll to bottom
            logs.scrollTop(logs[0].scrollHeight);
        },

        /**
         * Load insights data
         */
        loadInsightsData: function () {
            RawWireAdmin.moduleAjax('core', 'get_insights', {}, function (response) {
                if (response.success) {
                    RawWireAdmin.updateInsightsPanel(response.data);
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showPanelError('insights', err);
            });
        },

        /**
         * Update insights panel
         */
        updateInsightsPanel: function (data) {
            $('#insights-top-categories').text(data.top_categories || 'None');
            $('#insights-peak-hours').text(data.peak_hours || 'N/A');
            $('#insights-avg-quality').text(data.avg_quality || '0%');
            $('#insights-trends').text(data.trends || 'No trends available');
        },

        /**
         * Clear cache
         */
        clearCache: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to clear the cache?')) {
                return;
            }

            var $btn = $(e.target).closest('button');
            var originalText = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-trash"></span> Clearing...')
                .addClass('rawwire-loading');

            RawWireAdmin.moduleAjax('core', 'clear_cache', {}, function (response) {
                RawWireAdmin.resetButton($btn, originalText);
                if (response.success) {
                    RawWireAdmin.showSuccess('Cache cleared successfully');
                } else {
                    RawWireAdmin.showError(response.data.message || response.message || 'Cache clear failed');
                }
            }, function () {
                RawWireAdmin.showError('Network error occurred');
                RawWireAdmin.resetButton($btn, originalText);
            });
        },

        /**
         * Toggle panel
         */
        togglePanel: function (e) {
            var header = $(e.target).closest('.panel-header');
            var panel = header.closest('.panel');
            var body = panel.find('.panel-body');

            body.slideToggle(200);
            panel.toggleClass('collapsed');
        },

        /**
         * Handle panel control
         */
        handlePanelControl: function (e) {
            var control = $(e.target);
            var action = control.data('action');
            var value = control.is(':checked') ? 1 : 0;
            var panel = control.closest('.panel');
            var panel_id = panel.length ? panel.attr('id') : '';
            var module = panel.find('.panel-body-content').data('module') || 'core';

            RawWireAdmin.moduleAjax(module, 'panel_control', { control_action: action, value: value, panel_id: panel_id, module: module }, function (response) {
                if (response.success) {
                    RawWireAdmin.showSuccess('Control updated successfully');
                } else {
                    RawWireAdmin.showError('Failed to update control: ' + (response.data || response.message || ''));
                    control.prop('checked', !value); // Revert on failure
                }
            }, function (xhr, status, err) {
                RawWireAdmin.showError('Error updating control: ' + err);
                control.prop('checked', !value); // Revert on failure
            });
        },

        // Legacy chat methods removed in v1.0.26 — see chat-panel.js

        // Legacy workflow methods removed in v1.0.26 — see workflow-handlers.php

        /**
         * Show panel error
         */
        showPanelError: function (panelId, error) {
            var panel = $('#' + panelId + '-panel');
            panel.addClass('rawwire-error');
            panel.find('.panel-body').prepend('<div class="notice notice-error"><p>Error loading ' + panelId + ' data: ' + error + '</p></div>');
        },

        /**
         * Show workflow error
         */
        showWorkflowError: function (message) {
            $('.workflow-body').prepend('<div class="notice notice-error"><p>' + message + '</p></div>');
        },

        /**
         * Show workflow success
         */
        showWorkflowSuccess: function (message) {
            $('.workflow-body').prepend('<div class="notice notice-success"><p>' + message + '</p></div>');
        },

        /**
         * Show error
         */
        showError: function (message) {
            RawWireAdmin.showNotice(message, 'error');
        },

        /**
         * Show success
         */
        showSuccess: function (message) {
            RawWireAdmin.showNotice(message, 'success');
        },

        /**
         * Show notice
         */
        showNotice: function (message, type) {
            var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.rawwire-dashboard').prepend(notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function () {
                notice.fadeOut(function () {
                    notice.remove();
                });
            }, 5000);
        },

        /**
         * Format date
         */
        formatDate: function (dateString) {
            if (!dateString) return 'N/A';
            var date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        /**
         * Open toolkit configuration modal
         */
        openToolkitModal: function (e) {
            e.preventDefault();
            var $btn = $(e.target);
            var moduleSlug = $btn.data('module');

            $('#toolkit-modal').show();
            $('#toolkit-config-content').html('<p>Loading configuration...</p>');

            // Load toolkit configuration for the module
            this.loadToolkitConfig(moduleSlug);
        },

        /**
         * Close toolkit configuration modal
         */
        closeToolkitModal: function (e) {
            e.preventDefault();
            $('#toolkit-modal').hide();
        },

        /**
         * Load toolkit configuration for a module
         */
        loadToolkitConfig: function (moduleSlug) {
            $.ajax({
                url: rawwire_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rawwire_module_toolkit_load',
                    nonce: rawwire_ajax.nonce,
                    module_slug: moduleSlug
                },
                success: function (response) {
                    if (response.success) {
                        $('#toolkit-config-content').html(response.data.html);
                    } else {
                        $('#toolkit-config-content').html('<p class="error">Error loading configuration: ' + response.data.message + '</p>');
                    }
                },
                error: function () {
                    $('#toolkit-config-content').html('<p class="error">Error loading configuration.</p>');
                }
            });
        },

        /**
         * Load adapter form when selection changes
         */
        loadAdapterForm: function (e) {
            var $select = $(e.target);
            var category = $select.data('category');
            var adapter = $select.val();
            var moduleSlug = $select.closest('.toolkit-config-form').data('module');

            if (!adapter) return;

            var $formContainer = $select.closest('.toolkit-category').find('.adapter-form-container');
            $formContainer.html('<p>Loading form...</p>');

            $.ajax({
                url: rawwire_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rawwire_module_toolkit_form',
                    nonce: rawwire_ajax.nonce,
                    module_slug: moduleSlug,
                    category: category,
                    adapter: adapter
                },
                success: function (response) {
                    if (response.success) {
                        $formContainer.html(response.data.html);
                    } else {
                        $formContainer.html('<p class="error">Error loading form: ' + response.data.message + '</p>');
                    }
                },
                error: function () {
                    $formContainer.html('<p class="error">Error loading form.</p>');
                }
            });
        },

        /**
         * Save toolkit configuration
         */
        saveToolkitConfig: function (e) {
            e.preventDefault();
            var $form = $(e.target);
            var moduleSlug = $form.data('module');
            var $submitBtn = $form.find('input[type="submit"]');
            var originalText = $submitBtn.val();

            $submitBtn.prop('disabled', true).val('Saving...');

            var formData = $form.serializeArray();
            var config = {};

            // Group form data by category
            $.each(formData, function (i, field) {
                if (field.name !== 'module_slug') {
                    var parts = field.name.split('_');
                    var category = parts[0];
                    var key = parts.slice(1).join('_');

                    if (!config[category]) config[category] = {};
                    config[category][key] = field.value;
                }
            });

            $.ajax({
                url: rawwire_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rawwire_module_toolkit_save',
                    nonce: rawwire_ajax.nonce,
                    module_slug: moduleSlug,
                    config: JSON.stringify(config)
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.showToast('Configuration saved successfully!', 'success');
                        $('#toolkit-modal').hide();
                    } else {
                        RawWireAdmin.showToast('Error saving configuration: ' + response.data.message, 'error');
                    }
                },
                error: function () {
                    RawWireAdmin.showToast('Error saving configuration.', 'error');
                },
                complete: function () {
                    $submitBtn.prop('disabled', false).val(originalText);
                }
            });
        },

        /**
         * Toast notification helper
         */
        showToast: function (message, type) {
            type = type || 'success';
            var toastClass = 'rawwire-toast rawwire-toast-' + type;
            var $toast = $('<div class="' + toastClass + '">' + RawWireAdmin.escapeHtml(message) + '</div>');

            // Create container if needed
            if (!$('#rawwire-toast-container').length) {
                $('body').append('<div id="rawwire-toast-container"></div>');
            }

            $('#rawwire-toast-container').append($toast);

            // Auto-dismiss after 3 seconds
            setTimeout(function () {
                $toast.fadeOut(300, function () { $(this).remove(); });
            }, 3000);
        },

        /**
         * Handle template panel toggle button click
         * For buttons with data-action="toggle_scraper_source"
         */
        handleTemplateToggle: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var $row = $btn.closest('tr');
            var sourceId = $row.data('item-id');
            var sourceName = $row.find('td:first').text().trim();

            // Determine current state from row or data attribute
            var isCurrentlyEnabled = $row.hasClass('enabled') || $btn.data('enabled') === true;
            var newState = !isCurrentlyEnabled;

            var self = this;

            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                    action: 'rawwire_toggle_scraper_source',
                    source_id: sourceId,
                    enabled: newState,
                    nonce: rawwire_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Update row state
                        $row.toggleClass('enabled', newState).toggleClass('disabled', !newState);
                        $btn.data('enabled', newState);

                        // Update enabled column display if it exists
                        var $enabledCell = $row.find('td').eq(2); // Third column (Enabled)
                        if ($enabledCell.length) {
                            $enabledCell.html(newState ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no-alt"></span>');
                        }

                        var status = newState ? 'enabled' : 'disabled';
                        self.showToast('Source "' + sourceName + '" ' + status, 'success');
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to toggle source';
                        self.showToast('Error: ' + errorMsg, 'error');
                    }
                },
                error: function (xhr, status, error) {
                    self.showToast('Error toggling source: ' + error, 'error');
                }
            });
        },

        /**
         * Handle template panel test button click
         * For buttons with data-action="test_scraper_source"
         */
        handleTemplateTest: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var $row = $btn.closest('tr');
            var sourceId = $row.data('item-id');
            var sourceName = $row.find('td:first').text().trim();

            // Check if source is enabled
            var isEnabled = $row.hasClass('enabled') || !$row.hasClass('disabled');
            if (!isEnabled) {
                this.showToast('Cannot test disabled source. Please enable it first.', 'error');
                return;
            }

            // Save original button state
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Testing...');

            var self = this;

            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                    action: 'rawwire_test_scraper_source',
                    source_id: sourceId,
                    nonce: rawwire_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var data = response.data || {};
                        var count = data.count || 0;
                        var message = 'Test successful for "' + sourceName + '": ' + count + ' items found';
                        self.showToast(message, 'success');

                        // Optionally refresh the dashboard data
                        if (typeof self.loadDashboardData === 'function') {
                            self.loadDashboardData();
                        }
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Test failed';
                        self.showToast('Error testing "' + sourceName + '": ' + errorMsg, 'error');
                    }
                },
                error: function (xhr, status, error) {
                    self.showToast('Error testing source: ' + error, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },

        /**
         * Toggle template source enable/disable
         */
        toggleTemplateSource: function (sourceId, enabled, $item) {
            var sourceName = $item.find('.source-name').text();
            var self = this;

            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                    action: 'rawwire_toggle_template_source',
                    source_id: sourceId,
                    enabled: enabled,
                    nonce: rawwire_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $item.toggleClass('enabled', enabled).toggleClass('disabled', !enabled);
                        var status = enabled ? 'enabled' : 'disabled';
                        self.showToast('Source "' + sourceName + '" ' + status, 'success');
                    } else {
                        // Revert toggle on error
                        $item.toggleClass('enabled', !enabled).toggleClass('disabled', enabled);
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to toggle source';
                        self.showToast('Error: ' + errorMsg, 'error');
                    }
                },
                error: function (xhr, status, error) {
                    // Revert toggle on error
                    $item.toggleClass('enabled', !enabled).toggleClass('disabled', enabled);
                    self.showToast('Error toggling source: ' + error, 'error');
                }
            });
        },

        /**
         * Toggle scraper toolkit source enable/disable
         */
        toggleScraperSource: function (sourceId, enabled, $item) {
            var sourceName = $item.find('.source-name').text();
            var self = this;

            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                    action: 'rawwire_toggle_scraper_source',
                    source_id: sourceId,
                    enabled: enabled,
                    nonce: rawwire_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $item.toggleClass('enabled', enabled).toggleClass('disabled', !enabled);
                        var status = enabled ? 'enabled' : 'disabled';
                        self.showToast('Source "' + sourceName + '" ' + status, 'success');
                    } else {
                        // Revert toggle on error
                        $item.toggleClass('enabled', !enabled).toggleClass('disabled', enabled);
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to toggle source';
                        self.showToast('Error: ' + errorMsg, 'error');
                    }
                },
                error: function (xhr, status, error) {
                    // Revert toggle on error
                    $item.toggleClass('enabled', !enabled).toggleClass('disabled', enabled);
                    self.showToast('Error toggling source: ' + error, 'error');
                }
            });
        },

        /**
         * Escape HTML
         */
        escapeHtml: function (text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
        },

        /**
         * Reset button to original state
         */
        resetButton: function ($btn, originalText) {
            $btn.prop('disabled', false)
                .html(originalText)
                .removeClass('rawwire-loading');
        }
    };

    // Initialize when document is ready
    $(document).ready(function () {
        RawWireAdmin.init();
    });

})(jQuery);