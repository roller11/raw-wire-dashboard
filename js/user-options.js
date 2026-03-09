/**
 * User Options - Page Interactions
 *
 * Handles tab switching (reuses Soothsayer tab pattern),
 * form state management, and AJAX save.
 *
 * @package RawWire_Dashboard
 * @since 1.0.32
 */
(function ($) {
    'use strict';

    const UserOptions = {
        init() {
            this.bindTabs();
            this.bindSave();
        },

        /**
         * Tab switching — same pattern as Soothsayer
         */
        bindTabs() {
            $(document).on('click', '.soothsayer-tabs .tab-btn', function (e) {
                e.preventDefault();
                const tabId = $(this).data('tab');

                // Update active tab button
                $('.soothsayer-tabs .tab-btn').removeClass('active');
                $(this).addClass('active');

                // Show corresponding panel
                $('.tab-panel').removeClass('active');
                $('#panel-' + tabId).addClass('active');
            });
        },

        /**
         * Save user options via AJAX
         */
        bindSave() {
            $(document).on('click', '#save-user-options', function () {
                const $btn = $(this);
                const originalHtml = $btn.html();

                // Collect all option values
                const data = {
                    action: 'rawwire_save_user_options',
                    nonce: userOptionsData.nonce,
                    dashboard_density: $('#opt-dashboard-density').val(),
                    animations_enabled: $('#opt-animations').is(':checked') ? 1 : 0,
                    items_per_page: $('#opt-items-per-page').val(),
                    email_notifications: $('#opt-email-notifications').is(':checked') ? 1 : 0,
                    lead_alerts: $('#opt-lead-alerts').is(':checked') ? 1 : 0,
                    digest_frequency: $('#opt-digest-frequency').val(),
                    timezone: $('#opt-timezone').val(),
                };

                // Set loading state
                $btn.html('<span class="dashicons dashicons-update spin"></span> Saving...');
                $btn.prop('disabled', true);

                $.post(userOptionsData.ajaxUrl, data, function (response) {
                    $btn.html(originalHtml);
                    $btn.prop('disabled', false);

                    if (response.success) {
                        UserOptions.showToast('Settings saved successfully');
                    } else {
                        UserOptions.showToast('Error saving settings', true);
                    }
                }).fail(function () {
                    $btn.html(originalHtml);
                    $btn.prop('disabled', false);
                    UserOptions.showToast('Network error — please try again', true);
                });
            });
        },

        /**
         * Show toast notification
         */
        showToast(message, isError) {
            const $toast = $('#user-options-toast');
            $toast.find('.toast-message').text(message);

            if (isError) {
                $toast.css('border-color', 'var(--ss-danger)');
                $toast.find('.dashicons').removeClass('dashicons-saved').addClass('dashicons-warning');
            } else {
                $toast.css('border-color', 'var(--ss-success)');
                $toast.find('.dashicons').removeClass('dashicons-warning').addClass('dashicons-saved');
            }

            $toast.fadeIn(300);
            setTimeout(function () {
                $toast.fadeOut(400);
            }, 3000);
        }
    };

    $(document).ready(function () {
        UserOptions.init();
    });
})(jQuery);
