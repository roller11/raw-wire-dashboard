/**
 * Soothsayer - Intelligence War Room JavaScript
 * Handles workflow animations, data streaming, and executive actions
 *
 * @package RawWire_Dashboard
 * @since 1.0.30
 */

(function ($) {
    'use strict';

    /**
     * Soothsayer Controller
     */
    const Soothsayer = {

        // State
        workflowRunning: false,
        currentStage: -1,
        streamEntryCount: 0,
        streamRate: 0,
        streamInterval: null,

        /**
         * Initialize Soothsayer
         */
        init: function () {
            this.bindEvents();
            this.initializeStats();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // Launch workflow button
            $('#launch-workflow-btn').on('click', this.launchWorkflow.bind(this));

            // Clear stream button
            $('#clear-stream-btn').on('click', this.clearStream.bind(this));

            // Executive action buttons
            $('.exec-action-btn').on('click', this.handleExecutiveAction.bind(this));

            // Matrix action buttons
            $(document).on('click', '.action-email', this.handleEmailAction.bind(this));
            $(document).on('click', '.action-calendar', this.handleCalendarAction.bind(this));
            $(document).on('click', '.action-expand', this.handleExpandAction.bind(this));

            // Matrix filter
            $('#matrix-filter').on('change', this.filterMatrix.bind(this));
        },

        /**
         * Initialize stats display
         */
        initializeStats: function () {
            // Animate stats on load
            $('.stat-value').each(function () {
                const $el = $(this);
                const finalValue = $el.text();
                const isNumber = /^[\d,]+$/.test(finalValue.replace(/[^0-9,]/g, ''));

                if (isNumber && !finalValue.includes('$')) {
                    const target = parseInt(finalValue.replace(/,/g, ''), 10);
                    $el.text('0');
                    Soothsayer.animateNumber($el, 0, target, 1000);
                }
            });
        },

        /**
         * Animate number counter
         */
        animateNumber: function ($el, start, end, duration) {
            const startTime = performance.now();

            function update(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);

                // Ease out
                const easeProgress = 1 - Math.pow(1 - progress, 3);
                const current = Math.floor(start + (end - start) * easeProgress);

                $el.text(current.toLocaleString());

                if (progress < 1) {
                    requestAnimationFrame(update);
                }
            }

            requestAnimationFrame(update);
        },

        /**
         * Launch intelligence workflow
         */
        launchWorkflow: function (e) {
            e.preventDefault();

            if (this.workflowRunning) {
                this.showToast('Workflow already in progress', 'warning');
                return;
            }

            const $btn = $(e.target).closest('.button-hero');
            const originalText = $btn.html();

            // Start workflow
            this.workflowRunning = true;
            this.currentStage = -1;

            // Update button
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update spin"></span> Running...');

            // Clear previous states
            $('.pipeline-stage').removeClass('active complete error');
            $('.status-text').text('Standby');
            $('#pipeline-progress').css('width', '0%');

            // Clear stream
            this.clearStream();

            // AJAX to initiate workflow
            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_launch_workflow',
                    nonce: soothsayerData.nonce
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.runWorkflowAnimation(response.data.stages);
                    } else {
                        Soothsayer.showToast('Failed to launch workflow', 'error');
                        Soothsayer.resetWorkflow($btn, originalText);
                    }
                },
                error: function () {
                    Soothsayer.showToast('Network error', 'error');
                    Soothsayer.resetWorkflow($btn, originalText);
                }
            });
        },

        /**
         * Run workflow animation sequence
         */
        runWorkflowAnimation: function (stages) {
            const totalStages = stages.length;
            let currentIndex = 0;

            // Start streaming data
            this.startDataStream();

            // Progress through each stage
            const advanceStage = function () {
                if (currentIndex >= totalStages) {
                    // Workflow complete
                    Soothsayer.completeWorkflow();
                    return;
                }

                // Mark previous stage as complete
                if (currentIndex > 0) {
                    const prevStage = stages[currentIndex - 1];
                    const $prevStage = $('[data-stage="' + prevStage + '"]');
                    $prevStage.removeClass('active').addClass('complete');
                    $prevStage.find('.status-text').text('Done');
                }

                // Activate current stage
                const stageId = stages[currentIndex];
                const $stage = $('[data-stage="' + stageId + '"]');
                $stage.addClass('active');
                $stage.find('.status-text').text('Active');

                // Update progress bar
                const progress = ((currentIndex + 1) / totalStages) * 100;
                $('#pipeline-progress').css('width', progress + '%');

                // Add stream entry for this stage
                Soothsayer.addStreamEntry(stageId, 'Processing ' + stageId.toUpperCase() + ' stage...');

                currentIndex++;
                Soothsayer.currentStage = currentIndex;

                // Schedule next stage (2-4 seconds per stage for effect)
                const delay = 2000 + Math.random() * 2000;
                setTimeout(advanceStage, delay);
            };

            // Start the sequence
            setTimeout(advanceStage, 500);
        },

        /**
         * Complete workflow
         */
        completeWorkflow: function () {
            this.workflowRunning = false;
            this.stopDataStream();

            // Mark last stage complete
            $('.pipeline-stage').last().removeClass('active').addClass('complete');
            $('.pipeline-stage').last().find('.status-text').text('Done');

            // Reset button
            const $btn = $('#launch-workflow-btn');
            $btn.prop('disabled', false);
            $btn.html('<span class="dashicons dashicons-superhero-alt"></span> Launch Intelligence Sweep');

            // Success message
            this.showToast('Intelligence sweep complete!', 'success');

            // Final stream entry
            this.addStreamEntry('complete', 'All systems nominal. Intelligence ready for review.');
        },

        /**
         * Reset workflow state
         */
        resetWorkflow: function ($btn, originalText) {
            this.workflowRunning = false;
            this.stopDataStream();
            $btn.prop('disabled', false);
            $btn.html(originalText);
        },

        /**
         * Start data streaming simulation
         */
        startDataStream: function () {
            const streamMessages = [
                { type: 'scan', msg: 'New permit detected in SF Bay Area' },
                { type: 'scan', msg: 'Project announcement captured from news feed' },
                { type: 'harvest', msg: 'Extracting company profile data...' },
                { type: 'harvest', msg: 'Contact information gathered' },
                { type: 'process', msg: 'AI pattern matching in progress' },
                { type: 'process', msg: 'Industry correlation identified' },
                { type: 'classify', msg: 'Lead scored: HIGH priority' },
                { type: 'classify', msg: 'Target added to decision matrix' }
            ];

            let msgIndex = 0;

            this.streamInterval = setInterval(function () {
                if (!Soothsayer.workflowRunning) return;

                const entry = streamMessages[msgIndex % streamMessages.length];
                Soothsayer.addStreamEntry(entry.type, entry.msg);

                msgIndex++;
                Soothsayer.streamRate = Math.floor(60 / ((Date.now() / 1000) / msgIndex));
                $('#stream-rate').text(Soothsayer.streamRate);
            }, 1500);
        },

        /**
         * Stop data streaming
         */
        stopDataStream: function () {
            if (this.streamInterval) {
                clearInterval(this.streamInterval);
                this.streamInterval = null;
            }
        },

        /**
         * Add entry to stream
         */
        addStreamEntry: function (type, message) {
            const $stream = $('#intel-stream .stream-content');

            // Remove placeholder if present
            $stream.find('.stream-placeholder').remove();

            // Icon map
            const icons = {
                'scan': 'search',
                'harvest': 'download',
                'process': 'admin-generic',
                'classify': 'chart-bar',
                'complete': 'yes-alt'
            };

            const icon = icons[type] || 'marker';
            const time = new Date().toLocaleTimeString('en-US', { hour12: false });

            const entryHtml = `
                <div class="stream-entry type-${type}">
                    <div class="stream-entry-icon">
                        <span class="dashicons dashicons-${icon}"></span>
                    </div>
                    <div class="stream-entry-content">
                        <p class="stream-entry-message">${message}</p>
                        <span class="stream-entry-time">${time}</span>
                    </div>
                </div>
            `;

            $stream.prepend(entryHtml);

            this.streamEntryCount++;
            $('#stream-entries').text(this.streamEntryCount);

            // Limit entries to prevent memory issues
            if ($stream.find('.stream-entry').length > 50) {
                $stream.find('.stream-entry').last().remove();
            }
        },

        /**
         * Clear stream
         */
        clearStream: function () {
            const $content = $('#intel-stream .stream-content');
            $content.html(`
                <div class="stream-placeholder">
                    <span class="dashicons dashicons-visibility"></span>
                    <p>Intelligence systems on standby</p>
                    <small>Launch workflow to begin data collection</small>
                </div>
            `);
            this.streamEntryCount = 0;
            this.streamRate = 0;
            $('#stream-entries').text('0');
            $('#stream-rate').text('0');
        },

        /**
         * Handle executive action button
         */
        handleExecutiveAction: function (e) {
            const $btn = $(e.target).closest('.exec-action-btn');
            const action = $btn.data('action');

            // Visual feedback
            $btn.addClass('loading');

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_executive_action',
                    nonce: soothsayerData.nonce,
                    exec_action: action
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.showToast(response.data.message, 'success');
                        Soothsayer.addStreamEntry('process', response.data.message);
                    } else {
                        Soothsayer.showToast('Action failed', 'error');
                    }
                },
                error: function () {
                    Soothsayer.showToast('Network error', 'error');
                },
                complete: function () {
                    $btn.removeClass('loading');
                }
            });
        },

        /**
         * Handle email action
         */
        handleEmailAction: function (e) {
            const $row = $(e.target).closest('.matrix-row');
            const company = $row.find('.matrix-target strong').text();
            this.showToast('Preparing outreach email to ' + company, 'success');
        },

        /**
         * Handle calendar action
         */
        handleCalendarAction: function (e) {
            const $row = $(e.target).closest('.matrix-row');
            const company = $row.find('.matrix-target strong').text();
            this.showToast('Opening calendar for ' + company, 'success');
        },

        /**
         * Handle expand/view action
         */
        handleExpandAction: function (e) {
            const $row = $(e.target).closest('.matrix-row');
            const id = $row.data('id');
            // Could open modal or navigate to detail page
            this.showToast('Loading details for target #' + id, 'success');
        },

        /**
         * Filter matrix table
         */
        filterMatrix: function (e) {
            const filter = $(e.target).val();
            const $rows = $('#matrix-tbody .matrix-row');

            if (filter === 'all') {
                $rows.show();
                return;
            }

            $rows.each(function () {
                const score = parseInt($(this).find('.score-bar span').text(), 10);
                let show = false;

                switch (filter) {
                    case 'hot':
                        show = score >= 80;
                        break;
                    case 'new':
                        show = score >= 60 && score < 80;
                        break;
                    case 'follow':
                        show = score < 60;
                        break;
                }

                $(this).toggle(show);
            });
        },

        /**
         * Show toast notification
         */
        showToast: function (message, type) {
            // Remove existing toast
            $('.soothsayer-toast').remove();

            const typeColors = {
                'success': '#10b981',
                'error': '#ef4444',
                'warning': '#f59e0b'
            };

            const $toast = $(`
                <div class="soothsayer-toast ${type}">
                    <span>${message}</span>
                </div>
            `);

            $('body').append($toast);

            // Show
            setTimeout(function () {
                $toast.addClass('show');
            }, 10);

            // Hide after 3s
            setTimeout(function () {
                $toast.removeClass('show');
                setTimeout(function () {
                    $toast.remove();
                }, 300);
            }, 3000);
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        if ($('.rawwire-soothsayer').length) {
            Soothsayer.init();
        }
    });

    // Add toast styles dynamically
    $('<style>')
        .html(`
            .soothsayer-toast {
                position: fixed;
                bottom: 30px;
                right: 30px;
                padding: 14px 24px;
                border-radius: 10px;
                color: white;
                font-weight: 500;
                box-shadow: 0 10px 40px rgba(0,0,0,0.4);
                transform: translateY(100px);
                opacity: 0;
                transition: all 0.3s ease;
                z-index: 9999;
            }
            .soothsayer-toast.show {
                transform: translateY(0);
                opacity: 1;
            }
            .soothsayer-toast.success { background: linear-gradient(135deg, #10b981, #059669); }
            .soothsayer-toast.error { background: linear-gradient(135deg, #ef4444, #dc2626); }
            .soothsayer-toast.warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
            .exec-action-btn.loading {
                opacity: 0.6;
                pointer-events: none;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .spin { animation: spin 1s linear infinite; }
        `)
        .appendTo('head');

})(jQuery);
