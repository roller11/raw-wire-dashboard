/**
 * ARCHIVED: Activity Logs Module from dashboard.js
 * 
 * This code was extracted from dashboard.js during cleanup on 2026-01-14.
 * It was one of several duplicate implementations of the logging UI.
 * 
 * Other related files also archived:
 * - includes/class-activity-logs.php (PHP AJAX handlers)
 * - includes/class-logger.php (PHP Logger class)
 * - js/activity-logs.js (Standalone JS implementation)
 * 
 * Location in original file: Lines 608-857 (approximately)
 */

    // === ACTIVITY LOGS - THREE TAB SYSTEM ===
    const activityLogsModule = {
        currentTab: 'info',
        cache: {
            info: null,
            debug: null,
            errors: null
        },
        isLoading: false,

        init() {
            this.bindTabSwitching();
            this.bindExportButton();
            this.loadTab('info'); // Load info tab by default
        },

        bindTabSwitching() {
            $('.activity-logs-tab').on('click', (e) => {
                e.preventDefault();
                const tab = $(e.currentTarget);
                const tabType = tab.data('tab');
                
                if (tabType === this.currentTab) return; // Already on this tab
                
                // Update tab UI
                $('.activity-logs-tab').removeClass('active');
                tab.addClass('active');
                
                // Show corresponding pane
                $('.logs-pane').removeClass('active');
                $('#' + tabType + '-tab').addClass('active');
                
                // Load logs for this tab
                this.loadTab(tabType);
            });
        },

        bindExportButton() {
            $('#export-logs-btn').on('click', () => {
                this.exportLogs();
            });
        },

        loadTab(tabType) {
            if (this.isLoading) return;
            
            this.currentTab = tabType;
            const container = $('#' + tabType + '-tab .logs-container');
            
            // Check cache first
            if (this.cache[tabType]) {
                this.renderLogs(container, this.cache[tabType]);
                return;
            }
            
            // Show loading state
            this.isLoading = true;
            container.html('<div class="logs-loading"><span class="spinner is-active"></span> Loading ' + tabType + ' logs...</div>');
            
            // Fetch logs via REST API
            $.ajax({
                url: RawWireCfg.rest + '/logs',
                method: 'GET',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
                data: {
                    severity: tabType === 'errors' ? 'error' : (tabType === 'debug' ? 'debug' : 'info'),
                    limit: 100
                },
                success: (response) => {
                    this.isLoading = false;
                    
                    if (response.success && response.logs) {
                        this.cache[tabType] = response.logs;
                        this.renderLogs(container, response.logs);
                    } else {
                        container.html('<div class="logs-error"><span class="dashicons dashicons-warning"></span> Failed to load logs</div>');
                    }
                },
                error: (xhr) => {
                    this.isLoading = false;
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Network error';
                    container.html('<div class="logs-error"><span class="dashicons dashicons-warning"></span> ' + msg + '</div>');
                }
            });
        },

        renderLogs(container, logs) {
            if (!Array.isArray(logs)) {
                console.error('renderLogs: logs is not an array', logs);
                container.html('<div class="logs-error"><span class="dashicons dashicons-warning"></span> Invalid logs data format</div>');
                return;
            }
            
            if (logs.length === 0) {
                container.html('<div class="logs-empty"><span class="dashicons dashicons-info"></span> No ' + this.currentTab + ' logs found</div>');
                return;
            }
            
            let html = '<table class="logs-table widefat">';
            html += '<thead><tr>';
            html += '<th style="width: 150px;">Time</th>';
            html += '<th style="width: 100px;">Type</th>';
            html += '<th style="width: 80px;">Severity</th>';
            html += '<th>Message</th>';
            html += '<th style="width: 100px;">Actions</th>';
            html += '</tr></thead>';
            html += '<tbody>';
            
            logs.forEach((log) => {
                const severityClass = 'severity-' + sanitizeForClassName(log.severity || 'info');
                
                html += '<tr class="log-row ' + severityClass + '">';
                html += '<td><span class="log-time">' + this.formatTime(log.timestamp) + '</span></td>';
                html += '<td><span class="log-type">N/A</span></td>';
                html += '<td><span class="log-severity ' + severityClass + '">' + escapeHtml(log.severity || 'info') + '</span></td>';
                html += '<td><span class="log-message">' + escapeHtml(log.message || 'No message') + '</span></td>';
                html += '<td><button class="button button-small view-details-btn" data-details="{}" title="View Details">Details</button></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            container.html(html);
            
            // Bind details button
            container.find('.view-details-btn').on('click', (e) => {
                const btn = $(e.currentTarget);
                const details = btn.data('details');
                this.showDetailsModal(details);
            });
        },

        formatTime(timestamp) {
            if (!timestamp) return 'N/A';
            
            // Handle WordPress debug log format: [25-Dec-2024 12:34:56 UTC]
            if (typeof timestamp === 'string' && timestamp.startsWith('[') && timestamp.endsWith(']')) {
                const match = timestamp.match(/\[(\d{2})-(\w{3})-(\d{4}) (\d{2}):(\d{2}):(\d{2}) UTC\]/);
                if (match) {
                    const [, day, month, year, hour, minute, second] = match;
                    const monthMap = {
                        'Jan': 0, 'Feb': 1, 'Mar': 2, 'Apr': 3, 'May': 4, 'Jun': 5,
                        'Jul': 6, 'Aug': 7, 'Sep': 8, 'Oct': 9, 'Nov': 10, 'Dec': 11
                    };
                    const date = new Date(Date.UTC(parseInt(year), monthMap[month], parseInt(day), parseInt(hour), parseInt(minute), parseInt(second)));
                    if (!isNaN(date.getTime())) {
                        const now = new Date();
                        const diff = Math.floor((now - date) / 1000); // seconds
                        
                        if (diff < 60) return diff + 's ago';
                        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
                        
                        return date.toLocaleString();
                    }
                }
            }
            
            // Fallback for other formats
            const date = new Date(timestamp);
            if (isNaN(date.getTime())) return timestamp; // Return as-is if can't parse
            
            const now = new Date();
            const diff = Math.floor((now - date) / 1000); // seconds
            
            if (diff < 60) return diff + 's ago';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            
            return date.toLocaleString();
        },

        showDetailsModal(details) {
            // Create modal overlay
            const modal = $('<div class="logs-modal-overlay"></div>');
            const modalContent = $('<div class="logs-modal-content"></div>');
            
            modalContent.html(
                '<div class="logs-modal-header">' +
                '<h3>Log Details</h3>' +
                '<button class="logs-modal-close" title="Close">&times;</button>' +
                '</div>' +
                '<div class="logs-modal-body">' +
                '<pre>' + details + '</pre>' +
                '</div>' +
                '<div class="logs-modal-footer">' +
                '<button class="button logs-modal-close">Close</button>' +
                '</div>'
            );
            
            modal.append(modalContent);
            $('body').append(modal);
            
            // Bind close handlers
            modal.find('.logs-modal-close').on('click', () => {
                modal.fadeOut(200, () => modal.remove());
            });
            
            modal.on('click', (e) => {
                if ($(e.target).hasClass('logs-modal-overlay')) {
                    modal.fadeOut(200, () => modal.remove());
                }
            });
            
            // ESC key to close
            $(document).on('keydown.logsModal', (e) => {
                if (e.key === 'Escape') {
                    modal.fadeOut(200, () => modal.remove());
                    $(document).off('keydown.logsModal');
                }
            });
            
            modal.fadeIn(200);
        },

        exportLogs() {
            const logs = this.cache[this.currentTab];
            if (!logs || logs.length === 0) {
                toast('No logs to export', 'info');
                return;
            }
            
            // Convert to CSV
            let csv = 'Time,Type,Severity,Message,Details\n';
            logs.forEach((log) => {
                const time = log.timestamp || '';
                const type = 'N/A';
                const severity = log.severity || 'info';
                const message = (log.message || '').replace(/"/g, '""');
                const details = '{}';
                
                csv += '"' + time + '","' + type + '","' + severity + '","' + message + '","' + details + '"\n';
            });
            
            // Download file
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'rawwire-logs-' + this.currentTab + '-' + Date.now() + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            toast('Logs exported successfully', 'success');
        }
    };

    // Initialize activity logs module (from end of file)
    if ($('.activity-logs-tabs').length > 0) {
        activityLogsModule.init();
    }

    // Fallback handlers for UI actions not yet implemented server-side
    $('#refresh-logs').on('click', function(e) {
        e.preventDefault();
        toast('Refreshing logs is not yet supported in this build.', 'info');
    });

    $('#export-logs').on('click', function(e) {
        e.preventDefault();
        toast('Exporting logs is not yet supported in this build.', 'info');
    });

    $('#clear-logs').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        if (!RawWireCfg || !RawWireCfg.userCaps || !RawWireCfg.userCaps.manage_options) {
            toast('Insufficient permissions to clear logs.', 'error');
            return;
        }
        // Confirm and fallback
        if (!confirm('Clear all logs? This action is not yet implemented.')) {
            return;
        }
        toast('Clearing logs is not yet supported in this build.', 'info');
    });
