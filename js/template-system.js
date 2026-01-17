/**
 * RawWire Template System JavaScript
 * Handles template interactions, page actions, and workflow operations
 */

(function($) {
    'use strict';

    // Namespace for RawWire Admin
    window.RawWireAdmin = window.RawWireAdmin || {};

    /**
     * Core Template System
     */
    RawWireAdmin.Template = {
        currentPage: null,
        templateData: {},

        /**
         * Initialize the template system
         */
        init: function() {
            this.bindEvents();
            this.initVariantSwitcher();
            this.initTemplateSwitcher();
        },

        /**
         * Bind global events
         */
        bindEvents: function() {
            // Modal close handlers
            $(document).on('click', '.rawwire-modal-overlay', function(e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    RawWireAdmin.Modal.close($(this));
                }
            });

            $(document).on('click', '.rawwire-modal-close', function() {
                RawWireAdmin.Modal.close($(this).closest('.rawwire-modal-overlay'));
            });

            // ESC key to close modals
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('.rawwire-modal-overlay:visible').hide();
                }
            });

            // Control panel button handler (template-driven)
            $(document).on('click', '.rawwire-btn[data-action]', function(e) {
                e.preventDefault();
                e.stopImmediatePropagation(); // Prevent other delegated handlers from firing
                var $btn = $(this);
                var action = $btn.data('action');
                var confirmMsg = $btn.data('confirm');
                
                // Confirm if needed
                if (confirmMsg && !confirm(confirmMsg)) {
                    return;
                }
                
                // Dispatch to the appropriate handler
                RawWireAdmin.Controls.handleAction(action, $btn);
            });

            // Page action buttons
            $(document).on('click', '[data-page-action]', function() {
                var action = $(this).data('page-action');
                var actionHandler = $(this).data('action');
                RawWireAdmin.Actions.handle(action, actionHandler);
            });

            // Panel action buttons
            $(document).on('click', '[data-panel-action]', function() {
                var action = $(this).data('panel-action');
                var panelId = $(this).closest('.rawwire-panel').data('panel-id');
                RawWireAdmin.Panels.handleAction(action, panelId);
            });

            // Card action buttons
            $(document).on('click', '[data-card-action]', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var action = $(this).data('card-action');
                var itemId = $(this).data('item-id');
                var card = $(this).closest('.rawwire-card');
                RawWireAdmin.Cards.handleAction(action, itemId, card);
            });

            // Card expand/collapse buttons (Show More)
            $(document).on('click', '.rawwire-expand-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $btn = $(this);
                var $expandContainer = $btn.closest('.rawwire-card-expand');
                var $expandedContent = $expandContainer.find('.rawwire-card-expanded');
                var $icon = $btn.find('.dashicons');
                var $text = $btn.find('.rawwire-expand-text');
                
                $expandedContent.slideToggle(200, function() {
                    var isExpanded = $expandedContent.is(':visible');
                    $text.text(isExpanded ? 'Show Less' : 'Show More');
                    $icon.toggleClass('dashicons-arrow-down-alt2', !isExpanded);
                    $icon.toggleClass('dashicons-arrow-up-alt2', isExpanded);
                });
            });
        },

        /**
         * Initialize variant switcher
         */
        initVariantSwitcher: function() {
            $('#rawwire-variant-selector').on('change', function() {
                var variant = $(this).val();
                RawWireAdmin.Template.switchVariant(variant);
            });
        },

        /**
         * Initialize template switcher
         */
        initTemplateSwitcher: function() {
            $('#rawwire-template-switcher').on('click', function() {
                RawWireAdmin.Modal.show('#rawwire-template-modal');
                RawWireAdmin.Template.loadTemplateList();
            });

            // Template card selection
            $(document).on('click', '.rawwire-template-card', function() {
                var templateId = $(this).data('template-id');
                if (templateId !== RawWireAdmin.Template.templateData.id) {
                    RawWireAdmin.Template.confirmSwitch(templateId);
                }
            });
        },

        /**
         * Switch variant
         */
        switchVariant: function(variant) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_variant',
                    variant: variant,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Apply new CSS
                        $('#rawwire-template-css').html(response.data.css);
                        RawWireAdmin.Notify.success('Theme variant updated');
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Failed to change variant');
                    }
                }
            });
        },

        /**
         * Load template list
         */
        loadTemplateList: function() {
            var $list = $('#rawwire-template-list');
            $list.html('<p class="loading"><span class="rawwire-spinner"></span> Loading templates...</p>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_list',
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var html = '';
                        response.data.templates.forEach(function(tpl) {
                            var activeClass = tpl.id === response.data.active ? 'active' : '';
                            html += '<div class="rawwire-template-card ' + activeClass + '" data-template-id="' + tpl.id + '">';
                            html += '<div class="rawwire-template-card-icon"><span class="dashicons dashicons-layout"></span></div>';
                            html += '<div class="rawwire-template-card-name">' + tpl.name + '</div>';
                            html += '<div class="rawwire-template-card-desc">' + (tpl.description || '') + '</div>';
                            html += '</div>';
                        });
                        $list.html(html);
                    } else {
                        $list.html('<p class="error">Failed to load templates</p>');
                    }
                }
            });
        },

        /**
         * Confirm template switch
         */
        confirmSwitch: function(templateId) {
            var backupData = $('#rawwire-backup-data').is(':checked');

            if (backupData) {
                // Download data first, then confirm switch
                RawWireAdmin.Template.downloadData(function() {
                    RawWireAdmin.Template.showSwitchConfirm(templateId);
                });
            } else {
                RawWireAdmin.Template.showSwitchConfirm(templateId);
            }
        },

        /**
         * Show switch confirmation dialog
         */
        showSwitchConfirm: function(templateId) {
            RawWireAdmin.Modal.close('#rawwire-template-modal');
            RawWireAdmin.Modal.confirm(
                'Are you sure you want to switch templates? All current configuration and data will be erased.',
                function() {
                    RawWireAdmin.Template.doSwitch(templateId);
                }
            );
        },

        /**
         * Perform template switch
         */
        doSwitch: function(templateId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_switch',
                    template_id: templateId,
                    nonce: rawwire_admin.nonce
                },
                beforeSend: function() {
                    RawWireAdmin.Notify.info('Switching template...');
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Template switched successfully. Reloading...');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Failed to switch template');
                    }
                }
            });
        },

        /**
         * Download template data
         */
        downloadData: function(callback) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_export_data',
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Create download
                        var blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = 'rawwire-data-export-' + new Date().toISOString().split('T')[0] + '.json';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        RawWireAdmin.Notify.success('Data downloaded');
                        if (callback) callback();
                    } else {
                        RawWireAdmin.Notify.error('Failed to export data');
                    }
                }
            });
        }
    };

    /**
     * Modal Management
     */
    RawWireAdmin.Modal = {
        show: function(selector) {
            $(selector).fadeIn(200);
        },

        close: function(element) {
            if (typeof element === 'string') {
                $(element).fadeOut(200);
            } else {
                element.fadeOut(200);
            }
        },

        confirm: function(message, onConfirm, onCancel) {
            var $modal = $('#rawwire-confirm-modal');
            $('#rawwire-confirm-message').text(message);

            $modal.show();

            $modal.find('.rawwire-confirm-ok').off('click').on('click', function() {
                RawWireAdmin.Modal.close($modal);
                if (onConfirm) onConfirm();
            });

            $modal.find('.rawwire-confirm-cancel').off('click').on('click', function() {
                RawWireAdmin.Modal.close($modal);
                if (onCancel) onCancel();
            });
        }
    };

    /**
     * Panel Management
     */
    RawWireAdmin.Panels = {
        handleAction: function(action, panelId) {
            switch (action) {
                case 'refresh':
                    this.refresh(panelId);
                    break;
                case 'expand':
                    this.toggleExpand(panelId);
                    break;
                default:
                    // Trigger custom action
                    $(document).trigger('rawwire:panel:action', [action, panelId]);
            }
        },

        refresh: function(panelId) {
            var $panel = $('[data-panel-id="' + panelId + '"]');
            var $content = $panel.find('.rawwire-panel-content');

            $content.html('<div class="rawwire-loading"><span class="rawwire-spinner"></span></div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_panel_refresh',
                    panel_id: panelId,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $content.html(response.data.html);
                    } else {
                        $content.html('<div class="error">Failed to refresh panel</div>');
                    }
                }
            });
        },

        toggleExpand: function(panelId) {
            var $panel = $('[data-panel-id="' + panelId + '"]');
            $panel.toggleClass('rawwire-panel-expanded');
        }
    };

    /**
     * Control Panel Button Actions (template-driven)
     * Handles actions defined in template control panels
     */
    RawWireAdmin.Controls = {
        /**
         * Handle control button action
         * @param {string} action - Action name from template
         * @param {jQuery} $btn - The button element
         */
        handleAction: function(action, $btn) {
            var originalHtml = $btn.html();
            
            switch (action) {
                case 'trigger_sync':
                    this.triggerSync($btn, originalHtml);
                    break;
                case 'clear_cache':
                    this.clearCache($btn, originalHtml);
                    break;
                case 'clear_workflow_tables':
                    this.clearWorkflowTables($btn, originalHtml);
                    break;
                case 'start_workflow':
                    this.startWorkflow($btn, originalHtml);
                    break;
                default:
                    // Generic AJAX action - send to module dispatcher
                    this.genericAction(action, $btn, originalHtml);
            }
        },

        /**
         * Clear all workflow tables via REST API
         */
        clearWorkflowTables: function($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Clearing...');
            
            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + '/clear-workflow-tables',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    success: function(response) {
                        if (response.success) {
                            RawWireAdmin.Notify.success(response.message || 'All tables cleared');
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.error || 'Clear failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function(xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to clear tables';
                        RawWireAdmin.Notify.error(msg);
                        RawWireAdmin.Controls.resetButton($btn, originalHtml);
                    }
                });
            } else {
                RawWireAdmin.Notify.error('Configuration not available');
                RawWireAdmin.Controls.resetButton($btn, originalHtml);
            }
        },

        /**
         * Start workflow via REST API
         */
        startWorkflow: function($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Starting...');
            
            // Gather workflow config from control panel
            var panel = $btn.closest('.rawwire-panel');
            var payload = {
                scraper: panel.find('[name="workflow:scraper"], [data-binding="workflow:scraper"]').val() || 'github',
                scorer: panel.find('[name="workflow:scorer"], [data-binding="workflow:scorer"]').val() || 'keyword',
                max_records: parseInt(panel.find('[name="workflow:max_records"], [data-binding="workflow:max_records"]').val()) || 10,
                target_table: panel.find('[name="workflow:target_table"], [data-binding="workflow:target_table"]').val() || 'candidates',
                auto_approve_threshold: parseInt(panel.find('[name="workflow:auto_approve"], [data-binding="workflow:auto_approve"]').val()) || 0,
                async: false
            };
            
            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + '/workflow/start',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    success: function(response) {
                        if (response.success) {
                            var msg = 'Scraped ' + (response.items_scraped || 0) + ' items';
                            if (response.items_stored !== undefined) {
                                msg += ', stored ' + response.items_stored;
                                // Show dedup info if different
                                var skipped = (response.items_scraped || 0) - (response.items_stored || 0);
                                if (skipped > 0) {
                                    msg += ' (' + skipped + ' duplicates)';
                                }
                            }
                            if (response.items_approved !== undefined || response.items_archived !== undefined) {
                                msg += ' → ' + (response.items_approved || 0) + ' approved, ' + (response.items_archived || 0) + ' archived';
                            }
                            RawWireAdmin.Notify.success(msg);
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.error || 'Workflow failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function(xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to start workflow';
                        RawWireAdmin.Notify.error(msg);
                        RawWireAdmin.Controls.resetButton($btn, originalHtml);
                    }
                });
            } else {
                RawWireAdmin.Notify.error('Configuration not available');
                RawWireAdmin.Controls.resetButton($btn, originalHtml);
            }
        },

        /**
         * Trigger sync/fetch from sources
         * @deprecated Use startWorkflow() instead. This redirects to workflow/start endpoint.
         */
        triggerSync: function($btn, originalHtml) {
            // Redirect to the new workflow system
            console.log('[RawWire] triggerSync deprecated - redirecting to startWorkflow');
            this.startWorkflow($btn, originalHtml);
        },

        /**
         * Clear cache
         */
        clearCache: function($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-trash"></span> Clearing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_clear_cache',
                    nonce: rawwire_admin ? rawwire_admin.nonce : ''
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Cache cleared successfully');
                    } else {
                        RawWireAdmin.Notify.error(response.data || 'Cache clear failed');
                    }
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                },
                error: function() {
                    RawWireAdmin.Notify.error('Network error clearing cache');
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                }
            });
        },

        /**
         * Generic action handler - sends to module AJAX dispatcher
         */
        genericAction: function(action, $btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Processing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_module_action',
                    module: 'core',
                    module_action: action,
                    nonce: rawwire_admin ? rawwire_admin.nonce : ''
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success(response.data || 'Action completed');
                    } else {
                        RawWireAdmin.Notify.error(response.data || 'Action failed');
                    }
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                },
                error: function() {
                    RawWireAdmin.Notify.error('Network error');
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                }
            });
        },

        /**
         * Reset button to original state
         */
        resetButton: function($btn, originalHtml) {
            $btn.prop('disabled', false).html(originalHtml);
        }
    };

    /**
     * Card Actions
     */
    RawWireAdmin.Cards = {
        handleAction: function(action, itemId, $card) {
            switch (action) {
                case 'approve':
                    this.approve(itemId, $card);
                    break;
                case 'reject':
                    this.reject(itemId, $card);
                    break;
                case 'view':
                    this.view(itemId);
                    break;
                case 'edit':
                    this.edit(itemId);
                    break;
                case 'generate':
                    this.openGenerator(itemId);
                    break;
                case 'publish':
                    this.openPublisher(itemId);
                    break;
                default:
                    $(document).trigger('rawwire:card:action', [action, itemId]);
            }
        },

        approve: function(itemId, $card) {
            this.updateStatus(itemId, 'approved', $card);
        },

        reject: function(itemId, $card) {
            RawWireAdmin.Modal.confirm('Are you sure you want to reject this item?', function() {
                RawWireAdmin.Cards.updateStatus(itemId, 'rejected', $card);
            });
        },

        updateStatus: function(itemId, status, $card) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_workflow_update',
                    item_id: itemId,
                    status: status,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (status === 'approved') {
                            RawWireAdmin.Notify.success('Item approved');
                            $card.addClass('approved').fadeOut(300, function() {
                                $(this).remove();
                            });
                        } else if (status === 'rejected') {
                            RawWireAdmin.Notify.info('Item rejected');
                            $card.addClass('rejected').fadeOut(300, function() {
                                $(this).remove();
                            });
                        }
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Action failed');
                    }
                }
            });
        },

        view: function(itemId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_item_detail',
                    item_id: itemId,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#rawwire-item-title').text(response.data.title || 'Item Details');
                        $('#rawwire-item-content').html(response.data.content);
                        $('#rawwire-item-actions').html(response.data.actions || '');
                        RawWireAdmin.Modal.show('#rawwire-item-modal');
                    }
                }
            });
        },

        edit: function(itemId) {
            // Open edit interface
            window.location.href = rawwire_admin.edit_url + '&item=' + itemId;
        },

        openGenerator: function(itemId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_item_detail',
                    item_id: itemId,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#rawwire-gen-input').val(response.data.content_text || response.data.excerpt || '');
                        $('#rawwire-item-modal').data('item-id', itemId);
                        $('#rawwire-gen-output-group').hide();
                        $('#rawwire-gen-save').hide();
                        RawWireAdmin.Modal.show('#rawwire-generator-modal');
                    }
                }
            });
        },

        openPublisher: function(itemId) {
            RawWireAdmin.Publisher.load(itemId);
        }
    };

    /**
     * Page Actions
     */
    RawWireAdmin.Actions = {
        handle: function(action, handler) {
            switch (action) {
                case 'run_scraper':
                    this.runScraper();
                    break;
                case 'approve_all':
                    this.bulkAction('approve');
                    break;
                case 'reject_all':
                    this.bulkAction('reject');
                    break;
                case 'publish_all':
                    this.bulkAction('publish');
                    break;
                case 'refresh_all':
                    this.refreshAll();
                    break;
                default:
                    // Custom action handler
                    if (handler) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: handler,
                                page: RawWireAdmin.Template.currentPage,
                                nonce: rawwire_admin.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    RawWireAdmin.Notify.success(response.data.message || 'Action completed');
                                    if (response.data.reload) {
                                        window.location.reload();
                                    }
                                } else {
                                    RawWireAdmin.Notify.error(response.data.message || 'Action failed');
                                }
                            }
                        });
                    }
            }
        },

        runScraper: function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_toolbox_run',
                    toolbox: 'scraper',
                    nonce: rawwire_admin.nonce
                },
                beforeSend: function() {
                    RawWireAdmin.Notify.info('Running scraper...');
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Scraper completed: ' + (response.data.count || 0) + ' items found');
                        RawWireAdmin.Actions.refreshAll();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Scraper failed');
                    }
                }
            });
        },

        bulkAction: function(action) {
            var $selected = $('[data-selected="true"]');
            if ($selected.length === 0) {
                RawWireAdmin.Notify.warning('No items selected');
                return;
            }

            var ids = [];
            $selected.each(function() {
                ids.push($(this).data('item-id'));
            });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_bulk_action',
                    bulk_action: action,
                    item_ids: ids,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Bulk action completed');
                        RawWireAdmin.Actions.refreshAll();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Bulk action failed');
                    }
                }
            });
        },

        refreshAll: function() {
            $('.rawwire-panel').each(function() {
                var panelId = $(this).data('panel-id');
                if (panelId) {
                    RawWireAdmin.Panels.refresh(panelId);
                }
            });
        }
    };

    /**
     * AI Generator
     */
    RawWireAdmin.Generator = {
        init: function() {
            $('#rawwire-gen-mode').on('change', function() {
                var mode = $(this).val();
                $('.rawwire-gen-options').hide();
                $('.rawwire-gen-options[data-mode="' + mode + '"]').show();
            });

            $('#rawwire-gen-run').on('click', function() {
                RawWireAdmin.Generator.run();
            });

            $('#rawwire-gen-save').on('click', function() {
                RawWireAdmin.Generator.save();
            });
        },

        run: function() {
            var mode = $('#rawwire-gen-mode').val();
            var input = $('#rawwire-gen-input').val();
            var options = {};

            if (mode === 'rewrite') {
                options.audience = $('#rawwire-gen-audience').val();
            } else if (mode === 'expand') {
                options.word_count = $('#rawwire-gen-wordcount').val();
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_toolbox_run',
                    toolbox: 'generator',
                    mode: mode,
                    input: input,
                    options: JSON.stringify(options),
                    nonce: rawwire_admin.nonce
                },
                beforeSend: function() {
                    $('#rawwire-gen-run').prop('disabled', true).text('Generating...');
                },
                success: function(response) {
                    $('#rawwire-gen-run').prop('disabled', false).html('<span class="dashicons dashicons-admin-generic"></span> Generate');

                    if (response.success) {
                        $('#rawwire-gen-output').val(response.data.output);
                        $('#rawwire-gen-output-group').show();
                        $('#rawwire-gen-save').show();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Generation failed');
                    }
                }
            });
        },

        save: function() {
            var itemId = $('#rawwire-generator-modal').data('item-id');
            var content = $('#rawwire-gen-output').val();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_item_update',
                    item_id: itemId,
                    field: 'generated_content',
                    value: content,
                    move_stage: 'release',
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Content saved and moved to release queue');
                        RawWireAdmin.Modal.close('#rawwire-generator-modal');
                        RawWireAdmin.Actions.refreshAll();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Save failed');
                    }
                }
            });
        }
    };

    /**
     * Publisher
     */
    RawWireAdmin.Publisher = {
        init: function() {
            $('#rawwire-publish-schedule').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#rawwire-publish-custom-time').show();
                } else {
                    $('#rawwire-publish-custom-time').hide();
                }
            });

            $('#rawwire-publish-confirm').on('click', function() {
                RawWireAdmin.Publisher.publish();
            });
        },

        load: function(itemId) {
            $('#rawwire-publish-modal').data('item-id', itemId);

            // Load outlets from template config
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_get_outlets',
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var html = '';
                        response.data.outlets.forEach(function(outlet) {
                            html += '<label class="rawwire-toggle">';
                            html += '<input type="checkbox" name="outlet" value="' + outlet.id + '" ' + (outlet.default ? 'checked' : '') + '>';
                            html += '<span class="rawwire-toggle-switch"></span>';
                            html += '<span class="rawwire-toggle-label">' + outlet.name + '</span>';
                            html += '</label>';
                        });
                        $('#rawwire-publish-outlets').html(html);
                    }
                }
            });

            RawWireAdmin.Modal.show('#rawwire-publish-modal');
        },

        publish: function() {
            var itemId = $('#rawwire-publish-modal').data('item-id');
            var outlets = [];
            $('#rawwire-publish-outlets input:checked').each(function() {
                outlets.push($(this).val());
            });

            var schedule = $('#rawwire-publish-schedule').val();
            var scheduleTime = null;

            if (schedule === 'custom') {
                scheduleTime = $('#rawwire-publish-datetime').val();
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_toolbox_run',
                    toolbox: 'poster',
                    item_id: itemId,
                    outlets: JSON.stringify(outlets),
                    schedule: schedule,
                    schedule_time: scheduleTime,
                    nonce: rawwire_admin.nonce
                },
                beforeSend: function() {
                    $('#rawwire-publish-confirm').prop('disabled', true).text('Publishing...');
                },
                success: function(response) {
                    $('#rawwire-publish-confirm').prop('disabled', false).html('<span class="dashicons dashicons-share"></span> Publish');

                    if (response.success) {
                        RawWireAdmin.Notify.success(response.data.message || 'Published successfully');
                        RawWireAdmin.Modal.close('#rawwire-publish-modal');
                        RawWireAdmin.Actions.refreshAll();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Publish failed');
                    }
                }
            });
        }
    };

    /**
     * Notifications
     */
    RawWireAdmin.Notify = {
        show: function(message, type) {
            var $notice = $('<div class="rawwire-notice rawwire-notice-' + type + '"></div>');
            $notice.html('<span class="dashicons dashicons-' + this.getIcon(type) + '"></span> ' + message);

            $('body').append($notice);
            $notice.css({
                position: 'fixed',
                top: '50px',
                right: '20px',
                zIndex: 100001,
                minWidth: '300px',
                animation: 'slideInRight 0.3s ease'
            });

            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },

        getIcon: function(type) {
            var icons = {
                success: 'yes-alt',
                error: 'warning',
                warning: 'info',
                info: 'info-outline'
            };
            return icons[type] || 'info';
        },

        success: function(message) { this.show(message, 'success'); },
        error: function(message) { this.show(message, 'error'); },
        warning: function(message) { this.show(message, 'warning'); },
        info: function(message) { this.show(message, 'info'); }
    };

    /**
     * Page Initialization
     */
    RawWireAdmin.initPage = function(pageId) {
        RawWireAdmin.Template.currentPage = pageId;

        // Initialize page-specific features
        switch (pageId) {
            case 'dashboard':
                RawWireAdmin.initDashboard();
                break;
            case 'approvals':
                RawWireAdmin.initApprovals();
                break;
            case 'release':
                RawWireAdmin.initRelease();
                break;
            case 'settings':
                RawWireAdmin.initSettings();
                break;
        }

        // Trigger custom init event
        $(document).trigger('rawwire:page:init', [pageId]);
    };

    RawWireAdmin.initDashboard = function() {
        // Dashboard-specific initialization
        // Auto-refresh stats every 60 seconds
        setInterval(function() {
            RawWireAdmin.Panels.refresh('stats');
        }, 60000);
    };

    RawWireAdmin.initApprovals = function() {
        // Selection handling for bulk actions
        $(document).on('click', '.rawwire-card', function(e) {
            if (!$(e.target).is('button') && !$(e.target).closest('button').length) {
                $(this).toggleClass('selected');
                $(this).attr('data-selected', $(this).hasClass('selected'));
            }
        });
    };

    RawWireAdmin.initRelease = function() {
        // Similar to approvals
        RawWireAdmin.initApprovals();
    };

    RawWireAdmin.initSettings = function() {
        // Settings tabs
        $('.rawwire-settings-tab').on('click', function() {
            var target = $(this).data('tab');
            $('.rawwire-settings-tab').removeClass('active');
            $(this).addClass('active');
            $('.rawwire-settings-section').removeClass('active');
            $('#' + target).addClass('active');
        });

        // Settings form submission
        $('.rawwire-settings-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var data = $form.serialize();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data + '&action=rawwire_save_settings&nonce=' + rawwire_admin.nonce,
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Settings saved');
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Failed to save settings');
                    }
                }
            });
        });
    };

    /**
     * Toggle Controls
     */
    $(document).on('change', '.rawwire-toggle input', function() {
        var $toggle = $(this);
        var settingKey = $toggle.attr('name');
        var value = $toggle.is(':checked') ? 1 : 0;

        // If it's a setting toggle, save immediately
        if (settingKey && $toggle.closest('.rawwire-panel-settings').length) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_save_setting',
                    key: settingKey,
                    value: value,
                    nonce: rawwire_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Setting updated');
                    }
                }
            });
        }
    });

    // Initialize on document ready
    $(document).ready(function() {
        RawWireAdmin.Template.init();
        RawWireAdmin.Generator.init();
        RawWireAdmin.Publisher.init();
    });

})(jQuery);
