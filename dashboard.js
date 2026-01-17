jQuery(document).ready(function($) {
    console.log('Raw-Wire dashboard.js loaded');
    console.log('RawWireCfg:', typeof RawWireCfg !== 'undefined' ? RawWireCfg : 'NOT AVAILABLE');
    
    // Test if jQuery click works
    console.log('Testing jQuery click binding...');
    console.log('Button element:', document.getElementById('rawwire-sync-btn'));
    console.log('Button jQuery object:', $('#rawwire-sync-btn'));
    
    // === CLEAR WORKFLOW TABLES HANDLER ===
    // MOVED TO template-system.js - RawWireAdmin.Controls.clearWorkflowTables()
    // The handler in template-system.js uses stopImmediatePropagation() to prevent duplicates
    
    // === START WORKFLOW HANDLER ===
    // MOVED TO template-system.js - RawWireAdmin.Controls.startWorkflow()
    // The handler in template-system.js uses stopImmediatePropagation() to prevent duplicates
    
    // === LEGACY SYNC HANDLER - DEPRECATED ===
    // This handler is kept for backwards compatibility only.
    // The "Sync All Sources" button has been removed from the template.
    // All sync functionality now goes through workflow/start endpoint.
    $(document).on('click', '#rawwire-sync-btn, #fetch-data-btn', function(e) {
        console.warn('[RawWire] DEPRECATED: Legacy sync button clicked. Redirecting to workflow/start...');
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Starting workflow...');
        
        // Redirect to new workflow endpoint instead of deprecated /fetch-data
        $.ajax({
            url: RawWireCfg.rest + '/workflow/start',
            method: 'POST',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            data: JSON.stringify({
                scraper: 'native',
                max_records: 10,
                target_table: 'candidates'
            }),
            contentType: 'application/json',
            success: (response) => {
                console.log('Workflow response:', response);
                if (response && response.success) {
                    showToast('Scraped ' + (response.items_scraped || 0) + ' items, stored ' + (response.items_stored || 0), 'success');
                } else {
                    showToast(response.error || 'Workflow failed', 'error');
                }
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Sources');
                setTimeout(() => location.reload(), 1200);
            },
            error: (xhr) => {
                const msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to start workflow';
                showToast(msg, 'error');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Sources');
            }
        });
    });
    
    const cards = $('.finding-card');
    const drawer = $('#finding-drawer');
    const status = $('#control-status');
    let currentDrawerId = null;
    let lastFocusedElement = null;

    // OPTIMIZATION 1: Debounce helper for search/filter
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // SECURITY: HTML escaping utility to prevent XSS
    function escapeHtml(text) {
        if (!text && text !== 0) return ''; // Handle null/undefined, but allow 0
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // SECURITY: Escape values for use in HTML attributes
    function escapeAttribute(text) {
        if (!text && text !== 0) return ''; // Handle null/undefined, but allow 0
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/'/g, '&#39;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // SECURITY: Sanitize values for use in CSS class names
    function sanitizeForClassName(text) {
        // Only allow alphanumeric, hyphens, and underscores for CSS class names
        return (text || '').replace(/[^a-zA-Z0-9_-]/g, '');
    }

    // OPTIMIZATION 2: Lazy load images/content
    const lazyLoadObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const lazyElement = entry.target;
                if (lazyElement.dataset.src) {
                    lazyElement.src = lazyElement.dataset.src;
                    lazyElement.classList.remove('lazy');
                    lazyLoadObserver.unobserve(lazyElement);
                }
            }
        });
    });

    document.querySelectorAll('.lazy').forEach(el => lazyLoadObserver.observe(el));

    // OPTIMIZATION 3: Component caching
    const componentCache = new Map();
    function getCachedComponent(key, generator) {
        if (componentCache.has(key)) {
            return componentCache.get(key);
        }
        const component = generator();
        componentCache.set(key, component);
        return component;
    }

    // OPTIMIZATION 4: Progressive enhancement - core works without JS
    // (Already implemented via server-side rendering)

    // OPTIMIZATION 5: Optimistic UI updates
    function optimisticUpdate(element, action, rollback) {
        const originalState = element.clone();
        action();
        return {
            success: () => {},
            failure: () => {
                element.replaceWith(originalState);
                if (rollback) rollback();
            }
        };
    }

    // === CAPABILITY-AWARE UI ===
    // Hide/disable buttons that require capabilities the user doesn't have
    const initCapabilityAwareUI = () => {
        if (!RawWireCfg || !RawWireCfg.userCaps) return;
        
        $('[data-requires-cap]').each(function() {
            const btn = $(this);
            const requiredCap = btn.data('requires-cap');
            
            if (!RawWireCfg.userCaps[requiredCap]) {
                // User lacks capability - disable button and add tooltip
                btn.prop('disabled', true)
                   .attr('title', 'Requires administrator privileges')
                   .css('opacity', '0.5')
                   .css('cursor', 'not-allowed');
            }
        });
    };

    // === DRAWER ACCESSIBILITY ===
    // Trap focus inside drawer when open
    const focusableSelectors = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
    
    const trapFocus = (e) => {
        if (!drawer.hasClass('open')) return;
        
        const focusableElements = drawer.find(focusableSelectors).filter(':visible');
        const firstFocusable = focusableElements.first();
        const lastFocusable = focusableElements.last();
        
        if (e.key === 'Tab') {
            if (e.shiftKey && document.activeElement === firstFocusable[0]) {
                e.preventDefault();
                lastFocusable.focus();
            } else if (!e.shiftKey && document.activeElement === lastFocusable[0]) {
                e.preventDefault();
                firstFocusable.focus();
            }
        }
    };
    
    // Close drawer on ESC key
    const handleEscape = (e) => {
        if (e.key === 'Escape' && drawer.hasClass('open')) {
            closeDrawer();
        }
    };
    
    $(document).on('keydown', trapFocus);
    $(document).on('keydown', handleEscape);

    // === ERROR/LOADING STATES ===
    
    // Toast notification system
    const showToast = (message, type = 'info') => {
        const toastClass = type === 'error' ? 'notice-error' : 
                          type === 'success' ? 'notice-success' : 
                          'notice-info';
        const icon = type === 'error' ? 'warning' : 
                    type === 'success' ? 'yes' : 
                    'info';
        
        const toast = $('<div class="notice ' + toastClass + ' is-dismissible" style="position: fixed; top: 32px; right: 20px; z-index: 999999; max-width: 400px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">' +
            '<p><span class="dashicons dashicons-' + icon + '"></span> ' + escapeHtml(message) + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
            '</div>');
        
        $('body').append(toast);
        
        // Auto dismiss after 5 seconds
        setTimeout(() => toast.fadeOut(300, () => toast.remove()), 5000);
        
        // Manual dismiss
        toast.find('.notice-dismiss').on('click', function() {
            toast.fadeOut(300, () => toast.remove());
        });
    };
    
    // Expose a subset of helpers now; others are set after their declarations
    try {
        window.showToast = showToast;
        window.escapeHtml = escapeHtml;
    } catch (e) {
        console.debug('Unable to set global helpers (partial)', e);
    }
    const showError = (message, retry = false) => {
        const retryHtml = retry ? 
            '<button class="button" onclick="location.reload();" style="margin-left: 8px;">Retry</button>' : '';
        status.html(
            '<div class="notice notice-error" role="alert">' +
            '<p><span class="dashicons dashicons-warning" style="color: #dc3232;"></span> ' + 
            message + retryHtml + '</p></div>'
        );
    };

    const showLoading = (message) => {
        status.html(
            '<div class="notice notice-info" role="status">' +
            '<p><span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>' + 
            message + '</p></div>'
        );
    };

    // Expose remaining helpers that were declared later
    try {
        window.showError = showError;
        window.showLoading = showLoading;
    } catch (e) {
        console.debug('Unable to set remaining global helpers', e);
    }

    // === PROGRESS BAR PLACEHOLDER ===
    // Progress bar functionality archived - will be rebuilt from scratch
    // See: _archive/obsolete-progress-bar/
    const ensureProgressCounter = () => {}; // stub
    const pollFetchProgress = () => {}; // stub
    const stopFetchProgressPoll = () => {}; // stub
    const removeProgressCounter = () => {}; // stub

    // Clear cache
    $('#clear-cache-btn').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true);
        showLoading('Clearing cache...');
        $.ajax({
            url: RawWireCfg.rest + '/clear-cache',
            method: 'POST',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            success: () => {
                status.html('<div class="notice notice-success" role="status"><p>✓ Cache cleared</p></div>');
                btn.prop('disabled', false);
            },
            error: (xhr) => {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to clear cache';
                showError(msg, false);
                btn.prop('disabled', false);
            }
        });
    });

    // Filters
    const applyFilters = () => {
        const source = $('#filter-source').val();
        const category = $('#filter-category').val();
        const statusVal = $('#filter-status').val();
        const minScore = parseFloat($('#filter-score').val() || '0');
        const quick = $('.chip.active').data('filter');

        cards.each(function() {
            const card = $(this);
            const cSource = card.data('source');
            const cCategory = card.data('category');
            const cStatus = card.data('status');
            const cScore = parseFloat(card.data('score')) || 0;
            const freshness = parseFloat(card.data('freshness')) || 0;

            let visible = true;
            if (source && cSource !== source) visible = false;
            if (category && cCategory !== category) visible = false;
            if (statusVal && cStatus !== statusVal) visible = false;
            if (cScore < minScore) visible = false;

            if (quick === 'fresh' && freshness > 86400) visible = false;
            if (quick === 'pending' && cStatus !== 'pending') visible = false;
            if (quick === 'approved' && cStatus !== 'approved') visible = false;
            if (quick === 'highscore' && cScore < 80) visible = false;

            card.toggle(visible);
        });
    };

    $('#filter-source, #filter-category, #filter-status').on('change', applyFilters);
    $('#filter-score').on('input change', function() {
        $('#score-value').text($(this).val());
        applyFilters();
    });
    $('.chip').on('click', function() {
        const chip = $(this);
        if (chip.hasClass('active')) {
            chip.removeClass('active');
        } else {
            $('.chip').removeClass('active');
            chip.addClass('active');
        }
        applyFilters();
    });

    // Drawer
    const openDrawer = (card) => {
        // Store last focused element for return focus
        lastFocusedElement = document.activeElement;
        
        currentDrawerId = parseInt(card.data('id'), 10) || null;
        $('#drawer-title').text(card.data('title') || 'Finding');
        $('#drawer-summary').text(card.data('summary') || 'No summary available yet.');
        const meta = [
            'Source: ' + (card.data('source') || 'N/A'),
            'Category: ' + (card.data('category') || 'N/A'),
            'Score: ' + (card.data('score') || 0),
            'Confidence: ' + (card.data('confidence') || 0) + '%',
            'Status: ' + (card.data('status') || 'pending'),
            'Freshness: ' + card.data('freshness') + 's'
        ];
        $('#drawer-meta').html(meta.map((m) => '<div role="listitem">' + escapeHtml(m) + '</div>').join(''));

        const tags = (card.data('tags') || '').toString().split(',').filter(Boolean);
        $('#drawer-tags').html(tags.map((t) => '<span class="tag">' + escapeHtml(t) + '</span>').join(''));

        const link = card.data('link');
        if (link) {
            $('#drawer-link').attr('href', link).show();
        } else {
            $('#drawer-link').hide();
        }

        drawer.addClass('open').attr('aria-hidden', 'false');
        
        // Focus first focusable element (close button)
        setTimeout(() => {
            drawer.find(focusableSelectors).filter(':visible').first().focus();
        }, 100);
    };
    
    const closeDrawer = () => {
        drawer.removeClass('open').attr('aria-hidden', 'true');
        
        // Return focus to trigger element
        if (lastFocusedElement) {
            lastFocusedElement.focus();
            lastFocusedElement = null;
        }
    };

    cards.on('click', function(e) {
        if ($(e.target).closest('button').length) return; // allow button handlers to run
        openDrawer($(this));
    });
    $('#drawer-close').on('click', closeDrawer);

    // Approve/Snooze placeholders (UI feedback; hook actual endpoints later)
    const toast = (msg, tone) => {
        const toneClass = tone === 'success' ? 'notice-success' : 
                         tone === 'error' ? 'notice-error' : 'notice-info';
        const icon = tone === 'success' ? 'yes-alt' : 
                    tone === 'error' ? 'warning' : 'info';
        status.html(
            '<div class="notice ' + toneClass + '" role="status">' +
            '<p><span class="dashicons dashicons-' + icon + '"></span> ' + msg + '</p></div>'
        );
        setTimeout(() => status.empty(), 2500);
    };

    $(document).on('click', '.approve-btn', function(e) {
        e.stopPropagation();
        const btn = $(this);
        const id = parseInt(btn.attr('data-id'), 10);
        if (!id || !RawWireCfg.rest) return;

        btn.prop('disabled', true);
        $.ajax({
            url: RawWireCfg.rest + '/content/approve',
            method: 'POST',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            data: JSON.stringify({ content_ids: [id] }),
            contentType: 'application/json',
            success: () => {
                toast('Approved', 'success');
                setTimeout(() => location.reload(), 500);
            },
            error: (xhr) => {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Approve failed';
                toast(msg, 'error');
                btn.prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.snooze-btn', function(e) {
        e.stopPropagation();
        const btn = $(this);
        const id = parseInt(btn.attr('data-id'), 10);
        if (!id || !RawWireCfg.rest) return;

        btn.prop('disabled', true);
        $.ajax({
            url: RawWireCfg.rest + '/content/snooze',
            method: 'POST',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            data: JSON.stringify({ content_ids: [id], minutes: 60 }),
            contentType: 'application/json',
            success: () => {
                toast('Snoozed for 60 minutes', 'info');
                setTimeout(() => location.reload(), 500);
            },
            error: (xhr) => {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Snooze failed';
                toast(msg, 'error');
                btn.prop('disabled', false);
            }
        });
    });

    $('#drawer-approve').on('click', function(e) {
        e.preventDefault();
        if (!currentDrawerId || !RawWireCfg.rest) return;
        const btn = $(this);
        btn.prop('disabled', true);
        $.ajax({
            url: RawWireCfg.rest + '/content/approve',
            method: 'POST',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            data: JSON.stringify({ content_ids: [currentDrawerId] }),
            contentType: 'application/json',
            success: () => {
                toast('Approved', 'success');
                setTimeout(() => location.reload(), 500);
            },
            error: (xhr) => {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Approve failed';
                toast(msg, 'error');
                btn.prop('disabled', false);
            }
        });
    });

    $('#drawer-snooze').on('click', function(e) {
        e.preventDefault();
        if (!currentDrawerId || !RawWireCfg.rest) return;
        const btn = $(this);
        btn.prop('disabled', true);
        $.ajax({
            url: RawWireCfg.rest + '/content/snooze',
            method: 'POST',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            data: JSON.stringify({ content_ids: [currentDrawerId], minutes: 60 }),
            contentType: 'application/json',
            success: () => {
                toast('Snoozed for 60 minutes', 'info');
                setTimeout(() => location.reload(), 500);
            },
            error: (xhr) => {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Snooze failed';
                toast(msg, 'error');
                btn.prop('disabled', false);
            }
        });
    });

    // === ACTIVITY LOGS PLACEHOLDER ===
    // Activity logs functionality archived - will be rebuilt from scratch
    // See: _archive/obsolete-logging/

    // === SYNC STATUS - REAL-TIME UPDATES ===
    const updateSyncStatus = () => {
        // Update last sync time from server
        $.ajax({
            url: RawWireCfg.rest + '/stats',
            method: 'GET',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            success: (response) => {
                if (response && response.last_sync) {
                    $('#last-sync-value').text(formatTimeAgo(response.last_sync));
                }
                if (response && response.total_items !== undefined) {
                    $('#total-items-value').text(response.total_items);
                }
                if (response && response.approved_count !== undefined) {
                    $('#approved-count-value').text(response.approved_count);
                }
                if (response && response.pending_count !== undefined) {
                    $('#pending-count-value').text(response.pending_count);
                }
            },
            error: () => {
                // Silently fail - don't disrupt user experience
            }
        });
    };

    const formatTimeAgo = (timestamp) => {
        if (!timestamp) return 'Never';
        const date = new Date(timestamp * 1000); // Assuming Unix timestamp
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
        if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
        
        return date.toLocaleDateString();
    };

    // Initialize
    initCapabilityAwareUI();
    $('#score-value').text($('#filter-score').val());
    applyFilters();
    
    // Initialize sync status updates
    if ($('.sync-status-panel').length > 0) {
        updateSyncStatus();
        // Update every 30 seconds
        setInterval(updateSyncStatus, 30000);
    }
    
    // === SLIDER VALUE DISPLAY ===
    $(document).on('input', '.rawwire-slider', function() {
        const slider = $(this);
        const valueDisplay = slider.closest('.rawwire-slider-group').find('.rawwire-slider-value');
        if (valueDisplay.length) {
            valueDisplay.text(slider.val());
        }
    });
    
    // === CONDITIONAL FIELD VISIBILITY ===
    function updateConditionalVisibility() {
        $('[data-show-if-field]').each(function() {
            const $target = $(this);
            const fieldName = $target.data('show-if-field');
            const expectedValue = $target.data('show-if-value');
            
            // Find the controlling field
            const $field = $('[name="' + fieldName + '"], [data-binding="' + fieldName + '"]');
            
            if ($field.length) {
                const currentValue = $field.val();
                if (currentValue === expectedValue) {
                    $target.show();
                } else {
                    $target.hide();
                }
            }
        });
    }
    
    // Update on select/input change for conditional fields
    $(document).on('change', '[data-binding^="workflow:"]', function() {
        updateConditionalVisibility();
    });
    
    // Initial update for conditional fields
    updateConditionalVisibility();
    
    // === ACTIVITY LOG PANEL ===
    // Refresh log panel
    $(document).on('click', '.rawwire-log-refresh', function() {
        const panel = $(this).closest('.rawwire-log-panel');
        const panelId = panel.data('panel-id');
        refreshLogPanel(panel);
    });
    
    // Filter log entries
    $(document).on('change', '.rawwire-log-filter', function() {
        const filter = $(this).val();
        const entries = $(this).closest('.rawwire-log-panel').find('.rawwire-log-entry');
        
        entries.each(function() {
            const entry = $(this);
            if (filter === 'all') {
                entry.show();
            } else {
                entry.toggle(entry.hasClass('rawwire-log-' + filter));
            }
        });
    });
    
    function refreshLogPanel(panel) {
        const entriesContainer = panel.find('.rawwire-log-entries');
        entriesContainer.html('<div class="rawwire-log-loading" style="text-align:center;padding:20px;"><span class="dashicons dashicons-update spin"></span> Loading logs...</div>');
        
        $.ajax({
            url: RawWireCfg.rest + '/logs',
            method: 'GET',
            data: { limit: 30 },
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
            success: function(response) {
                if (response && response.logs) {
                    let html = '';
                    let counts = { info: 0, warning: 0, error: 0, debug: 0 };
                    
                    if (response.logs.length === 0) {
                        html = '<div class="rawwire-log-empty"><span class="dashicons dashicons-info-outline"></span><p>No log entries found. Run a workflow to see activity here.</p></div>';
                    } else {
                        response.logs.forEach(function(log) {
                            counts[log.severity] = (counts[log.severity] || 0) + 1;
                            const icon = log.severity === 'error' ? 'no' : 
                                        (log.severity === 'warning' ? 'warning' : 
                                        (log.severity === 'debug' ? 'admin-tools' : 'info'));
                            html += '<div class="rawwire-log-entry rawwire-log-' + escapeHtml(log.severity) + '">';
                            html += '<span class="rawwire-log-severity"><span class="dashicons dashicons-' + icon + '"></span></span>';
                            if (log.timestamp) {
                                html += '<span class="rawwire-log-time">' + escapeHtml(log.timestamp.substring(0, 19)) + '</span>';
                            }
                            html += '<span class="rawwire-log-message">' + escapeHtml(log.message.substring(0, 200)) + '</span>';
                            html += '</div>';
                        });
                    }
                    
                    entriesContainer.html(html);
                    
                    // Update counts
                    panel.find('.rawwire-log-stat.info .count').text(counts.info);
                    panel.find('.rawwire-log-stat.warning .count').text(counts.warning);
                    panel.find('.rawwire-log-stat.error .count').text(counts.error);
                }
            },
            error: function(xhr) {
                entriesContainer.html('<div class="rawwire-log-empty"><span class="dashicons dashicons-warning"></span><p>Failed to load logs</p></div>');
            }
        });
    }
    
    // Auto-refresh log panels
    function initLogPanelAutoRefresh() {
        $('.rawwire-log-panel').each(function() {
            const panel = $(this);
            const refreshInterval = parseInt(panel.data('refresh')) || 30;
            
            if (refreshInterval > 0) {
                setInterval(function() {
                    // Only refresh if the panel is visible
                    if (panel.is(':visible')) {
                        refreshLogPanel(panel);
                    }
                }, refreshInterval * 1000);
            }
        });
    }
    
    // Initialize log panel auto-refresh
    initLogPanelAutoRefresh();
});