/**
 * RawWire Lead Generator — Frontend JS
 *
 * Handles all AJAX interactions for the lead generation pipeline:
 * Import, Score, Approve/Reject, Enrich, Settings, Weight sliders.
 *
 * Depends on: jQuery, RawWireLeads (localized from PHP)
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */
(function ($) {
    'use strict';

    if (typeof RawWireLeads === 'undefined') {
        return;
    }

    var L = RawWireLeads;

    // =========================================================================
    // LOCAL STORAGE PERSISTENCE
    // =========================================================================

    var STORAGE_KEY = 'rw_lead_import_settings';

    /**
     * Save form values to localStorage
     */
    function saveFormState() {
        var state = {
            dataset: $('#rw-dataset').val(),
            permit_types: [],
            permit_sub_types: [],
            min_valuation: $('#rw-min-val').val(),
            date_range_months: $('#rw-date-range').val(),
            limit: $('#rw-limit').val(),
            // Source config form
            source_dataset: $('#rw-source-dataset').val(),
            source_min_val: $('#rw-source-min-val').val(),
            source_months: $('#rw-source-months').val(),
            source_limit: $('#rw-source-limit').val()
        };

        $('input[name="permit_types[]"]:checked').each(function () {
            state.permit_types.push($(this).val());
        });
        $('input[name="permit_sub_types[]"]:checked').each(function () {
            state.permit_sub_types.push($(this).val());
        });

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { }
    }

    /**
     * Restore form values from localStorage
     */
    function restoreFormState() {
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (!saved) return;

            var state = JSON.parse(saved);

            if (state.dataset && $('#rw-dataset').length) {
                $('#rw-dataset').val(state.dataset);
            }
            if (state.min_valuation && $('#rw-min-val').length) {
                $('#rw-min-val').val(state.min_valuation);
            }
            if (state.date_range_months && $('#rw-date-range').length) {
                $('#rw-date-range').val(state.date_range_months);
            }
            if (state.limit && $('#rw-limit').length) {
                $('#rw-limit').val(state.limit);
            }

            // Restore checkboxes
            if (state.permit_types && state.permit_types.length) {
                $('input[name="permit_types[]"]').each(function () {
                    $(this).prop('checked', state.permit_types.indexOf($(this).val()) !== -1);
                });
            }
            if (state.permit_sub_types && state.permit_sub_types.length) {
                $('input[name="permit_sub_types[]"]').each(function () {
                    $(this).prop('checked', state.permit_sub_types.indexOf($(this).val()) !== -1);
                });
            }

            // Source config form
            if (state.source_dataset && $('#rw-source-dataset').length) {
                $('#rw-source-dataset').val(state.source_dataset);
            }
            if (state.source_min_val && $('#rw-source-min-val').length) {
                $('#rw-source-min-val').val(state.source_min_val);
            }
            if (state.source_months && $('#rw-source-months').length) {
                $('#rw-source-months').val(state.source_months);
            }
            if (state.source_limit && $('#rw-source-limit').length) {
                $('#rw-source-limit').val(state.source_limit);
            }
        } catch (e) { }
    }

    // Auto-save on form changes
    $(document).on('change', '#rw-dataset, #rw-min-val, #rw-date-range, #rw-limit, input[name="permit_types[]"], input[name="permit_sub_types[]"], #rw-source-dataset, #rw-source-min-val, #rw-source-months, #rw-source-limit', function () {
        saveFormState();
    });

    // Restore on page load
    $(document).ready(function () {
        restoreFormState();
    });

    // =========================================================================
    // TOAST NOTIFICATION SYSTEM
    // =========================================================================

    var toastContainer = null;

    /**
     * Initialize toast container
     */
    function initToastContainer() {
        if (toastContainer) return;

        toastContainer = $('<div class="rw-toast-container"></div>');
        $('body').append(toastContainer);

        // Inject styles if not already present
        if (!$('#rw-toast-styles').length) {
            var styles = '<style id="rw-toast-styles">' +
                '.rw-toast-container{position:fixed;top:32px;right:20px;z-index:100000;max-width:400px}' +
                '.rw-toast{display:flex;align-items:flex-start;padding:12px 16px;margin-bottom:10px;border-radius:6px;' +
                'box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;line-height:1.4;animation:rwToastIn 0.3s ease}' +
                '@keyframes rwToastIn{from{opacity:0;transform:translateX(100%)}to{opacity:1;transform:translateX(0)}}' +
                '@keyframes rwToastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(100%)}}' +
                '.rw-toast-success{background:#d4edda;border-left:4px solid #28a745;color:#155724}' +
                '.rw-toast-error{background:#f8d7da;border-left:4px solid #dc3545;color:#721c24}' +
                '.rw-toast-info{background:#d1ecf1;border-left:4px solid #17a2b8;color:#0c5460}' +
                '.rw-toast-warning{background:#fff3cd;border-left:4px solid #ffc107;color:#856404}' +
                '.rw-toast-icon{margin-right:10px;font-size:18px;flex-shrink:0}' +
                '.rw-toast-content{flex:1}' +
                '.rw-toast-title{font-weight:600;margin-bottom:4px}' +
                '.rw-toast-message{opacity:0.9}' +
                '.rw-toast-close{background:none;border:none;cursor:pointer;opacity:0.5;padding:0 0 0 10px;font-size:18px}' +
                '.rw-toast-close:hover{opacity:1}' +
                '.rw-toast-progress{position:absolute;bottom:0;left:0;height:3px;background:rgba(0,0,0,0.1);border-radius:0 0 0 6px}' +
                '</style>';
            $('head').append(styles);
        }
    }

    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {string} type - 'success', 'error', 'info', 'warning'
     * @param {Object} options - Optional settings (title, duration, dismissible)
     */
    function toast(message, type, options) {
        initToastContainer();

        type = type || 'info';
        options = options || {};
        var duration = options.duration !== undefined ? options.duration : (type === 'error' ? 8000 : 5000);
        var title = options.title || '';
        var dismissible = options.dismissible !== false;

        var icons = {
            success: '&#10004;',
            error: '&#10006;',
            info: '&#8505;',
            warning: '&#9888;'
        };

        var $toast = $('<div class="rw-toast rw-toast-' + type + '">' +
            '<span class="rw-toast-icon">' + icons[type] + '</span>' +
            '<div class="rw-toast-content">' +
            (title ? '<div class="rw-toast-title">' + title + '</div>' : '') +
            '<div class="rw-toast-message">' + message + '</div>' +
            '</div>' +
            (dismissible ? '<button class="rw-toast-close">&times;</button>' : '') +
            '</div>');

        toastContainer.append($toast);

        // Close button handler
        $toast.find('.rw-toast-close').on('click', function () {
            dismissToast($toast);
        });

        // Auto dismiss
        if (duration > 0) {
            setTimeout(function () {
                dismissToast($toast);
            }, duration);
        }

        return $toast;
    }

    /**
     * Dismiss a toast
     */
    function dismissToast($toast) {
        $toast.css('animation', 'rwToastOut 0.3s ease forwards');
        setTimeout(function () {
            $toast.remove();
        }, 300);
    }

    /**
     * Legacy notify function - now uses toast system
     */
    function notify(message, type) {
        toast(message, type);
    }

    // =========================================================================
    // AJAX UTILITY
    // =========================================================================

    function ajax(action, data, callbacks) {
        data = data || {};
        data.action = action;
        data.nonce = L.nonce;

        return $.ajax({
            url: L.ajaxurl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function (resp) {
                if (resp.success && callbacks && callbacks.success) {
                    callbacks.success(resp.data);
                } else if (!resp.success && callbacks && callbacks.error) {
                    callbacks.error(resp.data);
                } else if (!resp.success) {
                    toast(resp.data && resp.data.message ? resp.data.message : 'An error occurred.', 'error');
                }
            },
            error: function (xhr) {
                var msg = 'Request failed.';
                try { msg = JSON.parse(xhr.responseText).data.message; } catch (e) { }
                if (callbacks && callbacks.error) {
                    callbacks.error({ message: msg });
                } else {
                    toast(msg, 'error');
                }
            },
            complete: function () {
                if (callbacks && callbacks.complete) {
                    callbacks.complete();
                }
            }
        });
    }

    function btnLoading($btn, loading) {
        if (loading) {
            $btn.data('orig-text', $btn.html()).html('<span class="spinner is-active" style="margin:0;float:none;"></span>').prop('disabled', true);
        } else {
            $btn.html($btn.data('orig-text')).prop('disabled', false);
        }
    }

    // =========================================================================
    // IMPORT (Sources Page — Import Tab)
    // =========================================================================

    /**
     * Gather all import form values into a data object
     */
    function gatherImportParams() {
        var data = {
            // Data source
            dataset: $('#rw-import-dataset').val(),

            // Value & scale
            min_valuation: $('#rw-import-min-val').val() || 100000,
            max_valuation: $('#rw-import-max-val').val() || '',
            min_sqft: $('#rw-import-min-sqft').val() || '',

            // Date & recency
            date_months: $('#rw-import-date-range').val() || 6,
            sort_by: $('#rw-import-sort-by').val() || 'issue_date',
            sort_order: $('#rw-import-sort-order').val() || 'DESC',

            // Location filters
            zip_codes: $('#rw-import-zip').val() || '',
            council_districts: $('#rw-import-cd').val() || '',
            community_plan_area: $('#rw-import-cpa').val() || '',
            zone: $('#rw-import-zone').val() || '',

            // Building use & status
            use_desc_search: $('#rw-import-use-desc').val() || '',
            use_code: $('#rw-import-use-code').val() || '',
            work_desc_search: $('#rw-import-work-desc').val() || '',
            status_filter: $('#rw-import-status').val() || '',

            // Green & specialty
            solar_only: $('#rw-import-solar').is(':checked') ? 1 : 0,
            ev_only: $('#rw-import-ev').is(':checked') ? 1 : 0,
            construction_type: $('#rw-import-construction').val() || '',

            // Contractor filters
            require_party_data: $('#rw-import-require-party').is(':checked') ? 1 : 0,
            exclude_owner_builder: $('#rw-import-exclude-owner').is(':checked') ? 1 : 0,
            contractor_name_search: $('#rw-import-contractor-name').val() || '',
            license_search: $('#rw-import-license').val() || '',

            // Import controls
            limit: $('#rw-import-limit').val() || 500,
            auto_score: $('#rw-import-auto-score').is(':checked') ? 1 : 0,
            auto_promote: $('#rw-import-auto-promote').is(':checked') ? 1 : 0,
            import_name: $('#rw-import-name').val() || '',

            // Permit type matrices
            permit_types: [],
            permit_sub_types: [],
        };

        $('input[name="permit_types[]"]:checked').each(function () {
            data.permit_types.push($(this).val());
        });
        $('input[name="permit_sub_types[]"]:checked').each(function () {
            data.permit_sub_types.push($(this).val());
        });

        return data;
    }

    // Preview available records count
    $(document).on('click', '.rw-preview-count-btn', function () {
        var $btn = $(this);
        btnLoading($btn, true);
        ajax('rawwire_lead_preview', gatherImportParams(), {
            success: function (data) {
                $('.rw-preview-result').html(
                    '<strong>' + (data.available || 0) + '</strong> records match current filters'
                ).show();
            },
            error: function (data) {
                $('.rw-preview-result').html('<span style="color:#e74c3c;">' + data.message + '</span>').show();
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // Save current import form values as defaults
    $(document).on('click', '.rw-save-import-defaults-btn', function () {
        var $btn = $(this);
        var params = gatherImportParams();

        var settings = {
            primary_dataset: params.dataset,
            import_limit: params.limit,
            min_valuation: params.min_valuation,
            date_range_months: params.date_months,
            auto_score: params.auto_score,
            auto_promote: params.auto_promote,
            permit_types: params.permit_types,
            permit_sub_types: params.permit_sub_types,
        };

        btnLoading($btn, true);
        ajax('rawwire_lead_save_settings', settings, {
            success: function () {
                notify('Import defaults saved.', 'success');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // Start import (direct Socrata import)
    $(document).on('click', '.rw-import-btn', function () {
        var $btn = $(this);
        var $prog = $('.rw-import-progress');

        var data = gatherImportParams();

        btnLoading($btn, true);
        $prog.html('<div class="rw-progress-bar"><div class="rw-progress-fill" style="width:15%"></div></div><span>' + L.strings.importing + '</span>').show();

        ajax('rawwire_lead_import', data, {
            success: function (result) {
                $prog.find('.rw-progress-fill').css('width', '100%');
                var msg = L.strings.import_complete + ' ' +
                    result.imported + ' imported, ' + result.skipped + ' skipped, ' + result.errors + ' errors.';
                if (result.auto_scored) {
                    msg += ' Auto-scored: ' + result.auto_scored.scored + ' records.';
                }
                notify(msg, 'success');
                setTimeout(function () { $prog.hide(); refreshStats(); }, 1500);
            },
            error: function (data) {
                $prog.hide();
                notify(data.message || 'Import failed.', 'error');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });


    // NOTE: WPAI create import handler removed - using custom Socrata importer


    // =========================================================================
    // SCORING (Sources Page — Pipeline Tab / Scoring Tab)
    // =========================================================================

    $(document).on('click', '.rw-score-btn', function () {
        var $btn = $(this);
        btnLoading($btn, true);
        notify(L.strings.scoring, 'info');

        ajax('rawwire_lead_score', { limit: 100 }, {
            success: function (data) {
                var msg = L.strings.score_complete + ' ' + data.scored + '/' + data.total + ' scored.';
                if (data.promotion) {
                    msg += ' Promoted: ' + data.promotion.promoted + ', Archived: ' + data.promotion.archived + '.';
                }
                notify(msg, 'success');
                refreshStats();
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // Promote candidates manually
    $(document).on('click', '.rw-promote-btn', function () {
        var $btn = $(this);
        btnLoading($btn, true);

        ajax('rawwire_lead_promote', {}, {
            success: function (data) {
                notify('Promoted: ' + data.promoted + ', Archived: ' + data.archived + ' (threshold: ' + data.threshold + ')', 'success');
                refreshStats();
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // =========================================================================
    // APPROVE / REJECT (Approvals Page)
    // =========================================================================

    $(document).on('click', '.rw-approve-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        var $card = $btn.closest('.rw-candidate-card');

        if (!confirm(L.strings.confirm_approve)) return;

        btnLoading($btn, true);
        ajax('rawwire_lead_approve', { candidate_id: id }, {
            success: function () {
                $card.addClass('rw-card-approved').fadeOut(500, function () { $card.remove(); });
                notify('Candidate #' + id + ' approved and queued for enrichment.', 'success');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    $(document).on('click', '.rw-reject-btn, .rw-trash-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        var $card = $btn.closest('.rw-candidate-card');

        if (!confirm(L.strings.confirm_reject || 'Move this candidate to trash?')) return;

        btnLoading($btn, true);
        ajax('rawwire_lead_trash', { candidate_id: id }, {
            success: function () {
                $card.addClass('rw-card-trashed').fadeOut(500, function () { $card.remove(); });
                notify('Candidate #' + id + ' moved to trash.', 'success');
                refreshStats();
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    $(document).on('click', '.rw-restore-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        var $card = $btn.closest('.rw-candidate-card');

        btnLoading($btn, true);
        ajax('rawwire_lead_restore', { candidate_id: id }, {
            success: function () {
                $card.fadeOut(500, function () { $card.remove(); });
                notify('Candidate #' + id + ' restored.', 'success');
                refreshStats();
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    $(document).on('click', '.rw-purge-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        var $card = $btn.closest('.rw-candidate-card');

        if (!confirm('Permanently delete this candidate? This cannot be undone.')) return;

        btnLoading($btn, true);
        ajax('rawwire_lead_purge', { candidate_id: id }, {
            success: function () {
                $card.fadeOut(500, function () { $card.remove(); });
                notify('Candidate #' + id + ' permanently deleted.', 'success');
                refreshStats();
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // =========================================================================
    // INVESTIGATE (Manual Investigation)
    // =========================================================================

    $(document).on('click', '.rw-investigate-btn', function () {
        var $btn = $(this);
        var candidateId = $btn.data('id');
        var sourceId = $btn.data('source');
        var $card = $btn.closest('.rw-candidate-card');
        var $invSection = $btn.closest('.rw-card-investigation');

        console.log('[RW Investigate] Button clicked:', { candidateId: candidateId, sourceId: sourceId });

        if (!confirm('Run investigation on this record? This will use AI API tokens.')) {
            console.log('[RW Investigate] User cancelled');
            return;
        }

        // Show loading state
        btnLoading($btn, true);
        $btn.prop('disabled', true).text('Investigating...');
        toast('Starting investigation for candidate #' + candidateId + '...', 'info');
        console.log('[RW Investigate] Sending AJAX request...');

        ajax('rawwire_lead_investigate', { candidate_id: candidateId, source_id: sourceId }, {
            success: function (data) {
                console.log('[RW Investigate] Success:', data);

                var result = data.result || {};
                var message = '';

                // Check if investigation was skipped
                if (result.skipped) {
                    message = 'Skipped: ' + (result.reason || 'Already investigated');
                    $invSection.find('.rw-inv-badge')
                        .removeClass('rw-inv-pending')
                        .addClass('rw-inv-complete')
                        .html('Investigation: ' + (result.reason || 'Skipped'));
                    toast(message, 'warning');
                } else if (result.success) {
                    var parties = result.parties_count || 0;
                    message = 'Completed! ' + parties + ' parties investigated.';
                    $invSection.find('.rw-inv-badge')
                        .removeClass('rw-inv-pending rw-inv-failed')
                        .addClass('rw-inv-complete')
                        .html('Investigation: Complete (' + parties + ' parties)');
                    $btn.remove();
                    toast(message, 'success');
                } else {
                    message = data.message || 'Investigation finished.';
                    $invSection.find('.rw-inv-badge')
                        .removeClass('rw-inv-pending rw-inv-failed')
                        .addClass('rw-inv-complete')
                        .html('Investigation: Complete');
                    $btn.remove();
                    toast(message, 'success');
                }
            },
            error: function (err) {
                var msg = err && err.message ? err.message : (typeof err === 'string' ? err : 'Unknown error');
                console.error('[RW Investigate] Error:', msg);
                $invSection.find('.rw-inv-badge')
                    .removeClass('rw-inv-pending')
                    .addClass('rw-inv-failed')
                    .html('Investigation: Failed');
                toast('Investigation failed: ' + msg, 'error');
            },
            complete: function () {
                console.log('[RW Investigate] Request complete');
                btnLoading($btn, false);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Investigate');
            },
        });
    });

    // Bulk actions
    $(document).on('click', '.rw-bulk-action-btn', function () {
        var action = $('#rw-bulk-action-select').val();
        if (!action) return;

        var ids = [];
        $('.rw-candidate-checkbox:checked').each(function () {
            ids.push($(this).val());
        });

        if (ids.length === 0) {
            notify(L.strings.no_selection, 'error');
            return;
        }

        if (!confirm(L.strings.confirm_bulk)) return;

        var $btn = $(this);
        btnLoading($btn, true);

        ajax('rawwire_lead_bulk_action', { bulk_action: action, ids: ids }, {
            success: function (data) {
                notify('Bulk action: ' + data.success + ' succeeded, ' + data.failed + ' failed.', 'success');
                setTimeout(function () { location.reload(); }, 1000);
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // Select all candidates
    $(document).on('change', '.rw-select-all-candidates', function () {
        $('.rw-candidate-checkbox').prop('checked', $(this).is(':checked'));
    });

    // =========================================================================
    // SCORE BREAKDOWN TOGGLE (Approvals Page)
    // =========================================================================

    $(document).on('click', '.rw-score-toggle', function (e) {
        e.preventDefault();
        var $target = $(this).closest('.rw-candidate-card').find('.rw-score-breakdown');
        $target.slideToggle(200);
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    // =========================================================================
    // WEIGHTS (Sources Page — Scoring Tab)
    // =========================================================================

    // Weight slider live value update
    $(document).on('input', '.rw-weight-slider', function () {
        $(this).siblings('.rw-weight-value').text(parseFloat($(this).val()).toFixed(1));
    });

    // Save weights
    $(document).on('click', '.rw-save-weights-btn', function () {
        var $btn = $(this);
        var weights = {};
        $('.rw-weight-slider').each(function () {
            weights[$(this).data('key')] = parseFloat($(this).val());
        });

        btnLoading($btn, true);
        ajax('rawwire_lead_update_weights', { weights: weights }, {
            success: function () {
                notify('Scoring weights updated.', 'success');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // Re-score all candidates with current weights
    $(document).on('click', '.rw-rescore-btn', function () {
        var $btn = $(this);
        if (!confirm('Re-score all candidates with current weights? This may take a moment.')) return;

        btnLoading($btn, true);
        ajax('rawwire_lead_rescore', { limit: 500 }, {
            success: function (data) {
                notify('Re-scored ' + data.scored + ' records.', 'success');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // =========================================================================
    // BLACKLIST KEYWORDS (Sources Page — Scoring Tab)
    // =========================================================================

    function getBlacklistKeywords() {
        var keywords = [];
        $('#rw-blacklist-tags .rw-blacklist-tag').each(function () {
            keywords.push($(this).data('keyword'));
        });
        return keywords;
    }

    function addBlacklistTag(keyword) {
        keyword = $.trim(keyword).toLowerCase();
        if (!keyword) return;

        // Check for duplicates
        var existing = getBlacklistKeywords();
        if (existing.indexOf(keyword) !== -1) {
            notify('Keyword "' + keyword + '" already exists.', 'warning');
            return;
        }

        // Remove empty state message
        $('#rw-blacklist-tags .rw-blacklist-empty').remove();

        var $tag = $('<span class="rw-blacklist-tag" data-keyword="' + keyword + '" ' +
            'style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#fee;border:1px solid #c00;border-radius:12px;font-size:13px;color:#900;">' +
            keyword +
            '<button type="button" class="rw-blacklist-remove" data-keyword="' + keyword + '" ' +
            'style="background:none;border:none;color:#c00;cursor:pointer;font-size:16px;line-height:1;padding:0 2px;" title="Remove">&times;</button>' +
            '</span>');

        $('#rw-blacklist-tags').append($tag);
        updateBlacklistCount();
    }

    function updateBlacklistCount() {
        var count = getBlacklistKeywords().length;
        $('.rw-blacklist-count').text(count + ' keyword(s)');
    }

    // Add keyword via button or Enter key
    $(document).on('click', '#rw-blacklist-add-btn', function () {
        var $input = $('#rw-blacklist-input');
        addBlacklistTag($input.val());
        $input.val('').focus();
    });

    $(document).on('keypress', '#rw-blacklist-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            var $input = $(this);
            addBlacklistTag($input.val());
            $input.val('').focus();
        }
    });

    // Remove keyword tag
    $(document).on('click', '.rw-blacklist-remove', function () {
        $(this).closest('.rw-blacklist-tag').remove();
        updateBlacklistCount();
        // Show empty message if no tags left
        if ($('#rw-blacklist-tags .rw-blacklist-tag').length === 0) {
            $('#rw-blacklist-tags').html(
                '<span class="rw-blacklist-empty" style="color:#999;font-style:italic;">No blacklist keywords set. Records are scored without penalty.</span>'
            );
        }
    });

    // Save blacklist
    $(document).on('click', '#rw-save-blacklist', function () {
        var $btn = $(this);
        var keywords = getBlacklistKeywords();

        btnLoading($btn, true);
        ajax('rawwire_lead_save_blacklist', { keywords: keywords.join(',') }, {
            success: function (data) {
                notify('Blacklist saved — ' + data.count + ' keyword(s).', 'success');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // =========================================================================
    // SETTINGS (Sources Page — Settings Tab)
    // =========================================================================

    $(document).on('click', '.rw-save-settings-btn', function () {
        var $btn = $(this);
        var settings = {};

        $('.rw-setting-field').each(function () {
            var $f = $(this);
            var key = $f.data('key');
            if ($f.is(':checkbox')) {
                settings[key] = $f.is(':checked') ? 1 : 0;
            } else {
                settings[key] = $f.val();
            }
        });

        // Collect permit type checkboxes
        settings.permit_types = [];
        $('input[name="settings_permit_types[]"]:checked').each(function () {
            settings.permit_types.push($(this).val());
        });
        settings.permit_sub_types = [];
        $('input[name="settings_permit_sub_types[]"]:checked').each(function () {
            settings.permit_sub_types.push($(this).val());
        });

        btnLoading($btn, true);
        ajax('rawwire_lead_save_settings', settings, {
            success: function () {
                notify('Settings saved.', 'success');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // =========================================================================
    // RESET TABLES
    // =========================================================================

    $(document).on('click', '.rw-reset-tables-btn', function () {
        var $btn = $(this);

        if (!confirm('⚠️ WARNING: This will permanently delete ALL lead data from all tables.\n\nAre you absolutely sure?')) {
            return;
        }

        // Double confirmation for safety
        if (!confirm('Last chance! This cannot be undone. Proceed?')) {
            return;
        }

        btnLoading($btn, true);
        ajax('rawwire_lead_reset_tables', {}, {
            success: function (response) {
                notify(response.message || 'Tables cleared successfully.', 'success');
                // Reload page to show fresh stats
                setTimeout(function () {
                    location.reload();
                }, 1500);
            },
            error: function (response) {
                notify(response?.message || 'Failed to reset tables.', 'error');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // =========================================================================
    // LEAD STATUS (Leads Page)
    // =========================================================================

    $(document).on('change', '.rw-lead-status-select', function () {
        var $sel = $(this);
        var id = $sel.data('id');
        var newStatus = $sel.val();

        ajax('rawwire_lead_update_status', { content_id: id, status: newStatus }, {
            success: function () {
                var $card = $sel.closest('.rw-lead-card');
                $card.removeClass('rw-lead-status-enriching rw-lead-status-ready rw-lead-status-contacted rw-lead-status-converted rw-lead-status-lost')
                    .addClass('rw-lead-status-' + newStatus);
                notify('Lead status updated to: ' + newStatus, 'success');
            },
        });
    });

    // Expand/collapse lead details
    $(document).on('click', '.rw-lead-expand-btn', function () {
        var id = $(this).data('id');
        var $target = $('#rw-lead-details-' + id);
        $target.slideToggle(250);
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    // Enrich single lead
    $(document).on('click', '.rw-enrich-single-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');

        btnLoading($btn, true);
        notify(L.strings.enriching, 'info');

        ajax('rawwire_lead_enrich', { content_id: id }, {
            success: function (data) {
                notify('Enrichment complete: ' + (data.project_name || 'Lead') + ' is ready.', 'success');
                setTimeout(function () { location.reload(); }, 1200);
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });

    // Batch enrich
    $(document).on('click', '.rw-enrich-batch-btn', function () {
        var $btn = $(this);
        btnLoading($btn, true);
        notify(L.strings.enriching, 'info');

        // Use score action with special flag — or just enrich multiple
        // For batch, we loop enriching status records
        ajax('rawwire_lead_enrich', { content_id: 0, batch: 1 }, {
            success: function (data) {
                notify('Batch enrichment: ' + (data.enriched || 0) + ' processed.', 'success');
                setTimeout(function () { location.reload(); }, 1200);
            },
            error: function () {
                // Fallback: reload page
                notify('Batch enrichment request sent.', 'info');
            },
            complete: function () {
                btnLoading($btn, false);
            },
        });
    });


    // NOTE: WP All Import integration removed - using custom Socrata importer

    // =========================================================================
    // TABS (Sources Page)
    // =========================================================================

    $(document).on('click', '.rw-lead-tab', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.rw-lead-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.rw-lead-tab-content').hide();
        $('#rw-tab-' + tab).show();
    });

    // =========================================================================
    // PERMIT PIPELINE (LADBS Scraper + Lead Sources Integration)
    // =========================================================================

    // Generic handler for rw-lead-action buttons
    $(document).on('click', '.rw-lead-action', function () {
        var $btn = $(this);
        var action = $btn.data('action');
        var confirmMsg = $btn.data('confirm');

        if (confirmMsg && !confirm(confirmMsg)) {
            return;
        }

        btnLoading($btn, true);

        // Handle permit pipeline action specifically
        if (action === 'rw_run_permit_pipeline') {
            toast('Running permit pipeline...', 'info', { title: 'Pipeline Started', duration: 10000 });

            ajax(action, { limit: 10 }, {
                success: function (data) {
                    var msg = 'Pipeline complete! ';
                    msg += 'Found: ' + (data.permits_found || 0) + ', ';
                    msg += 'Scraped: ' + (data.scraped || 0) + ', ';
                    msg += 'Inserted: ' + (data.inserted || 0) + ', ';
                    msg += 'Scored: ' + (data.scored || 0);

                    toast(msg, 'success', { title: 'Pipeline Complete', duration: 8000 });

                    if (data.messages && data.messages.length) {
                        data.messages.forEach(function (m) {
                            console.log('Pipeline:', m);
                        });
                    }

                    // Refresh stats after pipeline
                    refreshStats();
                    setTimeout(function () { location.reload(); }, 2500);
                },
                error: function (data) {
                    toast(data.message || 'Pipeline failed.', 'error', { title: 'Pipeline Error' });
                },
                complete: function () {
                    btnLoading($btn, false);
                }
            });
            return;
        }

        // Generic action handler for other buttons
        ajax(action, {}, {
            success: function (data) {
                toast('Action completed successfully.', 'success');
                refreshStats();
            },
            error: function (data) {
                toast(data.message || 'Action failed.', 'error');
            },
            complete: function () {
                btnLoading($btn, false);
            }
        });
    });

    // =========================================================================
    // PIPELINE STATS REFRESH
    // =========================================================================

    function refreshStats() {
        ajax('rawwire_lead_get_stats', {}, {
            success: function (data) {
                // Update stat cards if they exist
                $('.rw-stat-sources').text(data.sources ? data.sources.total : 0);
                $('.rw-stat-unprocessed').text(data.sources ? data.sources.unprocessed : 0);
                $('.rw-stat-candidates').text(data.candidates ? data.candidates.total : 0);
                $('.rw-stat-pending').text(data.candidates ? data.candidates.pending : 0);
                $('.rw-stat-leads').text(data.leads ? data.leads.total : 0);
                $('.rw-stat-ready').text(data.leads ? data.leads.ready : 0);
                $('.rw-stat-archived').text(data.archive ? data.archive.total : 0);
            },
        });
    }

    // =========================================================================
    // SORT CONTROL (Leads Page)
    // =========================================================================

    $(document).on('change', '#rw-leads-sort', function () {
        var sort = $(this).val();
        var url = new URL(window.location.href);
        url.searchParams.set('orderby', sort);
        url.searchParams.set('order', 'desc');
        window.location.href = url.toString();
    });

    // =========================================================================
    // INIT
    // =========================================================================

    $(document).ready(function () {
        // Show first tab by default on sources page
        if ($('.rw-lead-tab').length && !$('.rw-lead-tab.nav-tab-active').length) {
            $('.rw-lead-tab').first().trigger('click');
        }
    });

})(jQuery);
