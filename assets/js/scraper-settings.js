/**
 * Scraper Settings JavaScript
 * Handles horizontal form interactions, source management, and AJAX operations
 */
(function($) {
    'use strict';

    // Configuration from PHP
    const config = window.RawWireScraperCfg || {};
    
    /**
     * Initialize all event handlers
     */
    function init() {
        // Enable toggle
        $('#scraper-enabled').on('change', handleEnableToggle);
        
        // Address type toggle (URL/API)
        $('.rawwire-radio-btn').on('click', handleAddressTypeToggle);
        
        // Authentication type change
        $('#source-auth-type').on('change', handleAuthTypeChange);
        
        // Source type change (show/hide HTML selectors)
        $('#source-type').on('change', handleSourceTypeChange);
        
        // Add source button
        $('#add-source-btn').on('click', handleAddSource);
        
        // Preset buttons
        $('.rawwire-preset-btn').on('click', handlePresetClick);
        
        // Table row actions (delegated)
        $('#sources-tbody').on('click', '.test-source', handleTestSource);
        $('#sources-tbody').on('click', '.edit-source', handleEditSource);
        $('#sources-tbody').on('click', '.delete-source', handleDeleteSource);
        $('#sources-tbody').on('change', '.source-toggle', handleSourceToggle);
        
        // Bulk actions
        $('#test-all-btn').on('click', handleTestAll);
        $('#enable-all-btn').on('click', handleEnableAll);
        $('#disable-all-btn').on('click', handleDisableAll);
        $('#clear-all-btn').on('click', handleClearAll);
        
        // Settings save
        $('#save-settings-btn').on('click', handleSaveSettings);
        $('#run-now-btn').on('click', handleRunNow);
        
        // Auto-schedule toggle
        $('#auto-schedule').on('change', function() {
            $('.rawwire-schedule-field').toggle(this.checked);
        });
    }
    
    /**
     * Handle enable/disable toggle
     */
    function handleEnableToggle() {
        const enabled = $(this).is(':checked');
        const $badge = $('.rawwire-status-badge');
        const $config = $('#scraper-config');
        
        if (enabled) {
            $badge.removeClass('inactive').addClass('active').text('Active');
            $config.slideDown(300);
        } else {
            $badge.removeClass('active').addClass('inactive').text('Disabled');
            $config.slideUp(300);
        }
        
        // Save enabled state
        saveSettings({ enabled: enabled });
    }
    
    /**
     * Handle URL/API toggle
     */
    function handleAddressTypeToggle() {
        const $this = $(this);
        const value = $this.data('value');
        
        // Update active state
        $('.rawwire-radio-btn').removeClass('active');
        $this.addClass('active');
        $this.find('input').prop('checked', true);
        
        // Update label
        $('#address-label').text(value === 'api' ? 'API Endpoint' : 'URL');
        $('#source-address').attr('placeholder', value === 'api' 
            ? 'https://api.example.com/v1/data' 
            : 'https://example.com/data');
    }
    
    /**
     * Handle auth type change
     */
    function handleAuthTypeChange() {
        const authType = $(this).val();
        
        // Hide all auth fields first
        $('#auth-key-field, #auth-user-field, #auth-pass-field').hide();
        
        // Show relevant fields
        switch (authType) {
            case 'api_key':
            case 'bearer_token':
                $('#auth-key-field').show();
                break;
            case 'basic_auth':
                $('#auth-user-field, #auth-pass-field').show();
                break;
            case 'oauth2':
                $('#auth-key-field').show();
                break;
        }
    }
    
    /**
     * Handle source type change
     */
    function handleSourceTypeChange() {
        const sourceType = $(this).val();
        
        // Show HTML selectors for html_scrape type
        if (sourceType === 'html_scrape') {
            $('.rawwire-html-selectors').show();
        } else {
            $('.rawwire-html-selectors').hide();
        }
    }
    
    /**
     * Handle Add Source button
     */
    function handleAddSource() {
        const $btn = $(this);
        
        // Collect form data
        const sourceData = collectSourceFormData();
        
        // Validate required fields
        if (!sourceData.name || !sourceData.address || !sourceData.output_table || !sourceData.columns) {
            showNotice('Please fill in all required fields', 'error');
            highlightEmptyFields();
            return;
        }
        
        // Show loading state
        $btn.addClass('rawwire-loading').prop('disabled', true);
        
        // Save via AJAX
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_save_scraper_source',
                nonce: config.nonce,
                source: sourceData
            },
            success: function(response) {
                if (response.success) {
                    // Add row to table
                    addSourceToTable(response.data.id, sourceData);
                    
                    // Clear form for next entry
                    clearSourceForm();
                    
                    // Update count
                    updateSourceCount(1);
                    
                    showNotice('Source added successfully!', 'success');
                } else {
                    showNotice(response.data.message || 'Error adding source', 'error');
                }
            },
            error: function() {
                showNotice('Server error. Please try again.', 'error');
            },
            complete: function() {
                $btn.removeClass('rawwire-loading').prop('disabled', false);
            }
        });
    }
    
    /**
     * Collect data from the source form
     */
    function collectSourceFormData() {
        return {
            name: $('#source-name').val().trim(),
            type: $('#source-type').val(),
            address_type: $('input[name="address-type"]:checked').val(),
            address: $('#source-address').val().trim(),
            auth_type: $('#source-auth-type').val(),
            auth_key: $('#source-auth-key').val(),
            auth_user: $('#source-auth-user').val(),
            auth_pass: $('#source-auth-pass').val(),
            records_limit: parseInt($('#source-records').val()) || 10,
            copyright: $('#source-copyright').val(),
            output_table: $('#source-table').val().trim(),
            columns: $('#source-columns').val().trim(),
            timeout: parseInt($('#source-timeout').val()) || 30,
            delay: parseFloat($('#source-delay').val()) || 1,
            headers: $('#source-headers').val().trim(),
            params: $('#source-params').val().trim(),
            selectors: {
                title: $('#selector-title').val(),
                content: $('#selector-content').val(),
                date: $('#selector-date').val(),
                link: $('#selector-link').val()
            },
            enabled: true
        };
    }
    
    /**
     * Clear the source form for new entry
     */
    function clearSourceForm() {
        $('#source-name').val('').focus();
        $('#source-type').val('rest_api');
        $('#source-address').val('');
        $('#source-auth-type').val('none').trigger('change');
        $('#source-auth-key, #source-auth-user, #source-auth-pass').val('');
        $('#source-records').val('10');
        $('#source-copyright').val('public_domain');
        $('#source-table').val('candidates'); // Default to candidates workflow table
        // Keep columns as default for convenience
        $('#source-timeout').val('30');
        $('#source-delay').val('1');
        $('#source-headers, #source-params').val('');
        $('#selector-title, #selector-content, #selector-date, #selector-link').val('');
        
        // Reset address type toggle
        $('.rawwire-radio-btn').removeClass('active');
        $('.rawwire-radio-btn[data-value="url"]').addClass('active');
        $('input[name="address-type"][value="url"]').prop('checked', true);
    }
    
    /**
     * Add a source row to the table
     */
    function addSourceToTable(id, source) {
        const typeLabels = config.sourceTypes || {};
        const authLabels = config.authTypes || {};
        
        const typeLabel = typeLabels[source.type] || source.type;
        const authLabel = authLabels[source.auth_type] || 'None';
        const colCount = source.columns.split(',').filter(c => c.trim()).length;
        
        const $row = $(`
            <tr data-source-id="${id}" class="rawwire-success-flash">
                <td class="col-enabled">
                    <label class="rawwire-mini-switch">
                        <input type="checkbox" class="source-toggle" checked>
                        <span class="slider"></span>
                    </label>
                </td>
                <td class="col-name"><strong>${escapeHtml(source.name)}</strong></td>
                <td class="col-type">
                    <span class="rawwire-type-badge type-${source.type}">${escapeHtml(typeLabel)}</span>
                </td>
                <td class="col-address">
                    <span class="rawwire-address" title="${escapeHtml(source.address)}">
                        ${escapeHtml(source.address.substring(0, 40))}...
                    </span>
                </td>
                <td class="col-auth">
                    ${source.auth_type !== 'none' 
                        ? `<span class="rawwire-auth-badge"><span class="dashicons dashicons-lock"></span>${escapeHtml(authLabel)}</span>`
                        : '<span class="rawwire-auth-none">None</span>'}
                </td>
                <td class="col-records"><span class="rawwire-records">${source.records_limit}</span></td>
                <td class="col-table"><code>${escapeHtml(source.output_table)}</code></td>
                <td class="col-columns"><span class="rawwire-columns">${colCount} fields</span></td>
                <td class="col-copyright">
                    <span class="rawwire-copyright-badge copyright-${source.copyright}">
                        ${escapeHtml(source.copyright.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()))}
                    </span>
                </td>
                <td class="col-actions">
                    <div class="rawwire-action-btns">
                        <button type="button" class="rawwire-icon-btn test-source" title="Test Connection">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </button>
                        <button type="button" class="rawwire-icon-btn edit-source" title="Edit">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button type="button" class="rawwire-icon-btn delete-source" title="Delete">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </td>
            </tr>
        `);
        
        // Remove empty state row if present
        $('#empty-row').remove();
        
        // Add to table
        $('#sources-tbody').append($row);
    }
    
    /**
     * Handle preset button click
     */
    function handlePresetClick() {
        const presetKey = $(this).data('preset');
        const preset = config.presets?.[presetKey];
        
        if (!preset) {
            showNotice('Preset not found', 'error');
            return;
        }
        
        // Fill form with preset values
        $('#source-name').val(preset.name);
        $('#source-type').val(preset.protocol || 'rest_api');
        $('#source-address').val(preset.url);
        $('#source-copyright').val(preset.copyright || 'public_domain');
        
        // Always default to candidates table for workflow
        $('#source-table').val('candidates');
        
        // Set address type based on protocol (api endpoint vs regular url)
        const isApiProtocol = ['rest_api', 'graphql'].includes(preset.protocol);
        if (isApiProtocol) {
            $('.rawwire-radio-btn').removeClass('active');
            $('.rawwire-radio-btn[data-value="api"]').addClass('active');
            $('input[name="address-type"][value="api"]').prop('checked', true);
            $('#address-label').text('API Endpoint');
        } else {
            $('.rawwire-radio-btn').removeClass('active');
            $('.rawwire-radio-btn[data-value="url"]').addClass('active');
            $('input[name="address-type"][value="url"]').prop('checked', true);
            $('#address-label').text('URL');
        }
        
        // Handle auth requirements for presets that require API keys
        if (preset.requires_key) {
            $('#source-auth-type').val('api_key');
            $('#auth-key-field').show();
            $('#source-auth-key').val('').attr('placeholder', 'Enter your API key');
            
            // Build notice message with signup link if available
            let noticeMsg = `Preset "${preset.name}" loaded. This source requires an API key.`;
            if (preset.key_signup_url) {
                noticeMsg += ` <a href="${preset.key_signup_url}" target="_blank" style="color:#0073aa;text-decoration:underline;">Get an API key →</a>`;
            }
            noticeMsg += ' Enter your key and click ADD SOURCE.';
            showNotice(noticeMsg, 'warning');
            
            // Focus on the API key field
            $('#source-auth-key').focus();
        } else {
            $('#source-auth-type').val('none');
            $('#auth-key-field').hide();
            
            // Focus on records field so user can adjust
            $('#source-records').focus().select();
            
            showNotice(`Preset "${preset.name}" loaded. Click ADD SOURCE to add.`, 'info');
        }
    }
    
    /**
     * Handle test source connection
     */
    function handleTestSource() {
        const $row = $(this).closest('tr');
        const sourceId = $row.data('source-id');
        const $btn = $(this);
        
        $btn.addClass('rawwire-loading');
        
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_test_scraper_source',
                nonce: config.nonce,
                source_id: sourceId
            },
            success: function(response) {
                if (response.success) {
                    $row.addClass('rawwire-success-flash');
                    showNotice('Connection successful!', 'success');
                } else {
                    $row.addClass('rawwire-error-flash');
                    showNotice(response.data.message || 'Connection failed', 'error');
                }
            },
            error: function() {
                $row.addClass('rawwire-error-flash');
                showNotice('Test failed. Server error.', 'error');
            },
            complete: function() {
                $btn.removeClass('rawwire-loading');
                setTimeout(() => {
                    $row.removeClass('rawwire-success-flash rawwire-error-flash');
                }, 500);
            }
        });
    }
    
    /**
     * Handle edit source
     */
    function handleEditSource() {
        const $row = $(this).closest('tr');
        const sourceId = $row.data('source-id');
        
        // Load source data into form
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_get_scraper_source',
                nonce: config.nonce,
                source_id: sourceId
            },
            success: function(response) {
                if (response.success && response.data) {
                    const source = response.data;
                    
                    // Populate form
                    $('#source-name').val(source.name);
                    $('#source-type').val(source.type);
                    $('#source-address').val(source.address || source.url);
                    $('#source-auth-type').val(source.auth_type || 'none').trigger('change');
                    $('#source-auth-key').val(source.auth_key || '');
                    $('#source-records').val(source.records_limit || 10);
                    $('#source-copyright').val(source.copyright || 'public_domain');
                    $('#source-table').val(source.output_table || '');
                    $('#source-columns').val(source.columns || '');
                    
                    // Scroll to form
                    $('html, body').animate({
                        scrollTop: $('#source-form').offset().top - 50
                    }, 300);
                    
                    // Change ADD button to UPDATE
                    $('#add-source-btn')
                        .text('UPDATE SOURCE')
                        .data('edit-id', sourceId);
                }
            }
        });
    }
    
    /**
     * Handle delete source
     */
    function handleDeleteSource() {
        const $row = $(this).closest('tr');
        const sourceId = $row.data('source-id');
        const sourceName = $row.find('.col-name strong').text();
        
        if (!confirm(`Delete source "${sourceName}"?`)) {
            return;
        }
        
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_delete_scraper_source',
                nonce: config.nonce,
                source_id: sourceId
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        updateSourceCount(-1);
                        
                        // Show empty state if no sources
                        if ($('#sources-tbody tr').length === 0) {
                            $('#sources-tbody').html(`
                                <tr class="rawwire-empty-row" id="empty-row">
                                    <td colspan="10">
                                        <div class="rawwire-empty-state">
                                            <span class="dashicons dashicons-database-add"></span>
                                            <p>No sources configured yet. Add a preset or custom source above.</p>
                                        </div>
                                    </td>
                                </tr>
                            `);
                        }
                    });
                    showNotice('Source deleted', 'success');
                } else {
                    showNotice(response.data.message || 'Error deleting source', 'error');
                }
            }
        });
    }
    
    /**
     * Handle source toggle
     */
    function handleSourceToggle() {
        const $row = $(this).closest('tr');
        const sourceId = $row.data('source-id');
        const enabled = $(this).is(':checked');
        
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_toggle_scraper_source',
                nonce: config.nonce,
                source_id: sourceId,
                enabled: enabled
            }
        });
    }
    
    /**
     * Bulk actions
     */
    function handleTestAll() {
        $('.test-source').each(function(i) {
            setTimeout(() => $(this).click(), i * 500);
        });
    }
    
    function handleEnableAll() {
        $('#sources-tbody .source-toggle').prop('checked', true).trigger('change');
        showNotice('All sources enabled', 'success');
    }
    
    function handleDisableAll() {
        $('#sources-tbody .source-toggle').prop('checked', false).trigger('change');
        showNotice('All sources disabled', 'info');
    }
    
    function handleClearAll() {
        if (!confirm('Delete ALL configured sources? This cannot be undone.')) {
            return;
        }
        
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_clear_scraper_sources',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#sources-tbody').html(`
                        <tr class="rawwire-empty-row" id="empty-row">
                            <td colspan="10">
                                <div class="rawwire-empty-state">
                                    <span class="dashicons dashicons-database-add"></span>
                                    <p>No sources configured yet. Add a preset or custom source above.</p>
                                </div>
                            </td>
                        </tr>
                    `);
                    $('#source-count').text('0');
                    showNotice('All sources cleared', 'success');
                }
            }
        });
    }
    
    /**
     * Save settings
     */
    function handleSaveSettings() {
        const $btn = $(this);
        $btn.addClass('rawwire-loading').prop('disabled', true);
        
        const settings = {
            default_records_per_source: parseInt($('#default-records').val()) || 10,
            copyright_filter: $('#copyright-filter').val(),
            user_agent: $('#user-agent').val(),
            respect_robots_txt: $('#respect-robots').is(':checked'),
            auto_schedule: $('#auto-schedule').is(':checked'),
            schedule_interval: $('#schedule-interval').val(),
            store_raw_response: $('#store-raw').is(':checked')
        };
        
        saveSettings(settings, function(success) {
            $btn.removeClass('rawwire-loading').prop('disabled', false);
            if (success) {
                showNotice('Settings saved!', 'success');
            }
        });
    }
    
    /**
     * Generic save settings function
     */
    function saveSettings(settings, callback) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_save_scraper_settings',
                nonce: config.nonce,
                settings: settings
            },
            success: function(response) {
                if (callback) callback(response.success);
            },
            error: function() {
                if (callback) callback(false);
                showNotice('Error saving settings', 'error');
            }
        });
    }
    
    /**
     * Run collection now
     */
    function handleRunNow() {
        const $btn = $(this);
        $btn.addClass('rawwire-loading').prop('disabled', true).text('Running...');
        
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rawwire_run_scraper',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(`Collection complete! ${response.data.records_collected || 0} records collected.`, 'success');
                } else {
                    showNotice(response.data.message || 'Collection failed', 'error');
                }
            },
            error: function() {
                showNotice('Server error during collection', 'error');
            },
            complete: function() {
                $btn.removeClass('rawwire-loading').prop('disabled', false)
                    .html('<span class="dashicons dashicons-update"></span> Run Collection Now');
            }
        });
    }
    
    /**
     * Update source count display
     */
    function updateSourceCount(delta) {
        const $count = $('#source-count');
        const current = parseInt($count.text()) || 0;
        $count.text(Math.max(0, current + delta));
    }
    
    /**
     * Highlight empty required fields
     */
    function highlightEmptyFields() {
        const required = ['#source-name', '#source-address', '#source-table', '#source-columns'];
        required.forEach(sel => {
            const $field = $(sel);
            if (!$field.val().trim()) {
                $field.addClass('rawwire-error-flash');
                setTimeout(() => $field.removeClass('rawwire-error-flash'), 1000);
            }
        });
    }
    
    /**
     * Show notice message
     */
    function showNotice(message, type = 'info') {
        // Remove existing notices
        $('.rawwire-notice').remove();
        
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            info: '#4a9eff',
            warning: '#f59e0b'
        };
        
        const $notice = $(`
            <div class="rawwire-notice" style="
                position: fixed;
                top: 40px;
                right: 20px;
                padding: 12px 20px;
                background: ${colors[type] || colors.info};
                color: white;
                border-radius: 6px;
                font-weight: 500;
                z-index: 99999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                animation: slideIn 0.3s ease;
            ">${escapeHtml(message)}</div>
        `);
        
        $('body').append($notice);
        
        setTimeout(() => {
            $notice.fadeOut(300, function() { $(this).remove(); });
        }, 3000);
    }
    
    /**
     * Escape HTML for safe output
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // Initialize when DOM ready
    $(document).ready(init);
    
    // Add CSS for notice animation
    $('<style>@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }</style>').appendTo('head');

})(jQuery);
