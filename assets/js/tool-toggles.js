/**
 * Tool Toggle Manager - Frontend JS
 *
 * Handles AJAX toggle calls for the Tool Toggles page.
 * Each checkbox sends an AJAX request to enable/disable the tool,
 * with dependency checking and visual feedback.
 *
 * @package RawWire Dashboard
 * @since 1.0.27
 */
(function ($) {
    'use strict';

    var ToolToggles = {
        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            $(document).on('change', '.rawwire-tool-toggles input[type="checkbox"]', function () {
                ToolToggles.handleToggle($(this));
            });
        },

        handleToggle: function ($checkbox) {
            var toolId = $checkbox.data('tool-id');
            var enable = $checkbox.is(':checked');
            var $row = $checkbox.closest('tr');

            // Visual feedback — dim the row
            $row.css('opacity', '0.5');
            $checkbox.prop('disabled', true);

            $.ajax({
                url: RawWireToolToggles.ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_toggle_tool',
                    nonce: RawWireToolToggles.nonce,
                    tool_id: toolId,
                    enable: enable ? 1 : 0
                },
                success: function (response) {
                    $row.css('opacity', '1');
                    $checkbox.prop('disabled', false);

                    if (response.success) {
                        // Update row classes
                        $row.removeClass('enabled disabled').addClass(enable ? 'enabled' : 'disabled');

                        // Update status cell
                        var $statusCell = $row.find('.status-active, .status-inactive, .status-warning');
                        if ($statusCell.length) {
                            if (enable) {
                                $statusCell.removeClass('status-inactive status-warning')
                                    .addClass('status-active')
                                    .text('Active');
                            } else {
                                $statusCell.removeClass('status-active status-warning')
                                    .addClass('status-inactive')
                                    .text('Inactive');
                            }
                        }

                        ToolToggles.showToast(response.data.message, 'success');

                        // Check if any dependent tools need to be disabled
                        if (!enable) {
                            ToolToggles.checkDependents(toolId);
                        }
                    } else {
                        // Revert the checkbox
                        $checkbox.prop('checked', !enable);
                        ToolToggles.showToast(response.data.message || 'Toggle failed', 'error');
                    }
                },
                error: function () {
                    $row.css('opacity', '1');
                    $checkbox.prop('disabled', false);
                    $checkbox.prop('checked', !enable);
                    ToolToggles.showToast('Network error — toggle not saved', 'error');
                }
            });
        },

        /**
         * After disabling a tool, reload the page to refresh dependency states.
         * This ensures tools that depended on the disabled one show correct status.
         */
        checkDependents: function (toolId) {
            // Simple approach: reload after a short delay so the user sees the success toast
            setTimeout(function () {
                window.location.reload();
            }, 800);
        },

        /**
         * Show a toast notification
         */
        showToast: function (message, type) {
            // Use RawWireAdmin toast if available
            if (typeof RawWireAdmin !== 'undefined' && RawWireAdmin.showToast) {
                RawWireAdmin.showToast(message, type);
                return;
            }

            // Fallback: create inline notice
            var $wrap = $('.rawwire-wrap').first();
            if (!$wrap.length) {
                $wrap = $('.wrap').first();
            }

            var noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible" style="margin: 12px 0;">' +
                '<p>' + $('<span>').text(message).html() + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
                '</div>');

            $wrap.find('.rawwire-page-header').after($notice);

            $notice.find('.notice-dismiss').on('click', function () {
                $notice.fadeOut(200, function () { $(this).remove(); });
            });

            // Auto dismiss after 4s
            setTimeout(function () {
                $notice.fadeOut(400, function () { $(this).remove(); });
            }, 4000);
        }
    };

    $(document).ready(function () {
        ToolToggles.init();
    });

})(jQuery);
