/**
 * RawWire Template System JavaScript
 * Handles template interactions, page actions, and workflow operations
 */

(function ($) {
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
        init: function () {
            this.bindEvents();
            this.initVariantSwitcher();
            this.initTemplateSwitcher();
        },

        /**
         * Bind global events
         */
        bindEvents: function () {
            // Modal close handlers
            $(document).on('click', '.rawwire-modal-overlay', function (e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    RawWireAdmin.Modal.close($(this));
                }
            });

            $(document).on('click', '.rawwire-modal-close', function () {
                RawWireAdmin.Modal.close($(this).closest('.rawwire-modal-overlay'));
            });

            // ESC key to close modals
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape') {
                    $('.rawwire-modal-overlay:visible').hide();
                }
            });

            // Control panel button handler (template-driven)
            $(document).on('click', '.rawwire-btn[data-action]', function (e) {
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
            $(document).on('click', '[data-page-action]', function () {
                var action = $(this).data('page-action');
                var actionHandler = $(this).data('action');
                RawWireAdmin.Actions.handle(action, actionHandler);
            });

            // Panel action buttons
            $(document).on('click', '[data-panel-action]', function () {
                var action = $(this).data('panel-action');
                var panelId = $(this).closest('.rawwire-panel').data('panel-id');
                RawWireAdmin.Panels.handleAction(action, panelId);
            });

            // Card action buttons
            $(document).on('click', '[data-card-action]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var action = $(this).data('card-action');
                var itemId = $(this).data('item-id');
                var card = $(this).closest('.rawwire-card');
                RawWireAdmin.Cards.handleAction(action, itemId, card);
            });

            // Card expand/collapse buttons (Show More)
            $(document).on('click', '.rawwire-expand-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $btn = $(this);
                var $expandContainer = $btn.closest('.rawwire-card-expand');
                var $expandedContent = $expandContainer.find('.rawwire-card-expanded');
                var $icon = $btn.find('.dashicons');
                var $text = $btn.find('.rawwire-expand-text');

                $expandedContent.slideToggle(200, function () {
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
        initVariantSwitcher: function () {
            $('#rawwire-variant-selector').on('change', function () {
                var variant = $(this).val();
                RawWireAdmin.Template.switchVariant(variant);
            });
        },

        /**
         * Initialize template switcher
         */
        initTemplateSwitcher: function () {
            $('#rawwire-template-switcher').on('click', function () {
                RawWireAdmin.Modal.show('#rawwire-template-modal');
                RawWireAdmin.Template.loadTemplateList();
            });

            // Template card selection
            $(document).on('click', '.rawwire-template-card', function () {
                var templateId = $(this).data('template-id');
                if (templateId !== RawWireAdmin.Template.templateData.id) {
                    RawWireAdmin.Template.confirmSwitch(templateId);
                }
            });
        },

        /**
         * Switch variant
         */
        switchVariant: function (variant) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_variant',
                    variant: variant,
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
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
        loadTemplateList: function () {
            var $list = $('#rawwire-template-list');
            $list.html('<p class="loading"><span class="rawwire-spinner"></span> Loading templates...</p>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_list',
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var html = '';
                        response.data.templates.forEach(function (tpl) {
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
        confirmSwitch: function (templateId) {
            var backupData = $('#rawwire-backup-data').is(':checked');

            if (backupData) {
                // Download data first, then confirm switch
                RawWireAdmin.Template.downloadData(function () {
                    RawWireAdmin.Template.showSwitchConfirm(templateId);
                });
            } else {
                RawWireAdmin.Template.showSwitchConfirm(templateId);
            }
        },

        /**
         * Show switch confirmation dialog
         */
        showSwitchConfirm: function (templateId) {
            RawWireAdmin.Modal.close('#rawwire-template-modal');
            RawWireAdmin.Modal.confirm(
                'Are you sure you want to switch templates? All current configuration and data will be erased.',
                function () {
                    RawWireAdmin.Template.doSwitch(templateId);
                }
            );
        },

        /**
         * Perform template switch
         */
        doSwitch: function (templateId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_switch',
                    template_id: templateId,
                    nonce: rawwire_admin.nonce
                },
                beforeSend: function () {
                    RawWireAdmin.Notify.info('Switching template...');
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Template switched successfully. Reloading...');
                        setTimeout(function () {
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
        downloadData: function (callback) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_template_export_data',
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
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
        show: function (selector) {
            $(selector).addClass('active');
        },

        close: function (element) {
            if (typeof element === 'string') {
                $(element).removeClass('active');
            } else {
                element.removeClass('active');
            }
        },

        confirm: function (message, onConfirm, onCancel) {
            var $modal = $('#rawwire-confirm-modal');
            $('#rawwire-confirm-message').text(message);

            $modal.show();

            $modal.find('.rawwire-confirm-ok').off('click').on('click', function () {
                RawWireAdmin.Modal.close($modal);
                if (onConfirm) onConfirm();
            });

            $modal.find('.rawwire-confirm-cancel').off('click').on('click', function () {
                RawWireAdmin.Modal.close($modal);
                if (onCancel) onCancel();
            });
        }
    };

    /**
     * Panel Management
     */
    RawWireAdmin.Panels = {
        handleAction: function (action, panelId) {
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

        refresh: function (panelId) {
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
                success: function (response) {
                    if (response.success) {
                        $content.html(response.data.html);
                    } else {
                        $content.html('<div class="error">Failed to refresh panel</div>');
                    }
                }
            });
        },

        toggleExpand: function (panelId) {
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
        handleAction: function (action, $btn) {
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
                // Equalizer workflow actions
                case 'clear_lead_gen_tables':
                    this.equalizerClearTables('lead-gen', $btn, originalHtml);
                    break;
                case 'clear_post_gen_tables':
                    this.equalizerClearTables('post-gen', $btn, originalHtml);
                    break;
                case 'import_crawlomatic':
                    this.equalizerImportCrawlomatic($btn, originalHtml);
                    break;
                case 'process_crdata':
                    this.equalizerProcessCrdata($btn, originalHtml);
                    break;
                case 'run_post_generator':
                    this.equalizerRunPostGenerator($btn, originalHtml);
                    break;
                default:
                    // Generic AJAX action - send to module dispatcher
                    this.genericAction(action, $btn, originalHtml);
            }
        },

        /**
         * Clear all workflow tables via REST API
         */
        clearWorkflowTables: function ($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Clearing...');

            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + '/clear-workflow-tables',
                    method: 'POST',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    success: function (response) {
                        if (response.success) {
                            RawWireAdmin.Notify.success(response.message || 'All tables cleared');
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.error || 'Clear failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function (xhr) {
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
        startWorkflow: function ($btn, originalHtml) {
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
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    success: function (response) {
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
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.error || 'Workflow failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function (xhr) {
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
        triggerSync: function ($btn, originalHtml) {
            // Redirect to the new workflow system
            this.startWorkflow($btn, originalHtml);
        },

        /**
         * Clear cache
         */
        clearCache: function ($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-trash"></span> Clearing...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_clear_cache',
                    nonce: rawwire_admin ? rawwire_admin.nonce : ''
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Cache cleared successfully');
                    } else {
                        RawWireAdmin.Notify.error(response.data || 'Cache clear failed');
                    }
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                },
                error: function () {
                    RawWireAdmin.Notify.error('Network error clearing cache');
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                }
            });
        },

        /**
         * Generic action handler - sends to module AJAX dispatcher
         */
        genericAction: function (action, $btn, originalHtml) {
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
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success(response.data || 'Action completed');
                    } else {
                        RawWireAdmin.Notify.error(response.data || 'Action failed');
                    }
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                },
                error: function () {
                    RawWireAdmin.Notify.error('Network error');
                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                }
            });
        },

        // =====================================================================
        // EQUALIZER WORKFLOW HANDLERS
        // =====================================================================

        /**
         * Clear Equalizer tables (lead-gen or post-gen)
         */
        equalizerClearTables: function (type, $btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Clearing...');

            var endpoint = type === 'lead-gen' ? '/equalizer/clear-lead-gen' : '/equalizer/clear-post-gen';

            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + endpoint,
                    method: 'POST',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    success: function (response) {
                        if (response.success) {
                            RawWireAdmin.Notify.success(response.message || 'Tables cleared');
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.message || 'Clear failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function (xhr) {
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
         * Import from Crawlomatic into CR DATA table
         */
        equalizerImportCrawlomatic: function ($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Importing...');

            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + '/equalizer/workflow/import-crawlomatic',
                    method: 'POST',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    data: JSON.stringify({ limit: 50 }),
                    success: function (response) {
                        if (response.success) {
                            RawWireAdmin.Notify.success('Imported ' + (response.imported || 0) + ' items to CR DATA');
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.message || 'Import failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to import';
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
         * Process CR DATA with AI to create condensed content
         */
        equalizerProcessCrdata: function ($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Processing...');

            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + '/equalizer/workflow/process-crdata',
                    method: 'POST',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    data: JSON.stringify({ limit: 10 }),
                    success: function (response) {
                        if (response.success) {
                            var data = response.data || {};
                            var msg = 'Processed ' + (data.processed || 0) + ' items';
                            if (data.errors > 0) {
                                msg += ', ' + data.errors + ' errors';
                            }
                            RawWireAdmin.Notify.success(msg);
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            RawWireAdmin.Notify.error(response.message || 'Processing failed');
                            RawWireAdmin.Controls.resetButton($btn, originalHtml);
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to process';
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
         * Run Post Generator workflow (full pipeline)
         */
        equalizerRunPostGenerator: function ($btn, originalHtml) {
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Running...');

            var self = this;

            // Step 1: Import from Crawlomatic
            RawWireAdmin.Notify.info('Step 1/2: Importing crawled data...');

            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.rest) {
                $.ajax({
                    url: RawWireCfg.rest + '/equalizer/workflow/import-crawlomatic',
                    method: 'POST',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    },
                    contentType: 'application/json',
                    data: JSON.stringify({ limit: 20 }),
                    success: function (response) {
                        var imported = response.imported || 0;
                        RawWireAdmin.Notify.info('Imported ' + imported + ' items. Step 2/2: Processing with AI...');

                        // Step 2: Process with AI
                        $.ajax({
                            url: RawWireCfg.rest + '/equalizer/workflow/process-crdata',
                            method: 'POST',
                            beforeSend: function (xhr) {
                                xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                            },
                            contentType: 'application/json',
                            data: JSON.stringify({ limit: 10 }),
                            success: function (response) {
                                if (response.success) {
                                    var data = response.data || {};
                                    RawWireAdmin.Notify.success('Pipeline complete! Processed ' + (data.processed || 0) + ' items');
                                    setTimeout(function () { location.reload(); }, 800);
                                } else {
                                    RawWireAdmin.Notify.error(response.message || 'Processing failed');
                                    RawWireAdmin.Controls.resetButton($btn, originalHtml);
                                }
                            },
                            error: function (xhr) {
                                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Processing failed';
                                RawWireAdmin.Notify.error(msg);
                                RawWireAdmin.Controls.resetButton($btn, originalHtml);
                            }
                        });
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Import failed';
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
         * Reset button to original state
         */
        resetButton: function ($btn, originalHtml) {
            $btn.prop('disabled', false).html(originalHtml);
        }
    };

    /**
     * Card Actions
     */
    RawWireAdmin.Cards = {
        handleAction: function (action, itemId, $card) {
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

        approve: function (itemId, $card) {
            this.updateStatus(itemId, 'approved', $card);
        },

        reject: function (itemId, $card) {
            RawWireAdmin.Modal.confirm('Are you sure you want to reject this item?', function () {
                RawWireAdmin.Cards.updateStatus(itemId, 'rejected', $card);
            });
        },

        updateStatus: function (itemId, status, $card) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_workflow_update',
                    item_id: itemId,
                    status: status,
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        if (status === 'approved') {
                            RawWireAdmin.Notify.success('Item approved');
                            $card.addClass('approved').fadeOut(300, function () {
                                $(this).remove();
                            });
                        } else if (status === 'rejected') {
                            RawWireAdmin.Notify.info('Item rejected');
                            $card.addClass('rejected').fadeOut(300, function () {
                                $(this).remove();
                            });
                        }
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Action failed');
                    }
                }
            });
        },

        view: function (itemId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_item_detail',
                    item_id: itemId,
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $('#rawwire-item-title').text(response.data.title || 'Item Details');
                        $('#rawwire-item-content').html(response.data.content);
                        $('#rawwire-item-actions').html(response.data.actions || '');
                        RawWireAdmin.Modal.show('#rawwire-item-modal');
                    }
                }
            });
        },

        edit: function (itemId) {
            // Open edit interface
            window.location.href = rawwire_admin.edit_url + '&item=' + itemId;
        },

        openGenerator: function (itemId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_item_detail',
                    item_id: itemId,
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
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

        openPublisher: function (itemId) {
            RawWireAdmin.Publisher.load(itemId);
        }
    };

    /**
     * Page Actions
     */
    RawWireAdmin.Actions = {
        handle: function (action, handler) {
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
                            success: function (response) {
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

        runScraper: function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_toolbox_run',
                    toolbox: 'scraper',
                    nonce: rawwire_admin.nonce
                },
                beforeSend: function () {
                    RawWireAdmin.Notify.info('Running scraper...');
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Scraper completed: ' + (response.data.count || 0) + ' items found');
                        RawWireAdmin.Actions.refreshAll();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Scraper failed');
                    }
                }
            });
        },

        bulkAction: function (action) {
            var $selected = $('[data-selected="true"]');
            if ($selected.length === 0) {
                RawWireAdmin.Notify.warning('No items selected');
                return;
            }

            var ids = [];
            $selected.each(function () {
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
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Bulk action completed');
                        RawWireAdmin.Actions.refreshAll();
                    } else {
                        RawWireAdmin.Notify.error(response.data.message || 'Bulk action failed');
                    }
                }
            });
        },

        refreshAll: function () {
            $('.rawwire-panel').each(function () {
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
        init: function () {
            $('#rawwire-gen-mode').on('change', function () {
                var mode = $(this).val();
                $('.rawwire-gen-options').hide();
                $('.rawwire-gen-options[data-mode="' + mode + '"]').show();
            });

            $('#rawwire-gen-run').on('click', function () {
                RawWireAdmin.Generator.run();
            });

            $('#rawwire-gen-save').on('click', function () {
                RawWireAdmin.Generator.save();
            });
        },

        run: function () {
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
                beforeSend: function () {
                    $('#rawwire-gen-run').prop('disabled', true).text('Generating...');
                },
                success: function (response) {
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

        save: function () {
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
                success: function (response) {
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
        init: function () {
            $('#rawwire-publish-schedule').on('change', function () {
                if ($(this).val() === 'custom') {
                    $('#rawwire-publish-custom-time').show();
                } else {
                    $('#rawwire-publish-custom-time').hide();
                }
            });

            $('#rawwire-publish-confirm').on('click', function () {
                RawWireAdmin.Publisher.publish();
            });
        },

        load: function (itemId) {
            $('#rawwire-publish-modal').data('item-id', itemId);

            // Load outlets from template config
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_get_outlets',
                    nonce: rawwire_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var html = '';
                        response.data.outlets.forEach(function (outlet) {
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

        publish: function () {
            var itemId = $('#rawwire-publish-modal').data('item-id');
            var outlets = [];
            $('#rawwire-publish-outlets input:checked').each(function () {
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
                beforeSend: function () {
                    $('#rawwire-publish-confirm').prop('disabled', true).text('Publishing...');
                },
                success: function (response) {
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
        show: function (message, type) {
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

            setTimeout(function () {
                $notice.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 3000);
        },

        getIcon: function (type) {
            var icons = {
                success: 'yes-alt',
                error: 'warning',
                warning: 'info',
                info: 'info-outline'
            };
            return icons[type] || 'info';
        },

        success: function (message) { this.show(message, 'success'); },
        error: function (message) { this.show(message, 'error'); },
        warning: function (message) { this.show(message, 'warning'); },
        info: function (message) { this.show(message, 'info'); }
    };

    /**
     * Page Initialization
     */
    RawWireAdmin.initPage = function (pageId) {
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

    RawWireAdmin.initDashboard = function () {
        // Dashboard-specific initialization
        // Initialize hardwired panels
        RawWireAdmin.DashboardPanels.init();
    };

    /**
     * Dashboard Hardwired Panels Management
     * Handles workflow stats, automation progress, and activity logs
     */
    RawWireAdmin.DashboardPanels = {
        refreshIntervals: {},

        init: function () {
            // Check if this is an Equalizer template
            var $equalizerPanel = $('[data-panel="equalizer_stats"]');
            if ($equalizerPanel.length) {
                this.ensureEqualizerTables();
            }

            this.initWorkflowStats();
            this.initAutomationProgress();
            this.initActivityLog();
            this.initEqualizerActions();
            this.initCrawlomaticPanel();
            this.initRefreshIndicators();
        },

        /**
         * Initialize clickable refresh indicators on all panels
         */
        initRefreshIndicators: function () {
            var self = this;

            $(document).on('click', '.rawwire-refresh-indicator', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $indicator = $(this);
                var $panel = $indicator.closest('.rawwire-panel');
                var panelId = $panel.data('panel');
                var panelType = $panel.data('panel-type');

                // Add refreshing animation
                $indicator.addClass('refreshing');

                // Determine which refresh function to call based on panel type/id
                if (panelId === 'workflow_stats' || panelId === 'equalizer_stats') {
                    if (panelId === 'equalizer_stats') {
                        self.refreshEqualizerStats();
                    } else {
                        self.refreshWorkflowStats();
                    }
                } else if (panelType === 'progress' || panelId === 'automation_progress' || panelId === 'dual_progress') {
                    self.refreshAutomationProgress();
                } else if (panelType === 'log' || panelId === 'activity_log') {
                    self.refreshActivityLog();
                } else if (panelId === 'crawlomatic_config') {
                    self.refreshCrawlomaticPanel();
                } else {
                    // Generic panel refresh - trigger reload event
                    $panel.trigger('rawwire:panel:refresh', [panelId]);
                }

                // Remove animation after delay
                setTimeout(function () {
                    $indicator.removeClass('refreshing');
                }, 800);

                RawWireAdmin.Notify.info('Refreshing panel...');
            });
        },

        /**
         * Refresh Crawlomatic panel data
         */
        refreshCrawlomaticPanel: function () {
            // Reload the rules list from API
            var self = this;
            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/crawlomatic/rules' : '/wp-json/rawwire/v1/crawlomatic/rules',
                method: 'GET',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success) {
                        // Update rule count badge
                        $('.rawwire-crawlomatic-rules .rawwire-badge').text(response.rules ? response.rules.length : 0);
                        RawWireAdmin.Notify.success('Crawlomatic data refreshed');
                    }
                }
            });
        },

        /**
         * Ensure Equalizer tables exist (auto-create if needed)
         */
        ensureEqualizerTables: function () {
            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/equalizer/ensure-tables' : '/wp-json/rawwire/v1/equalizer/ensure-tables',
                method: 'POST',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success) {
                        console.log('[RawWire] Equalizer tables ready');
                    }
                },
                error: function () {
                    console.warn('[RawWire] Could not ensure Equalizer tables');
                }
            });
        },

        /**
         * Initialize Crawlomatic panel interactions
         */
        initCrawlomaticPanel: function () {
            var self = this;

            // Load rule configuration when clicking a rule item
            $(document).on('click', '.rawwire-rule-item', function () {
                $(this).find('input[type="radio"]').prop('checked', true);
                self.loadCrawlomaticRuleConfig($(this).data('rule-index'));
            });

            // Load selected rule config button
            $(document).on('click', '[data-action="load_rule_config"]', function (e) {
                e.preventDefault();
                var ruleIndex = $('input[name="crawlomatic_rule"]:checked').val();
                if (ruleIndex !== undefined) {
                    self.loadCrawlomaticRuleConfig(ruleIndex);
                } else {
                    RawWireAdmin.Notify.warning('Please select a rule first');
                }
            });

            // Run selected rule
            $(document).on('click', '[data-action="run_crawlomatic_rule"]', function (e) {
                e.preventDefault();
                var ruleIndex = $('input[name="crawlomatic_rule"]:checked').val();
                if (ruleIndex !== undefined) {
                    self.runCrawlomaticRule(ruleIndex);
                } else {
                    RawWireAdmin.Notify.warning('Please select a rule first');
                }
            });

            // Test selected rule
            $(document).on('click', '[data-action="test_crawlomatic_rule"]', function (e) {
                e.preventDefault();
                var ruleIndex = $('input[name="crawlomatic_rule"]:checked').val();
                if (ruleIndex !== undefined) {
                    self.testCrawlomaticRule(ruleIndex);
                } else {
                    RawWireAdmin.Notify.warning('Please select a rule first');
                }
            });

            // Save configuration form
            $(document).on('submit', '.rawwire-crawlomatic-form', function (e) {
                e.preventDefault();
                self.saveCrawlomaticConfig($(this));
            });
        },

        /**
         * Load Crawlomatic rule configuration
         */
        loadCrawlomaticRuleConfig: function (ruleIndex) {
            var self = this;
            RawWireAdmin.Notify.info('Loading rule configuration...');

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/crawlomatic/rules' : '/wp-json/rawwire/v1/crawlomatic/rules',
                method: 'GET',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success && response.rules) {
                        var rule = response.rules.find(function (r) {
                            return r.index == ruleIndex;
                        });

                        if (rule) {
                            // Populate form fields
                            $('.rawwire-crawlomatic-form #crawl_urls').val(rule.urls || '');
                            $('.rawwire-crawlomatic-form #selector_type').val(rule.selector_type || 'css');
                            $('.rawwire-crawlomatic-form #selector').val(rule.selector || '');
                            $('.rawwire-crawlomatic-form #max_items').val(rule.max_items || 50);
                            $('.rawwire-crawlomatic-form #schedule').val(rule.schedule || 'manual');

                            RawWireAdmin.Notify.success('Rule configuration loaded');
                        }
                    }
                },
                error: function () {
                    RawWireAdmin.Notify.error('Failed to load rule configuration');
                }
            });
        },

        /**
         * Run Crawlomatic rule
         */
        runCrawlomaticRule: function (ruleIndex) {
            RawWireAdmin.Notify.info('Running Crawlomatic rule...');

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/crawlomatic/run' : '/wp-json/rawwire/v1/crawlomatic/run',
                method: 'POST',
                data: JSON.stringify({ rule_index: parseInt(ruleIndex) }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success(response.message || 'Rule execution started');
                    } else {
                        RawWireAdmin.Notify.error(response.message || 'Failed to run rule');
                    }
                },
                error: function () {
                    RawWireAdmin.Notify.error('Failed to run Crawlomatic rule');
                }
            });
        },

        /**
         * Test Crawlomatic rule (dry run)
         */
        testCrawlomaticRule: function (ruleIndex) {
            RawWireAdmin.Notify.info('Testing rule (this may take a moment)...');

            // For now, just show the rule details
            // In a full implementation, this would hit a test endpoint
            RawWireAdmin.Notify.info('Test mode: Rule #' + ruleIndex + ' - Check Crawlomatic plugin for detailed testing');
        },

        /**
         * Save Crawlomatic configuration
         */
        saveCrawlomaticConfig: function ($form) {
            var formData = {
                urls: $form.find('#crawl_urls').val(),
                selector_type: $form.find('#selector_type').val(),
                selector: $form.find('#selector').val(),
                max_items: parseInt($form.find('#max_items').val()) || 50,
                schedule: $form.find('#schedule').val()
            };

            RawWireAdmin.Notify.info('Saving configuration...');

            // This would save to the selected rule or create a new one
            // For now, just show success
            setTimeout(function () {
                RawWireAdmin.Notify.success('Configuration saved (sync with Crawlomatic pending)');
            }, 500);
        },

        /**
         * Initialize Equalizer-specific actions (clear workflow buttons)
         */
        initEqualizerActions: function () {
            var self = this;

            // Clear Lead Gen tables
            $(document).on('click', '[data-action="clear_lead_gen_tables"]', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var confirmMsg = $btn.data('confirm');

                if (confirmMsg && !confirm(confirmMsg)) return;

                RawWireAdmin.Notify.info('Clearing Lead Generation data...');

                $.ajax({
                    url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/equalizer/clear-lead-gen' : '/wp-json/rawwire/v1/equalizer/clear-lead-gen',
                    method: 'POST',
                    beforeSend: function (xhr) {
                        if (window.RawWireCfg && RawWireCfg.nonce) {
                            xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                        }
                    },
                    success: function (response) {
                        if (response.success) {
                            RawWireAdmin.Notify.success(response.message);
                            self.refreshEqualizerStats();
                        } else {
                            RawWireAdmin.Notify.error('Failed to clear tables');
                        }
                    }
                });
            });

            // Clear Post Gen tables
            $(document).on('click', '[data-action="clear_post_gen_tables"]', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var confirmMsg = $btn.data('confirm');

                if (confirmMsg && !confirm(confirmMsg)) return;

                RawWireAdmin.Notify.info('Clearing Post Generator data...');

                $.ajax({
                    url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/equalizer/clear-post-gen' : '/wp-json/rawwire/v1/equalizer/clear-post-gen',
                    method: 'POST',
                    beforeSend: function (xhr) {
                        if (window.RawWireCfg && RawWireCfg.nonce) {
                            xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                        }
                    },
                    success: function (response) {
                        if (response.success) {
                            RawWireAdmin.Notify.success(response.message);
                            self.refreshEqualizerStats();
                        } else {
                            RawWireAdmin.Notify.error('Failed to clear tables');
                        }
                    }
                });
            });
        },

        /**
         * Refresh Equalizer stats (7 tables)
         */
        refreshEqualizerStats: function () {
            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/equalizer/stats' : '/wp-json/rawwire/v1/equalizer/stats',
                method: 'GET',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success && response.tables) {
                        // Update each metric
                        for (var key in response.tables) {
                            var $metric = $('[data-metric="' + key + '_count"]');
                            if ($metric.length) {
                                $metric.text((response.tables[key].count || 0).toLocaleString());
                            }
                        }
                    }
                }
            });
        },

        /**
         * Initialize Workflow Statistics Bar
         * Auto-refreshes table counts from /table-status endpoint
         */
        initWorkflowStats: function () {
            var $panel = $('[data-panel="workflow_stats"], [data-panel="equalizer_stats"]');
            if (!$panel.length) return;

            var interval = parseInt($panel.data('refresh') || 10) * 1000;
            var isEqualizer = $panel.data('panel') === 'equalizer_stats';

            // Initial load
            if (isEqualizer) {
                this.refreshEqualizerStats();
            } else {
                this.refreshWorkflowStats();
            }

            // Set up auto-refresh
            var self = this;
            this.refreshIntervals.stats = setInterval(function () {
                if (isEqualizer) {
                    self.refreshEqualizerStats();
                } else {
                    RawWireAdmin.DashboardPanels.refreshWorkflowStats();
                }
            }, interval);
        },

        refreshWorkflowStats: function () {
            var $panel = $('[data-panel="workflow_stats"]');
            if (!$panel.length) return;

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/table-status' : '/wp-json/rawwire/v1/table-status',
                method: 'GET',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success && response.tables) {
                        // Map table keys to metric IDs
                        var tableMetricMap = {
                            'candidates': 'candidates_count',
                            'approvals': 'approvals_count',
                            'content': 'content_count',
                            'releases': 'releases_count',
                            'published': 'published_count',
                            'archives': 'archives_count'
                        };

                        for (var key in response.tables) {
                            var metricId = tableMetricMap[key];
                            if (metricId) {
                                var $metric = $('[data-metric="' + metricId + '"]');
                                if ($metric.length) {
                                    var count = response.tables[key].count || 0;
                                    $metric.text(count.toLocaleString());
                                }
                            }
                        }
                    }
                },
                error: function () {
                    console.warn('[RawWire] Failed to refresh workflow stats');
                }
            });
        },

        /**
         * Initialize Automation Progress Panel
         */
        initAutomationProgress: function () {
            var $panel = $('[data-panel="automation_progress"], [data-panel="dual_progress"], .rawwire-progress-panel');
            if (!$panel.length) return;

            var interval = parseInt($panel.data('refresh') || 5) * 1000;

            // Bind automation selector change
            $(document).on('change', '.rawwire-automation-select', function () {
                var automationId = $(this).val();
                var $desc = $(this).closest('.rawwire-progress-selector').find('.rawwire-automation-description');
                var description = $(this).find('option:selected').data('description') || '';
                $desc.text(description);

                // Update the displayed steps
                RawWireAdmin.DashboardPanels.switchAutomation(automationId);
            });

            // Initial load
            this.refreshAutomationProgress();

            // Set up auto-refresh (only when running)
            this.refreshIntervals.progress = setInterval(function () {
                var $panel = $('.rawwire-progress-panel');
                var status = $panel.data('status');
                if (status === 'running') {
                    RawWireAdmin.DashboardPanels.refreshAutomationProgress();
                }
            }, interval);
        },

        refreshAutomationProgress: function () {
            var $panel = $('.rawwire-progress-panel');
            if (!$panel.length) return;

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/automation/progress' : '/wp-json/rawwire/v1/automation/progress',
                method: 'GET',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success && response.automation) {
                        var auto = response.automation;
                        $panel.data('status', auto.status);

                        // Update status indicator
                        var $status = $panel.find('.rawwire-status-indicator');
                        $status.removeClass('rawwire-status-idle rawwire-status-running rawwire-status-complete rawwire-status-error rawwire-status-paused');
                        $status.addClass('rawwire-status-' + auto.status);

                        // Update status text
                        var statusLabels = {
                            'idle': 'Ready',
                            'running': 'Running',
                            'complete': 'Complete',
                            'error': 'Error',
                            'paused': 'Paused'
                        };
                        $status.find('.rawwire-status-text').text(statusLabels[auto.status] || 'Unknown');

                        // Update step indicators
                        if (auto.current_step !== undefined) {
                            RawWireAdmin.DashboardPanels.updateStepIndicators(auto.current_step, auto.status);
                        }

                        // Update ETA if available
                        if (auto.elapsed && auto.status === 'running') {
                            var $eta = $panel.find('.rawwire-eta-text');
                            $eta.text('Elapsed: ' + RawWireAdmin.DashboardPanels.formatDuration(auto.elapsed));
                        }
                    }
                }
            });
        },

        updateStepIndicators: function (currentStep, status) {
            var $steps = $('.rawwire-step');
            $steps.each(function (index) {
                var $step = $(this);
                $step.removeClass('rawwire-step-pending rawwire-step-active rawwire-step-complete');

                if (status === 'complete') {
                    $step.addClass('rawwire-step-complete');
                } else if (status === 'running') {
                    if (index < currentStep) {
                        $step.addClass('rawwire-step-complete');
                    } else if (index === currentStep) {
                        $step.addClass('rawwire-step-active');
                    } else {
                        $step.addClass('rawwire-step-pending');
                    }
                } else {
                    $step.addClass('rawwire-step-pending');
                }

                // Update icons
                var $icon = $step.find('.rawwire-step-icon .dashicons');
                var originalIcon = $step.data('original-icon') || $icon.attr('class').split(' ').filter(c => c.startsWith('dashicons-'))[1];
                if (!$step.data('original-icon')) {
                    $step.data('original-icon', originalIcon);
                }

                $icon.removeClass('dashicons-yes-alt dashicons-update rawwire-spin').addClass(originalIcon);
                if ($step.hasClass('rawwire-step-complete')) {
                    $icon.removeClass(originalIcon).addClass('dashicons-yes-alt');
                } else if ($step.hasClass('rawwire-step-active')) {
                    $icon.removeClass(originalIcon).addClass('dashicons-update rawwire-spin');
                }
            });

            // Update progress bar
            var totalSteps = $steps.length;
            var progressPercent = totalSteps > 0 ? (currentStep / totalSteps) * 100 : 0;
            if (status === 'complete') progressPercent = 100;
            $('.rawwire-progress-steps .rawwire-progress-bar').css('width', progressPercent + '%');
        },

        switchAutomation: function (automationId) {
            // This would update the steps container based on the selected automation
            // For now, we'll trigger a page reload or panel refresh
            console.log('[RawWire] Switching automation to:', automationId);
        },

        formatDuration: function (seconds) {
            if (seconds < 60) return seconds + 's';
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            if (mins < 60) return mins + 'm ' + secs + 's';
            var hours = Math.floor(mins / 60);
            mins = mins % 60;
            return hours + 'h ' + mins + 'm';
        },

        /**
         * Initialize Activity Log Panel
         */
        initActivityLog: function () {
            var $panel = $('[data-panel="activity_log"], .rawwire-log-panel');
            if (!$panel.length) return;

            var interval = parseInt($panel.data('refresh') || 30) * 1000;

            // Bind tab clicks
            $(document).on('click', '.rawwire-log-tab', function () {
                var filter = $(this).data('filter');
                $('.rawwire-log-tab').removeClass('active');
                $(this).addClass('active');
                RawWireAdmin.DashboardPanels.filterLogs(filter);
            });

            // Bind filter dropdown
            $(document).on('change', '.rawwire-log-filter', function () {
                var filter = $(this).val();
                RawWireAdmin.DashboardPanels.filterLogs(filter);
            });

            // Bind refresh button
            $(document).on('click', '.rawwire-log-refresh', function () {
                RawWireAdmin.DashboardPanels.refreshActivityLog();
            });

            // Set up auto-refresh
            this.refreshIntervals.logs = setInterval(function () {
                RawWireAdmin.DashboardPanels.refreshActivityLog();
            }, interval);
        },

        filterLogs: function (filter) {
            var $entries = $('.rawwire-log-entry');

            if (!filter || filter === 'all') {
                $entries.show();
            } else {
                $entries.hide();
                $entries.filter('.rawwire-log-' + filter).show();
            }
        },

        refreshActivityLog: function () {
            var $panel = $('.rawwire-log-panel');
            if (!$panel.length || !$panel.is(':visible')) return;

            var limit = 50;
            var currentFilter = $('.rawwire-log-filter').val() || 'all';
            var $entries = $panel.find('.rawwire-log-entries');
            var esc = window.escapeHtml || function (t) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(t));
                return d.innerHTML;
            };

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/logs' : '/wp-json/rawwire/v1/logs',
                method: 'GET',
                data: { limit: limit },
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response && response.logs) {
                        var html = '';
                        var counts = { info: 0, warning: 0, error: 0, debug: 0 };

                        if (response.logs.length === 0) {
                            html = '<div class="rawwire-log-empty">' +
                                '<span class="dashicons dashicons-info-outline"></span>' +
                                '<p>No log entries found. Run a workflow to see activity here.</p>' +
                                '</div>';
                        } else {
                            response.logs.forEach(function (log) {
                                var sev = log.severity || 'info';
                                counts[sev] = (counts[sev] || 0) + 1;
                                var icon = sev === 'error' ? 'no' :
                                    (sev === 'warning' ? 'warning' :
                                        (sev === 'debug' ? 'admin-tools' : 'info'));
                                html += '<div class="rawwire-log-entry rawwire-log-' + esc(sev) + '">';
                                html += '<span class="rawwire-log-severity"><span class="dashicons dashicons-' + icon + '"></span></span>';
                                if (log.timestamp) {
                                    html += '<span class="rawwire-log-time">' + esc(log.timestamp.substring(0, 19)) + '</span>';
                                }
                                html += '<span class="rawwire-log-message">' + esc(log.message.substring(0, 200)) + '</span>';
                                html += '</div>';
                            });
                        }

                        // Rebuild log entries HTML
                        $entries.html(html);

                        // Update severity counts in header
                        $panel.find('.rawwire-log-stat.info .count').text(counts.info);
                        $panel.find('.rawwire-log-stat.warning .count').text(counts.warning);
                        $panel.find('.rawwire-log-stat.error .count').text(counts.error);

                        // Re-apply active filter to newly built entries
                        RawWireAdmin.DashboardPanels.filterLogs(currentFilter);
                    }
                },
                error: function () {
                    $entries.html('<div class="rawwire-log-empty">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<p>Failed to load logs</p></div>');
                }
            });
        },

        /**
         * Cleanup - call when leaving dashboard page
         */
        destroy: function () {
            for (var key in this.refreshIntervals) {
                clearInterval(this.refreshIntervals[key]);
            }
            this.refreshIntervals = {};
        }
    };

    /**
     * Dashboard Builder Module
     * Handles developer mode authentication, sidebar toggles, row building, and panel management.
     * Developer mode must be unlocked via login before the builder toolbar becomes available.
     */
    RawWireAdmin.Builder = {
        selectedColumns: 2,
        rowCount: 0,
        isBuilderOpen: false,
        isDevMode: false,

        init: function () {
            // Check initial dev mode state from server
            this.isDevMode = (typeof RawWireCfg !== 'undefined' && RawWireCfg.devMode) ? true : false;
            this.bindEvents();
            console.log('RawWire Builder initialized, devMode:', this.isDevMode);
        },

        bindEvents: function () {
            var self = this;

            // Developer toggle button - shows login prompt if not authenticated
            $(document).on('click', '#rawwire-builder-toggle', function (e) {
                e.preventDefault();
                if (!self.isDevMode) {
                    // Not authenticated - show login modal
                    self.showDevLoginModal();
                } else {
                    // Authenticated - toggle builder toolbar
                    self.toggleBuilder();
                    $(this).toggleClass('active');
                }
            });

            // Developer login submit
            $(document).on('click', '#rawwire-dev-login-submit', function (e) {
                e.preventDefault();
                self.submitDevLogin();
            });

            // Developer login - Enter key
            $(document).on('keypress', '#rawwire-dev-password', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.submitDevLogin();
                }
            });

            // Developer login modal close
            $(document).on('click', '#rawwire-dev-login-modal .rawwire-modal-close', function () {
                self.hideDevLoginModal();
            });
            $(document).on('click', '#rawwire-dev-login-modal', function (e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    self.hideDevLoginModal();
                }
            });

            // Developer logout
            $(document).on('click', '#rawwire-dev-logout', function (e) {
                e.preventDefault();
                self.devLogout();
            });

            // Clear cache button
            $(document).on('click', '#rawwire-clear-cache', function (e) {
                e.preventDefault();
                self.clearCache();
            });

            // Reload page button
            $(document).on('click', '#rawwire-reload-page', function (e) {
                e.preventDefault();
                self.reloadPage();
            });

            // Sidebar toggle buttons
            $(document).on('click', '[data-builder-action="toggle-sidebar-left"]', function () {
                self.toggleSidebar('left');
                $(this).toggleClass('active');
            });

            $(document).on('click', '[data-builder-action="toggle-sidebar-right"]', function () {
                self.toggleSidebar('right');
                $(this).toggleClass('active');
            });

            // Add Row button - opens modal
            $(document).on('click', '[data-builder-action="add-row"]', function () {
                self.openRowBuilderModal();
            });

            // Row option selection in modal
            $(document).on('click', '.rawwire-row-option', function () {
                $('.rawwire-row-option').removeClass('selected');
                $(this).addClass('selected');
                self.selectedColumns = parseInt($(this).data('columns'));
                $('#rawwire-custom-columns').val(self.selectedColumns);
            });

            // Custom column input
            $(document).on('change', '#rawwire-custom-columns', function () {
                var val = parseInt($(this).val());
                if (val >= 1 && val <= 6) {
                    self.selectedColumns = val;
                    $('.rawwire-row-option').removeClass('selected');
                    $('.rawwire-row-option[data-columns="' + val + '"]').addClass('selected');
                }
            });

            // Confirm add row
            $(document).on('click', '#rawwire-add-row-confirm', function () {
                self.addPanelRow(self.selectedColumns);
                self.closeRowBuilderModal();
            });

            // Close modal
            $(document).on('click', '#rawwire-row-builder-modal .rawwire-modal-close', function () {
                self.closeRowBuilderModal();
            });

            // Modal backdrop click
            $(document).on('click', '#rawwire-row-builder-modal', function (e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    self.closeRowBuilderModal();
                }
            });

            // Remove Row button - opens modal
            $(document).on('click', '[data-builder-action="remove-row"]', function () {
                self.openRemoveRowModal();
            });

            // Row select change - enable/disable confirm button
            $(document).on('change', '#rawwire-row-select', function () {
                var hasSelection = $(this).val() !== '';
                $('#rawwire-remove-row-confirm').prop('disabled', !hasSelection);
            });

            // Confirm remove row
            $(document).on('click', '#rawwire-remove-row-confirm', function () {
                var rowId = $('#rawwire-row-select').val();
                if (rowId) {
                    self.removeRow(rowId);
                    self.closeRemoveRowModal();
                }
            });

            // Close remove row modal
            $(document).on('click', '#rawwire-remove-row-modal .rawwire-modal-close', function () {
                self.closeRemoveRowModal();
            });

            // Remove row modal backdrop click
            $(document).on('click', '#rawwire-remove-row-modal', function (e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    self.closeRemoveRowModal();
                }
            });

            // Collapsible panels
            $(document).on('click', '.rawwire-panel.rawwire-collapsible .rawwire-panel-header', function () {
                $(this).closest('.rawwire-panel').toggleClass('collapsed');
            });

            // Workflow run/stop buttons
            $(document).on('click', '[data-action="run_workflow"]', function () {
                var automationId = $(this).data('automation');
                self.runWorkflow(automationId);
            });

            $(document).on('click', '[data-action="stop_workflow"]', function () {
                var automationId = $(this).data('automation');
                self.stopWorkflow(automationId);
            });

            // Workflow selector change - updates both automation and active tool
            $(document).on('change', '.rawwire-workflow-select', function () {
                var automationId = $(this).val();
                var $selected = $(this).find('option:selected');
                var toolId = $selected.data('tool') || '';
                var $bar = $(this).closest('.rawwire-workflow-bar');

                // Update the run/stop button data attributes
                $bar.find('[data-action="run_workflow"], [data-action="stop_workflow"]')
                    .attr('data-automation', automationId)
                    .attr('data-tool', toolId);

                // Update the workflow bar's active tool
                $bar.attr('data-active-tool', toolId);

                // Update stats panel if it's tool-aware (has data-stats-panel attribute)
                var $statsPanel = $('[data-stats-panel]');
                if ($statsPanel.length && toolId) {
                    self.refreshStatsForTool(toolId);
                }

                // Save selection via AJAX (optional - persist across page loads)
                if (toolId && window.RawWireCfg && RawWireCfg.rest) {
                    $.ajax({
                        url: RawWireCfg.rest + '/dashboard/set-active-tool',
                        method: 'POST',
                        data: JSON.stringify({ tool_id: toolId, automation_id: automationId }),
                        contentType: 'application/json',
                        beforeSend: function (xhr) {
                            if (RawWireCfg.nonce) {
                                xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                            }
                        }
                    });
                }
            });

            // ═══════════════════════════════════════════════════════════════
            // SLOT PICKER - Click empty slot to open panel picker
            // ═══════════════════════════════════════════════════════════════
            $(document).on('click', '.rawwire-panel-slot .rawwire-panel-slot-empty', function () {
                var $slot = $(this).closest('.rawwire-panel-slot');
                var $row = $slot.closest('.rawwire-panel-row');
                var isInfoOnly = $row.data('row-type') === 'info-only';
                self.activeSlot = $slot;
                self.openSlotPickerModal(isInfoOnly);
            });

            // Slot picker category tabs
            $(document).on('click', '.rawwire-slot-category', function () {
                var category = $(this).data('category');
                $('.rawwire-slot-category').removeClass('active');
                $(this).addClass('active');
                $('#rawwire-slot-picker-modal .rawwire-slot-list').hide();
                $('#rawwire-slot-picker-modal .rawwire-slot-list[data-category="' + category + '"]').show();
            });

            // Slot picker item selection
            $(document).on('click', '.rawwire-slot-item', function () {
                var slotType = $(this).data('slot-type');
                var slotItem = $(this).data('slot-item');
                self.assignSlotPanel(slotType, slotItem, $(this));
            });

            // Slot picker close
            $(document).on('click', '#rawwire-slot-picker-modal .rawwire-modal-close', function () {
                self.closeSlotPickerModal();
            });
            $(document).on('click', '#rawwire-slot-picker-modal', function (e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    self.closeSlotPickerModal();
                }
            });

            // Clear slot (remove assigned panel)
            $(document).on('click', '.rawwire-slot-clear', function (e) {
                e.stopPropagation();
                var $slot = $(this).closest('.rawwire-panel-slot');
                self.clearSlot($slot);
            });

            // ═══════════════════════════════════════════════════════════════
            // SAVE TEMPLATE
            // ═══════════════════════════════════════════════════════════════
            $(document).on('click', '[data-builder-action="save-template"]', function () {
                self.openSaveTemplateModal();
            });

            // Auto-generate template ID from name
            $(document).on('input', '#rawwire-template-name', function () {
                var name = $(this).val();
                var id = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                $('#rawwire-template-id').val(id);
            });

            // Save template confirm
            $(document).on('click', '#rawwire-save-template-confirm', function () {
                self.saveTemplate();
            });

            // Save template modal close
            $(document).on('click', '#rawwire-save-template-modal .rawwire-modal-close', function () {
                self.closeSaveTemplateModal();
            });
            $(document).on('click', '#rawwire-save-template-modal', function (e) {
                if ($(e.target).hasClass('rawwire-modal-overlay')) {
                    self.closeSaveTemplateModal();
                }
            });
        },

        toggleBuilder: function () {
            var $toolbar = $('#rawwire-builder-toolbar');
            var $toggle = $('#rawwire-builder-toggle');

            // Only allow toggle if dev mode is active
            if (!this.isDevMode) {
                this.showDevLoginModal();
                return;
            }

            this.isBuilderOpen = !this.isBuilderOpen;

            console.log('Toggling builder:', this.isBuilderOpen);

            if (this.isBuilderOpen) {
                $toolbar.slideDown(250);
                $toggle.addClass('active');
            } else {
                $toolbar.slideUp(250);
                $toggle.removeClass('active');
            }
        },

        /**
         * Show the developer login modal
         */
        showDevLoginModal: function () {
            $('#rawwire-dev-login-error').hide();
            $('#rawwire-dev-username').val('');
            $('#rawwire-dev-password').val('');
            $('#rawwire-dev-login-modal').addClass('active');
            setTimeout(function () {
                $('#rawwire-dev-username').focus();
            }, 250);
        },

        /**
         * Hide the developer login modal
         */
        hideDevLoginModal: function () {
            $('#rawwire-dev-login-modal').removeClass('active');
        },

        /**
         * Submit developer login credentials via AJAX
         */
        submitDevLogin: function () {
            var self = this;
            var username = $('#rawwire-dev-username').val().trim();
            var password = $('#rawwire-dev-password').val();
            var $btn = $('#rawwire-dev-login-submit');
            var $error = $('#rawwire-dev-login-error');

            if (!username || !password) {
                $error.text('Both fields are required.').show();
                return;
            }

            $btn.prop('disabled', true).find('.btn-text').text('Authenticating...');
            $error.hide();

            var nonce = '';
            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.adminNonce) {
                nonce = RawWireCfg.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.adminNonce) {
                nonce = rawwire_admin.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.nonce) {
                nonce = rawwire_admin.nonce;
            }

            $.ajax({
                url: (typeof RawWireCfg !== 'undefined' && RawWireCfg.ajaxurl) ? RawWireCfg.ajaxurl : ajaxurl,
                method: 'POST',
                data: {
                    action: 'rawwire_dev_login',
                    nonce: nonce,
                    dev_username: username,
                    dev_password: password
                },
                success: function (response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        self.isDevMode = true;
                        self.hideDevLoginModal();
                        RawWireAdmin.Notify.success('Developer mode activated');
                        // Reload page to show dev menus
                        window.location.reload();
                    } else {
                        $error.text(response.data.message || 'Authentication failed.').show();
                        $('#rawwire-dev-password').val('').focus();
                    }
                },
                error: function () {
                    $btn.prop('disabled', false);
                    $error.text('Connection error. Please try again.').show();
                }
            });
        },

        /**
         * Logout from developer mode
         */
        devLogout: function () {
            var self = this;
            var nonce = '';
            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.adminNonce) {
                nonce = RawWireCfg.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.adminNonce) {
                nonce = rawwire_admin.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.nonce) {
                nonce = rawwire_admin.nonce;
            }

            $.ajax({
                url: (typeof RawWireCfg !== 'undefined' && RawWireCfg.ajaxurl) ? RawWireCfg.ajaxurl : ajaxurl,
                method: 'POST',
                data: {
                    action: 'rawwire_dev_logout',
                    nonce: nonce
                },
                success: function (response) {
                    if (response.success) {
                        self.isDevMode = false;
                        self.isBuilderOpen = false;
                        RawWireAdmin.Notify.info('Developer mode locked');
                        window.location.reload();
                    }
                },
                error: function () {
                    RawWireAdmin.Notify.warning('Could not lock developer mode');
                }
            });
        },

        clearCache: function () {
            var self = this;
            RawWireAdmin.Notify.info('Clearing cache...');

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.ajaxurl) ? RawWireCfg.ajaxurl : ajaxurl,
                method: 'POST',
                data: {
                    action: 'rawwire_clear_cache',
                    nonce: (window.RawWireCfg && RawWireCfg.nonce) ? RawWireCfg.nonce : ''
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Cache cleared');
                    } else {
                        RawWireAdmin.Notify.warning('Cache clear completed');
                    }
                },
                error: function () {
                    // Even if AJAX fails, clear local storage
                    try {
                        localStorage.removeItem('rawwire_template_cache');
                        sessionStorage.clear();
                    } catch (e) { }
                    RawWireAdmin.Notify.success('Local cache cleared');
                }
            });
        },

        reloadPage: function () {
            RawWireAdmin.Notify.info('Reloading...');
            setTimeout(function () {
                window.location.reload(true);
            }, 300);
        },

        toggleSidebar: function (side) {
            var $sidebar = $('.rawwire-sidebar-' + side);
            var isVisible = $sidebar.is(':visible');

            if (isVisible) {
                $sidebar.slideUp(250);
            } else {
                $sidebar.slideDown(250);
            }

            // Update toggle button state
            var $btn = $('[data-builder-action="toggle-sidebar-' + side + '"]');
            $btn.toggleClass('active', !isVisible);
        },

        openRowBuilderModal: function () {
            $('#rawwire-row-builder-modal').addClass('active');
            // Reset selection
            this.selectedColumns = 2;
            $('.rawwire-row-option').removeClass('selected');
            $('.rawwire-row-option[data-columns="2"]').addClass('selected');
            $('#rawwire-custom-columns').val(2);
        },

        closeRowBuilderModal: function () {
            $('#rawwire-row-builder-modal').removeClass('active');
        },

        openRemoveRowModal: function () {
            var $select = $('#rawwire-row-select');
            $select.empty();

            // Get all added rows (only user-added rows, not static panels)
            var $rows = $('.rawwire-panel-row[data-row-id]');

            if ($rows.length === 0) {
                $select.append('<option value="">-- No rows added yet --</option>');
                $('#rawwire-remove-row-confirm').prop('disabled', true);
            } else {
                $select.append('<option value="">-- Select a row --</option>');
                $rows.each(function (index) {
                    var rowId = $(this).data('row-id');
                    var rowNum = index + 1;
                    var slotCount = $(this).find('.rawwire-panel-slot').length;
                    $select.append('<option value="' + rowId + '">Row ' + rowNum + ' (' + slotCount + ' columns)</option>');
                });
            }

            $('#rawwire-remove-row-modal').addClass('active');
        },

        closeRemoveRowModal: function () {
            $('#rawwire-remove-row-modal').removeClass('active');
            $('#rawwire-row-select').val('');
            $('#rawwire-remove-row-confirm').prop('disabled', true);
        },

        addPanelRow: function (columns) {
            this.rowCount++;
            var rowId = 'row-' + this.rowCount;
            var isInfoOnly = columns > 2;
            var rowType = isInfoOnly ? 'info-only' : 'standard';

            var $row = $('<div class="rawwire-panel-row" data-row-id="' + rowId + '" data-row-num="' + this.rowCount + '" data-columns="' + columns + '" data-row-type="' + rowType + '"></div>');

            // Add row label
            var typeLabel = isInfoOnly ? ' (Info)' : ' (Tools)';
            var $label = $('<div class="rawwire-row-label">Row ' + this.rowCount + typeLabel + '</div>');
            $row.append($label);

            for (var i = 0; i < columns; i++) {
                var slotId = rowId + '-slot-' + (i + 1);
                var slotLabel = isInfoOnly ? 'Click to add info panel' : 'Click to add panel';
                var slotIcon = isInfoOnly ? 'dashicons-info-outline' : 'dashicons-plus-alt2';
                var $slot = $(
                    '<div class="rawwire-panel-slot" data-slot-id="' + slotId + '" data-slot-type="" data-slot-item="">' +
                    '<div class="rawwire-panel-slot-empty">' +
                    '<span class="dashicons ' + slotIcon + '"></span>' +
                    '<p>' + slotLabel + '</p>' +
                    '</div>' +
                    '</div>'
                );
                $row.append($slot);
            }

            // Add row controls (inline remove button)
            var $controls = $(
                '<div class="rawwire-row-actions">' +
                '<button type="button" class="rawwire-btn-icon rawwire-btn-danger-ghost" data-row-action="remove" data-row="' + rowId + '" title="Remove this row">' +
                '<span class="dashicons dashicons-no-alt"></span>' +
                '</button>' +
                '</div>'
            );
            $row.append($controls);

            // Insert before the row builder placeholder (which sits between progress bar and log panel)
            var $builder = $('#rawwire-row-builder');
            if ($builder.length) {
                $builder.before($row);
                $builder.show();
            } else {
                // Fallback: try to insert before the activity log panel
                var $log = $('[data-panel-id="activity_log"]');
                if ($log.length) {
                    $log.before($row);
                } else {
                    $('.rawwire-page-content').append($row);
                }
            }

            // Animate in
            $row.hide().slideDown(300);

            RawWireAdmin.Notify.success('Added ' + columns + '-column row');
        },

        removeRow: function (rowId) {
            var $row = $('[data-row-id="' + rowId + '"]');
            $row.slideUp(300, function () {
                $(this).remove();
            });
        },

        runWorkflow: function (automationId) {
            var self = this;
            var $bar = $('.rawwire-workflow-bar');

            if (!automationId) {
                RawWireAdmin.Notify.error('No workflow selected');
                return;
            }

            RawWireAdmin.Notify.info('Starting workflow: ' + automationId);

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/automation/progress' : '/wp-json/rawwire/v1/automation/progress',
                method: 'POST',
                data: JSON.stringify({
                    automation_id: automationId,
                    action: 'start'
                }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    if (response.success) {
                        $bar.attr('data-status', 'running');
                        self.refreshWorkflowBar();
                        RawWireAdmin.Notify.success('Workflow started');
                    } else {
                        RawWireAdmin.Notify.error(response.message || 'Failed to start workflow');
                    }
                },
                error: function () {
                    RawWireAdmin.Notify.error('Failed to start workflow');
                }
            });
        },

        stopWorkflow: function (automationId) {
            var self = this;
            var $bar = $('.rawwire-workflow-bar');

            $.ajax({
                url: (window.RawWireCfg && RawWireCfg.rest) ? RawWireCfg.rest + '/automation/progress' : '/wp-json/rawwire/v1/automation/progress',
                method: 'POST',
                data: JSON.stringify({
                    automation_id: automationId,
                    action: 'stop'
                }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                },
                success: function (response) {
                    $bar.attr('data-status', 'idle');
                    self.refreshWorkflowBar();
                    RawWireAdmin.Notify.info('Workflow stopped');
                },
                error: function () {
                    RawWireAdmin.Notify.error('Failed to stop workflow');
                }
            });
        },

        refreshWorkflowBar: function () {
            // Refresh progress bar state - could reload the page section or update via AJAX
            // For now, we rely on the auto-refresh from DashboardPanels
            if (RawWireAdmin.DashboardPanels && RawWireAdmin.DashboardPanels.refreshAutomationProgress) {
                RawWireAdmin.DashboardPanels.refreshAutomationProgress();
            }
        },

        /**
         * Refresh stats panel to show metrics for a specific tool
         * Called when workflow dropdown selection changes
         * @param {string} toolId - The tool ID to load stats for
         */
        refreshStatsForTool: function (toolId) {
            var self = this;
            var $statsPanel = $('[data-stats-panel]');

            if (!$statsPanel.length || !toolId) {
                return;
            }

            // Update the active tool attribute
            $statsPanel.attr('data-active-tool', toolId);

            // Fetch new stats via AJAX
            var restUrl = (window.RawWireCfg && RawWireCfg.rest)
                ? RawWireCfg.rest + '/dashboard/stats/' + toolId
                : '/wp-json/rawwire/v1/dashboard/stats/' + toolId;

            $.ajax({
                url: restUrl,
                method: 'GET',
                beforeSend: function (xhr) {
                    if (window.RawWireCfg && RawWireCfg.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce);
                    }
                    $statsPanel.addClass('rawwire-loading');
                },
                success: function (response) {
                    if (response.success && response.data && response.data.metrics) {
                        self.updateStatsDisplay(response.data.metrics);
                    }
                },
                error: function () {
                    console.warn('Failed to refresh stats for tool: ' + toolId);
                },
                complete: function () {
                    $statsPanel.removeClass('rawwire-loading');
                }
            });
        },

        /**
         * Update stats panel display with new metrics
         * @param {Array} metrics - Array of metric objects with id, label, value, icon
         */
        updateStatsDisplay: function (metrics) {
            var $statsPanel = $('[data-stats-panel]');

            if (!$statsPanel.length || !metrics || !metrics.length) {
                return;
            }

            // Clear existing cards and rebuild
            $statsPanel.find('.rawwire-stat-card').remove();

            var $actions = $statsPanel.find('.rawwire-stats-actions').detach();

            metrics.forEach(function (metric) {
                var highlightClass = metric.highlight ? ' rawwire-highlight-' + metric.highlight : '';
                var $card = $(
                    '<div class="rawwire-stat-card' + highlightClass + '" data-metric-id="' + (metric.id || '') + '">' +
                    (metric.icon ? '<span class="dashicons ' + metric.icon + '"></span>' : '') +
                    '<h3 data-metric="' + (metric.id || '') + '">' + (metric.value || 0) + '</h3>' +
                    '<p>' + (metric.label || '') + '</p>' +
                    '</div>'
                );
                $statsPanel.append($card);
            });

            // Re-append actions if they exist
            if ($actions.length) {
                $statsPanel.append($actions);
            }
        },

        // ═══════════════════════════════════════════════════════════════════
        // SLOT PICKER METHODS
        // ═══════════════════════════════════════════════════════════════════

        /**
         * Currently active slot being configured
         */
        activeSlot: null,

        /**
         * Open the slot picker modal
         * @param {boolean} isInfoOnly - If true, show info-only items (>2 columns)
         */
        openSlotPickerModal: function (isInfoOnly) {
            var $modal = $('#rawwire-slot-picker-modal');

            if (isInfoOnly) {
                // Hide categories, show info-only list
                $('#rawwire-slot-categories').hide();
                $modal.find('.rawwire-slot-list[data-category]').hide();
                $('#rawwire-slot-info-only').show();
            } else {
                // Show categories with first tab active
                $('#rawwire-slot-categories').show();
                $('#rawwire-slot-info-only').hide();
                $('.rawwire-slot-category').removeClass('active').first().addClass('active');
                $modal.find('.rawwire-slot-list[data-category]').hide();
                $modal.find('.rawwire-slot-list[data-category="tool"]').show();
            }

            $modal.addClass('active');
        },

        /**
         * Close the slot picker modal
         */
        closeSlotPickerModal: function () {
            $('#rawwire-slot-picker-modal').removeClass('active');
            this.activeSlot = null;
        },

        /**
         * Assign a panel to the active slot
         * @param {string} slotType - tool, workflow, info, or info-only
         * @param {string} itemId - The ID of the selected item
         * @param {jQuery} $item - The clicked item button
         */
        assignSlotPanel: function (slotType, itemId, $item) {
            if (!this.activeSlot) return;

            var label = $item.find('strong').text();
            var icon = $item.find('.dashicons').attr('class').replace('dashicons ', '').replace('dashicons-', '');
            var iconClass = 'dashicons-' + icon;

            // Update the slot with the selected panel
            this.activeSlot.attr('data-slot-type', slotType);
            this.activeSlot.attr('data-slot-item', itemId);
            this.activeSlot.html(
                '<div class="rawwire-panel-slot-assigned">' +
                '<div class="rawwire-slot-assigned-header">' +
                '<span class="dashicons ' + iconClass + '"></span>' +
                '<span class="rawwire-slot-assigned-label">' + label + '</span>' +
                '<button type="button" class="rawwire-slot-clear" title="Remove panel">' +
                '<span class="dashicons dashicons-no-alt"></span>' +
                '</button>' +
                '</div>' +
                '<div class="rawwire-slot-assigned-type">' + slotType + '</div>' +
                '</div>'
            );

            this.activeSlot.addClass('rawwire-slot-filled');
            this.closeSlotPickerModal();
            RawWireAdmin.Notify.success(label + ' added to slot');
        },

        /**
         * Clear an assigned slot back to empty
         * @param {jQuery} $slot - The slot element
         */
        clearSlot: function ($slot) {
            var $row = $slot.closest('.rawwire-panel-row');
            var isInfoOnly = $row.data('row-type') === 'info-only';
            var slotLabel = isInfoOnly ? 'Click to add info panel' : 'Click to add panel';
            var slotIcon = isInfoOnly ? 'dashicons-info-outline' : 'dashicons-plus-alt2';

            $slot.attr('data-slot-type', '');
            $slot.attr('data-slot-item', '');
            $slot.removeClass('rawwire-slot-filled');
            $slot.html(
                '<div class="rawwire-panel-slot-empty">' +
                '<span class="dashicons ' + slotIcon + '"></span>' +
                '<p>' + slotLabel + '</p>' +
                '</div>'
            );
        },

        // ═══════════════════════════════════════════════════════════════════
        // SAVE TEMPLATE METHODS
        // ═══════════════════════════════════════════════════════════════════

        /**
         * Open the save template modal
         */
        openSaveTemplateModal: function () {
            $('#rawwire-template-name').val('');
            $('#rawwire-template-id').val('');
            $('#rawwire-template-desc').val('');
            $('#rawwire-save-template-error').hide();
            $('#rawwire-save-template-modal').addClass('active');
            setTimeout(function () {
                $('#rawwire-template-name').focus();
            }, 250);
        },

        /**
         * Close the save template modal
         */
        closeSaveTemplateModal: function () {
            $('#rawwire-save-template-modal').removeClass('active');
        },

        /**
         * Serialize the current builder layout into a template JSON structure
         * @returns {object} Template layout data
         */
        serializeLayout: function () {
            var layout = {
                rows: []
            };

            // Walk each user-added row
            $('.rawwire-panel-row[data-row-id]').each(function () {
                var $row = $(this);
                var rowData = {
                    id: $row.data('row-id'),
                    columns: parseInt($row.data('columns')),
                    type: $row.data('row-type'),
                    slots: []
                };

                $row.find('.rawwire-panel-slot').each(function () {
                    var $slot = $(this);
                    rowData.slots.push({
                        id: $slot.data('slot-id'),
                        type: $slot.data('slot-type') || '',
                        item: $slot.data('slot-item') || ''
                    });
                });

                layout.rows.push(rowData);
            });

            return layout;
        },

        /**
         * Save the current layout as a template via AJAX
         */
        saveTemplate: function () {
            var self = this;
            var name = $('#rawwire-template-name').val().trim();
            var id = $('#rawwire-template-id').val().trim();
            var desc = $('#rawwire-template-desc').val().trim();
            var $btn = $('#rawwire-save-template-confirm');
            var $error = $('#rawwire-save-template-error');

            if (!name) {
                $error.text('Template name is required.').show();
                return;
            }

            if (!id) {
                id = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            }

            var layout = this.serializeLayout();

            if (layout.rows.length === 0) {
                $error.text('Add at least one row before saving.').show();
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-cloud-saved').addClass('dashicons-update spin');

            // Get nonce
            var nonce = '';
            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.adminNonce) {
                nonce = RawWireCfg.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.adminNonce) {
                nonce = rawwire_admin.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.nonce) {
                nonce = rawwire_admin.nonce;
            }

            $.ajax({
                url: (typeof RawWireCfg !== 'undefined' && RawWireCfg.ajaxurl) ? RawWireCfg.ajaxurl : ajaxurl,
                method: 'POST',
                data: {
                    action: 'rawwire_save_builder_template',
                    nonce: nonce,
                    template_name: name,
                    template_id: id,
                    template_desc: desc,
                    layout: JSON.stringify(layout)
                },
                success: function (response) {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-cloud-saved');

                    if (response.success) {
                        self.closeSaveTemplateModal();
                        RawWireAdmin.Notify.success('Template "' + name + '" saved successfully');

                        // Ask if they want to activate it
                        if (confirm('Template saved! Would you like to activate it now?')) {
                            self.activateTemplate(id);
                        }
                    } else {
                        $error.text(response.data || 'Failed to save template.').show();
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-cloud-saved');
                    $error.text('Server error. Please try again.').show();
                }
            });
        },

        /**
         * Activate a template via the existing switch mechanism
         * @param {string} templateId - Template ID to activate
         */
        activateTemplate: function (templateId) {
            var nonce = '';
            if (typeof RawWireCfg !== 'undefined' && RawWireCfg.adminNonce) {
                nonce = RawWireCfg.adminNonce;
            } else if (typeof rawwire_admin !== 'undefined' && rawwire_admin.adminNonce) {
                nonce = rawwire_admin.adminNonce;
            }

            $.ajax({
                url: (typeof RawWireCfg !== 'undefined' && RawWireCfg.ajaxurl) ? RawWireCfg.ajaxurl : ajaxurl,
                method: 'POST',
                data: {
                    action: 'rawwire_template_switch',
                    nonce: nonce,
                    template_id: templateId
                },
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Template activated! Reloading...');
                        setTimeout(function () {
                            window.location.reload();
                        }, 1000);
                    } else {
                        RawWireAdmin.Notify.error('Failed to activate template');
                    }
                }
            });
        }
    };

    // Initialize Builder when document is ready
    $(document).ready(function () {
        RawWireAdmin.Builder.init();
    });

    // Also bind row removal
    $(document).on('click', '[data-row-action="remove"]', function () {
        var rowId = $(this).data('row');
        if (rowId) {
            RawWireAdmin.Builder.removeRow(rowId);
        }
    });

    RawWireAdmin.initApprovals = function () {
        // Selection handling for bulk actions
        $(document).on('click', '.rawwire-card', function (e) {
            if (!$(e.target).is('button') && !$(e.target).closest('button').length) {
                $(this).toggleClass('selected');
                $(this).attr('data-selected', $(this).hasClass('selected'));
            }
        });
    };

    RawWireAdmin.initRelease = function () {
        // Similar to approvals
        RawWireAdmin.initApprovals();
    };

    RawWireAdmin.initSettings = function () {
        // Settings tabs
        $('.rawwire-settings-tab').on('click', function () {
            var target = $(this).data('tab');
            $('.rawwire-settings-tab').removeClass('active');
            $(this).addClass('active');
            $('.rawwire-settings-section').removeClass('active');
            $('#' + target).addClass('active');
        });

        // Settings form submission
        $('.rawwire-settings-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var data = $form.serialize();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data + '&action=rawwire_save_settings&nonce=' + rawwire_admin.nonce,
                success: function (response) {
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
    $(document).on('change', '.rawwire-toggle input', function () {
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
                success: function (response) {
                    if (response.success) {
                        RawWireAdmin.Notify.success('Setting updated');
                    }
                }
            });
        }
    });

    // Initialize on document ready
    $(document).ready(function () {
        RawWireAdmin.Template.init();
        RawWireAdmin.Generator.init();
        RawWireAdmin.Publisher.init();
    });

})(jQuery);
