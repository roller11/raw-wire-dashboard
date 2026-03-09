/**
 * Raw Wire Dashboard Chat Panel JavaScript
 * 
 * Handles chat panel interactions with Venice.ai (privacy-first LLM).
 *
 * @package RawWire\Dashboard
 * @since 1.0.22
 */

(function ($) {
    'use strict';

    const ChatPanel = {
        panel: null,
        messages: null,
        input: null,
        sendBtn: null,
        status: null,
        chatId: null,
        isLoading: false,

        /**
         * Initialize the chat panel
         */
        init: function () {
            this.panel = $('#rawwire-chat-panel');
            this.messages = $('#rawwire-chat-messages');
            this.input = $('#rawwire-chat-input');
            this.sendBtn = $('#rawwire-chat-send');
            this.status = $('#rawwire-chat-status');

            if (!this.panel.length) {
                return;
            }

            this.bindEvents();
            this.loadChatHistory();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            const self = this;

            // Toggle button
            $('#rawwire-chat-toggle').on('click', function () {
                self.toggle();
            });

            // Header click to expand/collapse
            this.panel.find('.rawwire-chat-panel__header').on('click', function (e) {
                if ($(e.target).closest('.rawwire-chat-panel__btn').length) {
                    return;
                }
                self.toggle();
            });

            // Control buttons
            $('#rawwire-chat-close').on('click', function () {
                self.close();
            });

            $('#rawwire-chat-minimize').on('click', function () {
                self.minimize();
            });

            $('#rawwire-chat-clear').on('click', function () {
                self.clearConversation();
            });

            // Send message
            this.sendBtn.on('click', function () {
                self.sendMessage();
            });

            // Enter to send, Shift+Enter for new line
            this.input.on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Auto-resize textarea
            this.input.on('input', function () {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        },

        /**
         * Toggle panel open/closed
         */
        toggle: function () {
            this.panel.toggleClass('rawwire-chat-panel--collapsed');

            if (!this.panel.hasClass('rawwire-chat-panel--collapsed')) {
                this.input.focus();
                this.scrollToBottom();
            }
        },

        /**
         * Close the panel
         */
        close: function () {
            this.panel.addClass('rawwire-chat-panel--hidden');
        },

        /**
         * Minimize the panel
         */
        minimize: function () {
            this.panel.addClass('rawwire-chat-panel--collapsed');
        },

        /**
         * Open the panel
         */
        open: function () {
            this.panel.removeClass('rawwire-chat-panel--hidden rawwire-chat-panel--collapsed');
            this.input.focus();
        },

        /**
         * Send a message
         */
        sendMessage: function () {
            const message = this.input.val().trim();

            if (!message || this.isLoading) {
                return;
            }

            // Add user message to UI
            this.addMessage(message, 'user');
            this.input.val('').css('height', 'auto');
            this.setLoading(true);

            // Show typing indicator
            this.showTyping();

            // Send via Venice.ai backend
            this.sendAjaxMessage(message);
        },

        /**
         * Check chat provider (Venice.ai is now the default)
         */
        getProvider: function () {
            return rawwireChatConfig.provider || 'venice';
        },

        /**
         * Send message via Venice.ai AJAX endpoint
         */
        sendAjaxMessage: function (message) {
            const self = this;

            $.ajax({
                url: rawwireChatConfig.ajaxUrl,
                method: 'POST',
                timeout: 60000, // 60 second timeout for AI responses
                data: {
                    action: rawwireChatConfig.action || 'rawwire_venice_chat',
                    nonce: rawwireChatConfig.nonce,
                    message: message
                },
                success: function (response) {
                    self.hideTyping();

                    if (response.success) {
                        self.chatId = response.data.chatId || self.chatId;
                        self.addMessage(response.data.reply, 'ai');
                        self.saveChatHistory();
                    } else {
                        const errorMsg = response.data?.message || response.data?.error || 'An error occurred';
                        console.error('[RawWire Chat] API Error:', errorMsg, response);
                        self.addMessage('⚠️ ' + errorMsg, 'ai');
                    }
                },
                error: function (xhr, status, error) {
                    self.hideTyping();

                    // Build informative error message
                    let errorMsg = 'Connection error';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. The AI may be processing a complex request.';
                    } else if (status === 'abort') {
                        errorMsg = 'Request was cancelled.';
                    } else if (xhr.status === 0) {
                        errorMsg = 'Network error. Please check your connection.';
                    } else if (xhr.status === 403) {
                        errorMsg = 'Session expired. Please refresh the page.';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error. Please try again later.';
                    } else if (xhr.status) {
                        errorMsg = 'Error ' + xhr.status + ': ' + (error || 'Unknown error');
                    }

                    console.error('[RawWire Chat] AJAX Error:', {
                        status: status,
                        error: error,
                        httpStatus: xhr.status,
                        response: xhr.responseText
                    });

                    self.addMessage('⚠️ ' + errorMsg, 'ai');
                },
                complete: function () {
                    self.setLoading(false);
                }
            });
        },

        /**
         * Add a message to the chat
         */
        addMessage: function (content, type) {
            const messageClass = type === 'user' ? 'rawwire-chat-message--user' : 'rawwire-chat-message--ai';

            // Format markdown-like content
            const formattedContent = this.formatMessage(content);

            const html = `
                <div class="rawwire-chat-message ${messageClass}">
                    <div class="rawwire-chat-message__content">${formattedContent}</div>
                </div>
            `;

            this.messages.append(html);
            this.scrollToBottom();
        },

        /**
         * Format message content (basic markdown)
         */
        formatMessage: function (content) {
            if (!content) return '';

            // Escape HTML
            let text = $('<div>').text(content).html();

            // Code blocks
            text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code class="$1">$2</code></pre>');

            // Inline code
            text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

            // Bold
            text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

            // Italic
            text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');

            // Line breaks
            text = text.replace(/\n/g, '<br>');

            return text;
        },

        /**
         * Show typing indicator
         */
        showTyping: function () {
            const html = `
                <div class="rawwire-chat-message rawwire-chat-message--ai" id="rawwire-typing-indicator">
                    <div class="rawwire-chat-typing">
                        <span class="rawwire-chat-typing__dot"></span>
                        <span class="rawwire-chat-typing__dot"></span>
                        <span class="rawwire-chat-typing__dot"></span>
                    </div>
                </div>
            `;
            this.messages.append(html);
            this.scrollToBottom();
        },

        /**
         * Hide typing indicator
         */
        hideTyping: function () {
            $('#rawwire-typing-indicator').remove();
        },

        /**
         * Scroll to bottom of messages
         */
        scrollToBottom: function () {
            this.messages.scrollTop(this.messages[0].scrollHeight);
        },

        /**
         * Set loading state
         */
        setLoading: function (loading) {
            this.isLoading = loading;
            this.sendBtn.prop('disabled', loading);
            this.input.prop('disabled', loading);

            if (loading) {
                this.status.text('Thinking...');
            } else {
                this.status.text('');
            }
        },

        /**
         * Clear conversation
         */
        clearConversation: function () {
            if (!confirm('Clear conversation history?')) {
                return;
            }

            const self = this;

            // Clear server-side history via Venice handler
            $.ajax({
                url: rawwireChatConfig.ajaxUrl,
                method: 'POST',
                data: {
                    action: rawwireChatConfig.clearAction || 'rawwire_venice_clear',
                    nonce: rawwireChatConfig.nonce
                }
            });

            this.messages.html(`
                <div class="rawwire-chat-message rawwire-chat-message--ai">
                    <div class="rawwire-chat-message__content">
                        Hello! I'm your Raw Wire Dashboard assistant. I can help you manage scrapers, 
                        generate content, analyze data, and automate workflows. What would you like to do?
                    </div>
                </div>
            `);

            this.chatId = null;
            localStorage.removeItem('rawwire_chat_history');
        },

        /**
         * Save chat history to localStorage
         */
        saveChatHistory: function () {
            const messages = [];

            this.messages.find('.rawwire-chat-message').each(function () {
                const $msg = $(this);
                const type = $msg.hasClass('rawwire-chat-message--user') ? 'user' : 'ai';
                const content = $msg.find('.rawwire-chat-message__content').text();

                if (content) {
                    messages.push({ type, content });
                }
            });

            localStorage.setItem('rawwire_chat_history', JSON.stringify({
                chatId: this.chatId,
                messages: messages.slice(-50) // Keep last 50 messages
            }));
        },

        /**
         * Load chat history from localStorage
         */
        loadChatHistory: function () {
            try {
                const stored = localStorage.getItem('rawwire_chat_history');
                if (!stored) return;

                const data = JSON.parse(stored);

                if (data.chatId) {
                    this.chatId = data.chatId;
                }

                if (data.messages && data.messages.length > 1) {
                    this.messages.empty();

                    data.messages.forEach(msg => {
                        this.addMessage(msg.content, msg.type);
                    });
                }
            } catch (e) {
                console.warn('Failed to load chat history:', e);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        ChatPanel.init();
    });

    // Expose globally for potential external use
    window.RawWireChatPanel = ChatPanel;

})(jQuery);
