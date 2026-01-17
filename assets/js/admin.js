/**
 * Admin JavaScript for RawWire Dashboard
 *
 * @since 1.0.18
 */

(function($) {
    'use strict';

    /**
     * RawWire Admin
     */
    var RawWireAdmin = {

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.loadDashboardData();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Sync button - handled by dashboard.js via REST API
            // $(document).on('click', '#rawwire-sync-btn', this.sync.bind(this));
            $(document).on('click', '#rawwire-clear-cache-btn', this.clearCache.bind(this));

            // Content management - handled by dashboard.js via REST API
            // $(document).on('click', '.approve-btn, .reject-btn', this.updateContent.bind(this));

            // Panel functionality
            $(document).on('click', '.panel-header', this.togglePanel.bind(this));
            $(document).on('change', '.panel-control-toggle', this.handlePanelControl.bind(this));

            // Chat interface
            $(document).on('click', '.rawwire-chat-toggle', this.toggleChat.bind(this));
            $(document).on('click', '.chat-close', this.closeChat.bind(this));
            $(document).on('click', '.chat-send', this.sendChatMessage.bind(this));
            $(document).on('keypress', '.chat-input', this.handleChatKeypress.bind(this));

            // Workflow windows
            $(document).on('click', '.rawwire-workflow-search', this.openSearchWorkflow.bind(this));
            $(document).on('click', '.rawwire-workflow-generative', this.openGenerativeWorkflow.bind(this));
            $(document).on('click', '.workflow-close', this.closeWorkflow.bind(this));
            $(document).on('click', '.workflow-execute', this.executeWorkflow.bind(this));
            $(document).on('click', '.workflow-cancel', this.cancelWorkflow.bind(this));

            // Modules page
            $(document).on('click', '.configure-toolkit', this.openToolkitModal.bind(this));
            $(document).on('click', '.modal-close', this.closeToolkitModal.bind(this));
            $(document).on('change', '.toolkit-adapter-select', this.loadAdapterForm.bind(this));
            $(document).on('submit', '.toolkit-config-form', this.saveToolkitConfig.bind(this));
        },

        /**
         * Load dashboard data
         */
        loadDashboardData: function() {
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
        moduleAjax: function(module, action, data, success, error, complete) {
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
                success: function(response) {
                    if (typeof success === 'function') success(response);
                },
                error: function(xhr, status, err) {
                    if (typeof error === 'function') error(xhr, status, err);
                },
                complete: function() {
                    if (typeof complete === 'function') complete();
                }
            });
        },

        /**
         * Update stats display
         */
        updateStats: function() {
            RawWireAdmin.moduleAjax('core', 'get_stats', {}, function(response) {
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
            }, function(xhr, status, err) {
                RawWireAdmin.showError('AJAX error loading stats: ' + err);
            });
        },

        /**
         * Load content table
         */
        loadContentTable: function() {
            RawWireAdmin.moduleAjax('core', 'get_content', { limit: 10 }, function(response) {
                if (response.success) {
                    RawWireAdmin.renderContentTable(response.data);
                } else {
                    RawWireAdmin.showError('Failed to load content: ' + (response.data || response.message || ''));
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showError('AJAX error loading content: ' + err);
            });
        },

        /**
         * Render content table
         */
        renderContentTable: function(data) {
            var tbody = $('.rawwire-content tbody');
            tbody.empty();

            if (data.length === 0) {
                tbody.append('<tr><td colspan="5">No content available</td></tr>');
                return;
            }

            data.forEach(function(item) {
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
        loadPanelData: function() {
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
        loadDynamicPanels: function() {
            var self = this;
            $('.panel-body-content[data-module][data-action]').each(function() {
                var container = $(this);
                var module = container.data('module');
                var action = container.data('action');
                var panelEl = container.closest('.panel');
                var panelId = panelEl.attr('id') || '';

                if (!module || !action) return;

                self.moduleAjax(module, action, { panel_id: panelId }, function(response) {
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
                }, function(xhr, status, err) {
                    container.html('<div class="notice notice-error"><p>Error: ' + err + '</p></div>');
                });
            });
        },

        /**
         * Load overview data
         */
        loadOverviewData: function() {
            RawWireAdmin.moduleAjax('core', 'get_overview', {}, function(response) {
                if (response.success) {
                    RawWireAdmin.updateOverviewPanel(response.data);
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showPanelError('overview', err);
            });
        },

        /**
         * Update overview panel
         */
        updateOverviewPanel: function(data) {
            $('#overview-total-processed').text(data.total_processed || 0);
            $('#overview-active-workflows').text(data.active_workflows || 0);
            $('#overview-success-rate').text(data.success_rate || '0%');
            $('#overview-avg-response').text(data.avg_response || '0ms');
        },

        /**
         * Load sources data
         */
        loadSourcesData: function() {
            RawWireAdmin.moduleAjax('core', 'get_sources', {}, function(response) {
                if (response.success) {
                    RawWireAdmin.updateSourcesPanel(response.data);
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showPanelError('sources', err);
            });
        },

        /**
         * Update sources panel
         */
        updateSourcesPanel: function(data) {
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

            data.forEach(function(source) {
                var item = '<div class="source-item">' +
                    '<strong>' + RawWireAdmin.escapeHtml(source.name) + '</strong> - ' +
                    '<span class="status-' + source.status + '">' + source.status + '</span>' +
                    '</div>';
                list.append(item);
            });
        },

        /**
         * Load queue data
         */
        loadQueueData: function() {
            RawWireAdmin.moduleAjax('core', 'get_queue', {}, function(response) {
                if (response.success) {
                    RawWireAdmin.updateQueuePanel(response.data);
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showPanelError('queue', err);
            });
        },

        /**
         * Update queue panel
         */
        updateQueuePanel: function(data) {
            $('#queue-pending').text(data.pending || 0);
            $('#queue-processing').text(data.processing || 0);
            $('#queue-completed').text(data.completed || 0);
            $('#queue-failed').text(data.failed || 0);
        },

        /**
         * Load logs data
         */
        loadLogsData: function() {
            RawWireAdmin.moduleAjax('core', 'get_logs', { limit: 20 }, function(response) {
                if (response.success) {
                    RawWireAdmin.updateLogsPanel(response.data);
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showPanelError('logs', err);
            });
        },

        /**
         * Update logs panel
         */
        updateLogsPanel: function(data) {
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

            data.forEach(function(log) {
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
        loadInsightsData: function() {
            RawWireAdmin.moduleAjax('core', 'get_insights', {}, function(response) {
                if (response.success) {
                    RawWireAdmin.updateInsightsPanel(response.data);
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showPanelError('insights', err);
            });
        },

        /**
         * Update insights panel
         */
        updateInsightsPanel: function(data) {
            $('#insights-top-categories').text(data.top_categories || 'None');
            $('#insights-peak-hours').text(data.peak_hours || 'N/A');
            $('#insights-avg-quality').text(data.avg_quality || '0%');
            $('#insights-trends').text(data.trends || 'No trends available');
        },

        /**
         * Clear cache
         */
        clearCache: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to clear the cache?')) {
                return;
            }

            var $btn = $(e.target).closest('button');
            var originalText = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-trash"></span> Clearing...')
                .addClass('rawwire-loading');

            RawWireAdmin.moduleAjax('core', 'clear_cache', {}, function(response) {
                RawWireAdmin.resetButton($btn, originalText);
                if (response.success) {
                    RawWireAdmin.showSuccess('Cache cleared successfully');
                } else {
                    RawWireAdmin.showError(response.data.message || response.message || 'Cache clear failed');
                }
            }, function() {
                RawWireAdmin.showError('Network error occurred');
                RawWireAdmin.resetButton($btn, originalText);
            });
        },

        /**
         * Update content status
         */
        updateContent: function(e) {
            e.preventDefault();

            var $btn = $(e.target);
            var $row = $btn.closest('tr');
            var id = $btn.data('id');
            var status = $btn.data('status');

            $btn.prop('disabled', true);

            RawWireAdmin.moduleAjax('core', 'update_content', { id: id, status: status }, function(response) {
                if (response.success) {
                    // Update status display
                    $row.find('.status')
                        .removeClass('status-pending status-approved status-rejected')
                        .addClass('status-' + status)
                        .text(status.charAt(0).toUpperCase() + status.slice(1));

                    // Update stats
                    RawWireAdmin.updateStats();
                    RawWireAdmin.showSuccess('Content updated successfully');
                } else {
                    RawWireAdmin.showError(response.data.message || response.message || 'Update failed');
                }
            }, function() {
                RawWireAdmin.showError('Network error occurred');
            }, function() {
                $btn.prop('disabled', false);
            });
        },

        /**
         * Toggle panel
         */
        togglePanel: function(e) {
            var header = $(e.target).closest('.panel-header');
            var panel = header.closest('.panel');
            var body = panel.find('.panel-body');

            body.slideToggle(200);
            panel.toggleClass('collapsed');
        },

        /**
         * Handle panel control
         */
        handlePanelControl: function(e) {
            var control = $(e.target);
            var action = control.data('action');
            var value = control.is(':checked') ? 1 : 0;
            var panel = control.closest('.panel');
            var panel_id = panel.length ? panel.attr('id') : '';
            var module = panel.find('.panel-body-content').data('module') || 'core';

            RawWireAdmin.moduleAjax(module, 'panel_control', { control_action: action, value: value, panel_id: panel_id, module: module }, function(response) {
                if (response.success) {
                    RawWireAdmin.showSuccess('Control updated successfully');
                } else {
                    RawWireAdmin.showError('Failed to update control: ' + (response.data || response.message || ''));
                    control.prop('checked', !value); // Revert on failure
                }
            }, function(xhr, status, err) {
                RawWireAdmin.showError('Error updating control: ' + err);
                control.prop('checked', !value); // Revert on failure
            });
        },

        /**
         * Toggle chat
         */
        toggleChat: function(e) {
            e.preventDefault();
            $('.rawwire-chat').toggleClass('open');
        },

        /**
         * Close chat
         */
        closeChat: function(e) {
            e.preventDefault();
            $('.rawwire-chat').removeClass('open');
        },

        /**
         * Send chat message
         */
        sendChatMessage: function(e) {
            e.preventDefault();
            var input = $('.chat-input');
            var message = input.val().trim();

            if (!message) return;

            RawWireAdmin.addChatMessage(message, 'user');
            input.val('');

            // Send to AI
            RawWireAdmin.sendToAI(message);
        },

        /**
         * Handle chat keypress
         */
        handleChatKeypress: function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                RawWireAdmin.sendChatMessage(e);
            }
        },

        /**
         * Add chat message
         */
        addChatMessage: function(message, type) {
            var messages = $('.chat-messages');
            var messageEl = $('<div class="chat-message ' + type + '">' + RawWireAdmin.escapeHtml(message) + '</div>');
            messages.append(messageEl);
            messages.scrollTop(messages[0].scrollHeight);
        },

        /**
         * Send message to AI
         */
        sendToAI: function(message) {
            RawWireAdmin.addChatMessage('Thinking...', 'assistant');
            RawWireAdmin.moduleAjax('core', 'ai_chat', { message: message }, function(response) {
                $('.chat-message.assistant').last().remove();

                if (response.success) {
                    RawWireAdmin.addChatMessage(response.data, 'assistant');
                } else {
                    RawWireAdmin.addChatMessage('Error: ' + (response.data || response.message || ''), 'assistant');
                }
            }, function(xhr, status, error) {
                $('.chat-message.assistant').last().remove();
                RawWireAdmin.addChatMessage('Network error: ' + error, 'assistant');
            });
        },

        /**
         * Open search workflow
         */
        openSearchWorkflow: function(e) {
            e.preventDefault();
            RawWireAdmin.openWorkflow('search');
        },

        /**
         * Open generative workflow
         */
        openGenerativeWorkflow: function(e) {
            e.preventDefault();
            RawWireAdmin.openWorkflow('generative');
        },

        /**
         * Open workflow window
         */
        openWorkflow: function(type) {
            var modal = $('.rawwire-workflow-modal');
            var title = type === 'search' ? 'Search AI Workflow' : 'Generative AI Workflow';

            $('.workflow-header h3').text(title);
            modal.data('type', type);
            modal.addClass('open');

            // Load workflow configuration
            RawWireAdmin.loadWorkflowConfig(type);
        },

        /**
         * Close workflow
         */
        closeWorkflow: function(e) {
            e.preventDefault();
            $('.rawwire-workflow-modal').removeClass('open');
        },

        /**
         * Load workflow config
         */
        loadWorkflowConfig: function(type) {
            RawWireAdmin.moduleAjax('core', 'get_workflow_config', { type: type }, function(response) {
                if (response.success) {
                    RawWireAdmin.populateWorkflowForm(response.data);
                } else {
                    RawWireAdmin.showWorkflowError('Failed to load workflow config: ' + (response.data || response.message || ''));
                }
            }, function(xhr, status, error) {
                RawWireAdmin.showWorkflowError('Error loading workflow config: ' + error);
            });
        },

        /**
         * Populate workflow form
         */
        populateWorkflowForm: function(config) {
            // Populate form fields based on config
            if (config.models) {
                var modelSelect = $('#workflow-model');
                modelSelect.empty();
                config.models.forEach(function(model) {
                    modelSelect.append('<option value="' + model.id + '">' + model.name + '</option>');
                });
            }

            if (config.parameters) {
                // Populate parameter fields
                Object.keys(config.parameters).forEach(function(key) {
                    var field = $('#workflow-' + key);
                    if (field.length) {
                        field.val(config.parameters[key]);
                    }
                });
            }
        },

        /**
         * Execute workflow
         */
        executeWorkflow: function(e) {
            e.preventDefault();
            var modal = $('.rawwire-workflow-modal');
            var type = modal.data('type');
            var button = $(e.target);

            button.addClass('rawwire-loading').prop('disabled', true);

            var formData = RawWireAdmin.getWorkflowFormData();

            RawWireAdmin.moduleAjax('core', 'execute_workflow', { type: type, config: formData }, function(response) {
                button.removeClass('rawwire-loading').prop('disabled', false);

                if (response.success) {
                    RawWireAdmin.showWorkflowSuccess('Workflow executed successfully');
                    RawWireAdmin.updateWorkflowLogs(response.data.logs);
                    RawWireAdmin.updateWorkflowStatus('completed');
                } else {
                    RawWireAdmin.showWorkflowError('Workflow execution failed: ' + (response.data || response.message || ''));
                    RawWireAdmin.updateWorkflowStatus('failed');
                }
            }, function(xhr, status, error) {
                button.removeClass('rawwire-loading').prop('disabled', false);
                RawWireAdmin.showWorkflowError('Workflow execution error: ' + error);
                RawWireAdmin.updateWorkflowStatus('error');
            });
        },

        /**
         * Cancel workflow
         */
        cancelWorkflow: function(e) {
            e.preventDefault();
            var modal = $('.rawwire-workflow-modal');
            var type = modal.data('type');

            RawWireAdmin.moduleAjax('core', 'cancel_workflow', { type: type }, function(response) {
                if (response.success) {
                    RawWireAdmin.showWorkflowSuccess('Workflow cancelled');
                    RawWireAdmin.updateWorkflowStatus('cancelled');
                } else {
                    RawWireAdmin.showWorkflowError('Failed to cancel workflow: ' + (response.data || response.message || ''));
                }
            }, function(xhr, status, error) {
                RawWireAdmin.showWorkflowError('Error cancelling workflow: ' + error);
            });
        },

        /**
         * Get workflow form data
         */
        getWorkflowFormData: function() {
            return {
                model: $('#workflow-model').val(),
                temperature: $('#workflow-temperature').val(),
                max_tokens: $('#workflow-max-tokens').val(),
                prompt: $('#workflow-prompt').val(),
                input_data: $('#workflow-input').val()
            };
        },

        /**
         * Update workflow logs
         */
        updateWorkflowLogs: function(logs) {
            var logsContainer = $('.workflow-logs');
            logsContainer.empty();

            if (logs && logs.length > 0) {
                logs.forEach(function(log) {
                    logsContainer.append('<div>' + RawWireAdmin.escapeHtml(log) + '</div>');
                });
            } else {
                logsContainer.text('No logs available');
            }
        },

        /**
         * Update workflow status
         */
        updateWorkflowStatus: function(status) {
            var statusEl = $('.workflow-status');
            statusEl.text('Status: ' + status);

            statusEl.removeClass('status-completed status-failed status-error status-cancelled');
            statusEl.addClass('status-' + status);
        },

        /**
         * Show panel error
         */
        showPanelError: function(panelId, error) {
            var panel = $('#' + panelId + '-panel');
            panel.addClass('rawwire-error');
            panel.find('.panel-body').prepend('<div class="notice notice-error"><p>Error loading ' + panelId + ' data: ' + error + '</p></div>');
        },

        /**
         * Show workflow error
         */
        showWorkflowError: function(message) {
            $('.workflow-body').prepend('<div class="notice notice-error"><p>' + message + '</p></div>');
        },

        /**
         * Show workflow success
         */
        showWorkflowSuccess: function(message) {
            $('.workflow-body').prepend('<div class="notice notice-success"><p>' + message + '</p></div>');
        },

        /**
         * Show error
         */
        showError: function(message) {
            RawWireAdmin.showNotice(message, 'error');
        },

        /**
         * Show success
         */
        showSuccess: function(message) {
            RawWireAdmin.showNotice(message, 'success');
        },

        /**
         * Show notice
         */
        showNotice: function(message, type) {
            var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.rawwire-dashboard').prepend(notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut(function() {
                    notice.remove();
                });
            }, 5000);
        },

        /**
         * Format date
         */
        formatDate: function(dateString) {
            if (!dateString) return 'N/A';
            var date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        },

        /**
         * Open toolkit configuration modal
         */
        openToolkitModal: function(e) {
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
        closeToolkitModal: function(e) {
            e.preventDefault();
            $('#toolkit-modal').hide();
        },

        /**
         * Load toolkit configuration for a module
         */
        loadToolkitConfig: function(moduleSlug) {
            $.ajax({
                url: rawwire_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rawwire_module_toolkit_load',
                    nonce: rawwire_ajax.nonce,
                    module_slug: moduleSlug
                },
                success: function(response) {
                    if (response.success) {
                        $('#toolkit-config-content').html(response.data.html);
                    } else {
                        $('#toolkit-config-content').html('<p class="error">Error loading configuration: ' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $('#toolkit-config-content').html('<p class="error">Error loading configuration.</p>');
                }
            });
        },

        /**
         * Load adapter form when selection changes
         */
        loadAdapterForm: function(e) {
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
                success: function(response) {
                    if (response.success) {
                        $formContainer.html(response.data.html);
                    } else {
                        $formContainer.html('<p class="error">Error loading form: ' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $formContainer.html('<p class="error">Error loading form.</p>');
                }
            });
        },

        /**
         * Save toolkit configuration
         */
        saveToolkitConfig: function(e) {
            e.preventDefault();
            var $form = $(e.target);
            var moduleSlug = $form.data('module');
            var $submitBtn = $form.find('input[type="submit"]');
            var originalText = $submitBtn.val();

            $submitBtn.prop('disabled', true).val('Saving...');

            var formData = $form.serializeArray();
            var config = {};

            // Group form data by category
            $.each(formData, function(i, field) {
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
                success: function(response) {
                    if (response.success) {
                        alert('Configuration saved successfully!');
                        $('#toolkit-modal').hide();
                    } else {
                        alert('Error saving configuration: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Error saving configuration.');
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).val(originalText);
                }
            });
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        },

        /**
         * Reset button to original state
         */
        resetButton: function($btn, originalText) {
            $btn.prop('disabled', false)
                .html(originalText)
                .removeClass('rawwire-loading');
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        RawWireAdmin.init();
    });

})(jQuery);