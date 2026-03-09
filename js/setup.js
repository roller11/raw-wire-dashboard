/**
 * Setup Page JavaScript
 * Handles form saving and connection testing
 *
 * @package RawWire_Dashboard
 * @since 1.0.30
 */

(function ($) {
    'use strict';

    /**
     * Setup Page Controller
     */
    const RawWireSetup = {

        /**
         * Initialize setup page
         */
        init: function () {
            this.bindEvents();
            this.initToggles();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // Save form
            $('#rawwire-setup-form').on('submit', this.handleSave.bind(this));

            // Test connection buttons
            $('.test-connection-btn').on('click', this.handleTestConnection.bind(this));

            // Toggle section visibility based on enable state
            $('.rawwire-toggle input').on('change', this.handleToggleChange.bind(this));
        },

        /**
         * Initialize toggle states
         */
        initToggles: function () {
            $('.rawwire-toggle input').each(function () {
                const $toggle = $(this);
                const $section = $toggle.closest('.rawwire-setup-section');
                const $body = $section.find('.rawwire-setup-body');

                if (!$toggle.is(':checked')) {
                    $body.css('opacity', '0.5');
                    $body.find('input, select, button').prop('disabled', true);
                }
            });
        },

        /**
         * Handle toggle changes
         */
        handleToggleChange: function (e) {
            const $toggle = $(e.target);
            const $section = $toggle.closest('.rawwire-setup-section');
            const $body = $section.find('.rawwire-setup-body');
            const isEnabled = $toggle.is(':checked');

            if (isEnabled) {
                $body.css('opacity', '1');
                $body.find('input, select, button').prop('disabled', false);
            } else {
                $body.css('opacity', '0.5');
                $body.find('input, select, button').prop('disabled', true);
            }
        },

        /**
         * Handle form save
         */
        handleSave: function (e) {
            e.preventDefault();

            const $form = $(e.target);
            const $button = $form.find('.button-hero');
            const originalText = $button.html();

            // Show loading state
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update spin"></span> Saving...');

            // Collect form data
            const formData = this.collectFormData($form);

            // Send AJAX request
            $.ajax({
                url: rawwireSetup.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_save_setup',
                    nonce: rawwireSetup.nonce,
                    config: JSON.stringify(formData)
                },
                success: function (response) {
                    if (response.success) {
                        RawWireSetup.showToast('Configuration saved successfully!', 'success');
                    } else {
                        RawWireSetup.showToast(response.data || 'Failed to save configuration', 'error');
                    }
                },
                error: function () {
                    RawWireSetup.showToast('Network error. Please try again.', 'error');
                },
                complete: function () {
                    $button.prop('disabled', false);
                    $button.html(originalText);
                }
            });
        },

        /**
         * Collect form data into structured object
         */
        collectFormData: function ($form) {
            const data = {
                openclaw: {
                    enabled: $form.find('[name="openclaw_enabled"]').is(':checked'),
                    endpoint: $form.find('[name="openclaw_endpoint"]').val(),
                    auth_token: $form.find('[name="openclaw_auth_token"]').val(),
                    model: $form.find('[name="openclaw_model"]').val(),
                    max_tokens: parseInt($form.find('[name="openclaw_max_tokens"]').val()) || 4096,
                    temperature: parseFloat($form.find('[name="openclaw_temperature"]').val()) || 0.7
                },
                email: {
                    enabled: $form.find('[name="email_enabled"]').is(':checked'),
                    smtp_host: $form.find('[name="smtp_host"]').val(),
                    smtp_port: parseInt($form.find('[name="smtp_port"]').val()) || 587,
                    smtp_username: $form.find('[name="smtp_username"]').val(),
                    smtp_password: $form.find('[name="smtp_password"]').val(),
                    smtp_encryption: $form.find('[name="smtp_encryption"]').val(),
                    from_email: $form.find('[name="from_email"]').val(),
                    from_name: $form.find('[name="from_name"]').val()
                },
                calendar: {
                    enabled: $form.find('[name="calendar_enabled"]').is(':checked'),
                    provider: $form.find('[name="calendar_provider"]').val(),
                    client_id: $form.find('[name="calendar_client_id"]').val(),
                    client_secret: $form.find('[name="calendar_client_secret"]').val(),
                    default_reminder: parseInt($form.find('[name="default_reminder"]').val()) || 30
                },
                pinecone: {
                    enabled: $form.find('[name="pinecone_enabled"]').is(':checked'),
                    api_key: $form.find('[name="pinecone_api_key"]').val(),
                    environment: $form.find('[name="pinecone_environment"]').val(),
                    index_name: $form.find('[name="pinecone_index_name"]').val(),
                    namespace: $form.find('[name="pinecone_namespace"]').val()
                },
                context_engine: {
                    enabled: $form.find('[name="context_engine_enabled"]').is(':checked'),
                    endpoint: $form.find('[name="context_engine_endpoint"]').val(),
                    api_key: $form.find('[name="context_engine_api_key"]').val(),
                    sync_interval: parseInt($form.find('[name="context_sync_interval"]').val()) || 300
                },
                investigation: {
                    retention_days: parseInt($form.find('[name="retention_days"]').val()) || 90,
                    auto_archive: $form.find('[name="auto_archive"]').is(':checked'),
                    webhook_url: $form.find('[name="investigation_webhook"]').val()
                }
            };

            return data;
        },

        /**
         * Handle connection test
         */
        handleTestConnection: function (e) {
            e.preventDefault();

            const $button = $(e.target);
            const service = $button.data('service');
            const $status = $button.siblings('.connection-status');

            // Show loading state
            $button.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').text('Testing...').show();

            $.ajax({
                url: rawwireSetup.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_test_connection',
                    nonce: rawwireSetup.nonce,
                    service: service,
                    config: JSON.stringify(this.getServiceConfig(service))
                },
                success: function (response) {
                    $status.removeClass('loading');
                    if (response.success) {
                        $status.addClass('success').text(response.data || 'Connection successful!');
                    } else {
                        $status.addClass('error').text(response.data || 'Connection failed');
                    }
                },
                error: function () {
                    $status.removeClass('loading').addClass('error').text('Network error');
                },
                complete: function () {
                    $button.prop('disabled', false);

                    // Hide status after 5 seconds
                    setTimeout(function () {
                        $status.fadeOut();
                    }, 5000);
                }
            });
        },

        /**
         * Get service-specific configuration for testing
         */
        getServiceConfig: function (service) {
            const $form = $('#rawwire-setup-form');

            switch (service) {
                case 'openclaw':
                    return {
                        endpoint: $form.find('[name="openclaw_endpoint"]').val(),
                        auth_token: $form.find('[name="openclaw_auth_token"]').val(),
                        model: $form.find('[name="openclaw_model"]').val()
                    };
                case 'pinecone':
                    return {
                        api_key: $form.find('[name="pinecone_api_key"]').val(),
                        environment: $form.find('[name="pinecone_environment"]').val(),
                        index_name: $form.find('[name="pinecone_index_name"]').val()
                    };
                case 'context_engine':
                    return {
                        endpoint: $form.find('[name="context_engine_endpoint"]').val(),
                        api_key: $form.find('[name="context_engine_api_key"]').val()
                    };
                case 'email':
                    return {
                        smtp_host: $form.find('[name="smtp_host"]').val(),
                        smtp_port: $form.find('[name="smtp_port"]').val(),
                        smtp_username: $form.find('[name="smtp_username"]').val(),
                        smtp_password: $form.find('[name="smtp_password"]').val(),
                        smtp_encryption: $form.find('[name="smtp_encryption"]').val()
                    };
                default:
                    return {};
            }
        },

        /**
         * Show toast notification
         */
        showToast: function (message, type) {
            // Remove existing toast
            $('.rawwire-toast').remove();

            const $toast = $('<div class="rawwire-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);

            // Trigger show animation
            setTimeout(function () {
                $toast.addClass('show');
            }, 10);

            // Auto hide after 3 seconds
            setTimeout(function () {
                $toast.removeClass('show');
                setTimeout(function () {
                    $toast.remove();
                }, 300);
            }, 3000);
        }
    };

    // Initialize when document ready
    $(document).ready(function () {
        if ($('.rawwire-setup-page').length) {
            RawWireSetup.init();
        }
    });

    // Add spin animation for loading states
    $('<style>@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .spin { animation: spin 1s linear infinite; }</style>').appendTo('head');

})(jQuery);
