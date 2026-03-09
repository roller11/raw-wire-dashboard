/**
 * Soothsayer v2 - Intelligence Action Center
 *
 * Tab switching, list/detail interactions, AJAX calls, modals
 */

(function ($) {
    'use strict';

    const Soothsayer = {
        currentLead: null,
        currentProject: null,

        init: function () {
            this.bindTabs();
            this.bindListItems();
            this.bindModals();
            this.bindActions();
            this.initCalendar();
        },

        // ============================================
        // Tabs
        // ============================================
        bindTabs: function () {
            $('.tab-btn').on('click', function () {
                const target = $(this).data('tab');
                $('.tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.tab-panel').removeClass('active');
                $(`#panel-${target}`).addClass('active');
            });
        },

        // ============================================
        // List Items (Leads & Projects)
        // ============================================
        bindListItems: function () {
            // Lead selection
            $(document).on('click', '.lead-item', function () {
                const id = $(this).data('id');
                $('.lead-item').removeClass('selected');
                $(this).addClass('selected');
                Soothsayer.loadLeadDetails(id);
            });

            // Project selection
            $(document).on('click', '.project-item', function () {
                const id = $(this).data('id');
                $('.project-item').removeClass('selected');
                $(this).addClass('selected');
                Soothsayer.loadProjectDetails(id);
            });
        },

        loadLeadDetails: function (id) {
            const $detail = $('#lead-details');
            $detail.html('<div class="detail-loading"><span class="dashicons dashicons-update spin"></span> Loading...</div>');

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_get_lead_details',
                    nonce: soothsayerData.nonce,
                    lead_id: id
                },
                success: function (response) {
                    if (response.success && response.data) {
                        // Use pre-rendered HTML from PHP
                        if (response.data.html) {
                            $detail.html(response.data.html);
                            Soothsayer.currentLead = response.data.lead;
                        } else {
                            // Fallback to direct lead data
                            Soothsayer.currentLead = response.data;
                            Soothsayer.renderLeadDetail(response.data);
                        }
                    } else {
                        $detail.html('<div class="detail-error"><span class="dashicons dashicons-warning"></span> Failed to load lead</div>');
                    }
                },
                error: function () {
                    $detail.html('<div class="detail-error"><span class="dashicons dashicons-warning"></span> Network error</div>');
                }
            });
        },

        renderLeadDetail: function (lead) {
            const scoreClass = lead.score >= 80 ? 'score-hot' : (lead.score >= 50 ? 'score-warm' : 'score-cool');

            let html = `
            <div class="detail-content">
                <div class="detail-header">
                    <div class="lead-score detail-score ${scoreClass}">${lead.score}</div>
                    <div class="detail-title">
                        <h2>${this.escapeHtml(lead.title)}</h2>
                        <p class="detail-subtitle">${this.escapeHtml(lead.company)}</p>
                    </div>
                </div>
                
                <div class="detail-overview">
                    <div class="overview-item">
                        <span class="label">Est. Value</span>
                        <span class="value">${lead.value}</span>
                    </div>
                    <div class="overview-item">
                        <span class="label">Location</span>
                        <span class="value">${this.escapeHtml(lead.location)}</span>
                    </div>
                    <div class="overview-item">
                        <span class="label">Deadline</span>
                        <span class="value">${lead.deadline}</span>
                    </div>
                </div>
                
                <p class="detail-description">${this.escapeHtml(lead.description || '')}</p>
                
                <!-- Contacts -->
                <section class="detail-section section-contacts">
                    <h3><span class="dashicons dashicons-groups"></span> Known Contacts</h3>
                    <div class="contacts-list">
                        ${this.renderContacts(lead.contacts)}
                    </div>
                </section>
                
                <!-- Events -->
                <section class="detail-section section-events">
                    <h3><span class="dashicons dashicons-calendar-alt"></span> Related Events</h3>
                    <div class="events-list">
                        ${this.renderEvents(lead.events)}
                    </div>
                </section>
                
                <!-- Bidding Info -->
                <section class="detail-section section-bidding">
                    <h3><span class="dashicons dashicons-money-alt"></span> Bidding Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="label">Bid Due Date</span>
                            <span class="value">${lead.bid_due || 'TBD'}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Project Type</span>
                            <span class="value">${lead.project_type || 'N/A'}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Estimated Duration</span>
                            <span class="value">${lead.duration || 'N/A'}</span>
                        </div>
                    </div>
                    <div class="section-actions">
                        <button class="btn-secondary" data-action="download-specs"><span class="dashicons dashicons-download"></span> Download Specs</button>
                        <button class="btn-secondary" data-action="add-to-pipeline"><span class="dashicons dashicons-plus"></span> Add to Pipeline</button>
                    </div>
                </section>
                
                <!-- Knowledge -->
                <section class="detail-section section-knowledge">
                    <h3><span class="dashicons dashicons-lightbulb"></span> What We Know</h3>
                    <ul class="knowledge-list">
                        ${this.renderKnowledge(lead.knowledge)}
                    </ul>
                    <button class="btn-primary btn-full" data-action="discuss-ai" data-lead-id="${lead.id}">
                        <span class="dashicons dashicons-admin-comments"></span> Discuss with AI
                    </button>
                </section>
                
                <!-- Suggestions -->
                <section class="detail-section section-suggestions">
                    <h3><span class="dashicons dashicons-chart-line"></span> AI Suggestions</h3>
                    <div class="suggestions-list">
                        ${this.renderSuggestions(lead.suggestions)}
                    </div>
                </section>
            </div>
            `;

            $('#lead-details').html(html);
        },

        renderContacts: function (contacts) {
            if (!contacts || contacts.length === 0) {
                return '<p class="no-data">No contacts found</p>';
            }
            return contacts.map(c => `
                <div class="contact-card" data-contact-id="${c.id}">
                    <div class="contact-info">
                        <strong>${this.escapeHtml(c.name)}</strong>
                        <span class="contact-title">${this.escapeHtml(c.title)}</span>
                        <span class="contact-email">${this.escapeHtml(c.email)}</span>
                    </div>
                    <div class="contact-actions">
                        <button class="btn-sm" data-action="send-phone" data-contact='${JSON.stringify(c)}' title="Send to Phone">
                            <span class="dashicons dashicons-smartphone"></span>
                        </button>
                        <button class="btn-sm" data-action="add-contacts" data-contact='${JSON.stringify(c)}' title="Add to Contacts">
                            <span class="dashicons dashicons-id-alt"></span>
                        </button>
                        <button class="btn-sm" data-action="compose-email" data-contact='${JSON.stringify(c)}' title="Compose Email">
                            <span class="dashicons dashicons-email"></span>
                        </button>
                    </div>
                </div>
            `).join('');
        },

        renderEvents: function (events) {
            if (!events || events.length === 0) {
                return '<p class="no-data">No related events</p>';
            }
            return events.map(e => `
                <div class="event-card">
                    <div class="event-date">
                        <span class="dashicons dashicons-calendar"></span>
                        ${e.date}
                    </div>
                    <div class="event-info">
                        <strong>${this.escapeHtml(e.title)}</strong>
                        <span>${this.escapeHtml(e.location)}</span>
                        ${e.contacts ? `<span class="event-contacts">${e.contacts} contacts attending</span>` : ''}
                    </div>
                    <button class="btn-sm" data-action="add-calendar" data-event='${JSON.stringify(e)}' title="Add to Calendar">
                        <span class="dashicons dashicons-plus"></span>
                    </button>
                </div>
            `).join('');
        },

        renderKnowledge: function (items) {
            if (!items || items.length === 0) {
                return '<li>No additional insights available</li>';
            }
            return items.map(k => `<li>${this.escapeHtml(k)}</li>`).join('');
        },

        renderSuggestions: function (suggestions) {
            if (!suggestions || suggestions.length === 0) {
                return '<p class="no-data">No suggestions yet</p>';
            }
            return suggestions.map(s => `
                <div class="suggestion-card">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <p>${this.escapeHtml(s)}</p>
                </div>
            `).join('');
        },

        loadProjectDetails: function (id) {
            const $detail = $('#project-details');
            $detail.html('<div class="detail-loading"><span class="dashicons dashicons-update spin"></span> Loading...</div>');

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_get_confirmed_projects',
                    nonce: soothsayerData.nonce,
                    project_id: id
                },
                success: function (response) {
                    if (response.success && response.data) {
                        // Use pre-rendered HTML from PHP
                        if (response.data.html) {
                            $detail.html(response.data.html);
                            Soothsayer.currentProject = response.data.project;
                        } else if (response.data.project) {
                            Soothsayer.currentProject = response.data.project;
                            Soothsayer.renderProjectDetail(response.data.project);
                        } else {
                            // Fallback: find in array
                            const project = Array.isArray(response.data) ? response.data.find(p => p.id == id) : response.data;
                            if (project) {
                                Soothsayer.currentProject = project;
                                Soothsayer.renderProjectDetail(project);
                            }
                        }
                    } else {
                        $detail.html('<div class="detail-error"><span class="dashicons dashicons-warning"></span> Failed to load project</div>');
                    }
                },
                error: function () {
                    $detail.html('<div class="detail-error"><span class="dashicons dashicons-warning"></span> Network error</div>');
                }
            });
        },

        renderProjectDetail: function (project) {
            const html = `
            <div class="detail-content">
                <div class="detail-header">
                    <span class="project-status status-${project.status}">${project.status}</span>
                    <div class="detail-title">
                        <h2>${this.escapeHtml(project.title)}</h2>
                        <p class="detail-subtitle">${this.escapeHtml(project.gc)}</p>
                    </div>
                </div>
                
                <div class="detail-overview">
                    <div class="overview-item">
                        <span class="label">Contract Value</span>
                        <span class="value">${project.value}</span>
                    </div>
                    <div class="overview-item">
                        <span class="label">Start Date</span>
                        <span class="value">${project.start_date}</span>
                    </div>
                    <div class="overview-item">
                        <span class="label">Duration</span>
                        <span class="value">${project.duration || 'TBD'}</span>
                    </div>
                </div>
                
                <p class="detail-description">${this.escapeHtml(project.description || '')}</p>
                
                <section class="detail-section">
                    <h3><span class="dashicons dashicons-groups"></span> Project Team</h3>
                    <div class="contacts-list">
                        ${this.renderContacts(project.contacts)}
                    </div>
                </section>
                
                <section class="detail-section">
                    <h3><span class="dashicons dashicons-calendar-alt"></span> Milestones</h3>
                    <div class="events-list">
                        ${this.renderMilestones(project.milestones)}
                    </div>
                </section>
            </div>
            `;

            $('#project-details').html(html);
        },

        renderMilestones: function (milestones) {
            if (!milestones || milestones.length === 0) {
                return '<p class="no-data">No milestones defined</p>';
            }
            return milestones.map(m => `
                <div class="event-card">
                    <div class="event-date">
                        <span class="dashicons dashicons-flag"></span>
                        ${m.date}
                    </div>
                    <div class="event-info">
                        <strong>${this.escapeHtml(m.title)}</strong>
                        <span>${m.status}</span>
                    </div>
                </div>
            `).join('');
        },

        // ============================================
        // Actions
        // ============================================
        bindActions: function () {
            // Send to Phone
            $(document).on('click', '[data-action="send-phone"]', function () {
                const contact = Soothsayer.getContactFromButton($(this));
                if (contact) Soothsayer.sendToPhone(contact);
            });

            // Add to Contacts
            $(document).on('click', '[data-action="add-contacts"]', function () {
                const contact = Soothsayer.getContactFromButton($(this));
                if (contact) Soothsayer.addToContacts(contact);
            });

            // Compose Email
            $(document).on('click', '[data-action="compose-email"]', function () {
                const contact = Soothsayer.getContactFromButton($(this));
                if (contact) Soothsayer.openEmailModal(contact);
            });

            // Add to Calendar
            $(document).on('click', '[data-action="add-calendar"]', function () {
                const event = $(this).data('event');
                Soothsayer.addToCalendar(event);
            });

            // Discuss with AI
            $(document).on('click', '[data-action="discuss-ai"]', function () {
                const leadId = $(this).data('lead-id') || $(this).closest('.ss-detail').data('lead-id') || (Soothsayer.currentLead ? Soothsayer.currentLead.id : 0);
                Soothsayer.openAIModal(leadId);
            });

            // Follow Lead
            $(document).on('click', '[data-action="follow-lead"]', function () {
                const leadId = $(this).data('lead-id') || $(this).closest('.ss-detail').data('lead-id') || (Soothsayer.currentLead ? Soothsayer.currentLead.id : 0);
                Soothsayer.followLead(leadId);
            });

            // Probe Further (Network tab entities/projects)
            $(document).on('click', '.btn-probe', function () {
                const $btn = $(this);
                const type = $btn.data('type'); // 'entity' or 'project'
                const itemId = $btn.data('id');
                const sourceId = $btn.data('source-id') || (Soothsayer.currentLead ? Soothsayer.currentLead.source_id : 0);
                const parentLeadId = Soothsayer.currentLead ? Soothsayer.currentLead.id : 0;
                Soothsayer.probeFurther(type, itemId, sourceId, parentLeadId, $btn);
            });

            // Launch Tier 2 Deep Dive (from lead details)
            $(document).on('click', '.btn-tier2-dive', function () {
                const $btn = $(this);
                const sourceId = $btn.data('source-id') || (Soothsayer.currentLead ? Soothsayer.currentLead.source_id : 0);
                const leadId = $btn.data('lead-id') || (Soothsayer.currentLead ? Soothsayer.currentLead.id : 0);
                Soothsayer.launchDeepDive(sourceId, leadId, $btn);
            });

            // Party Investigation (Tier 1)
            $(document).on('click', '.btn-investigate', function () {
                const $btn = $(this);
                const candidateId = $btn.data('candidate-id');
                const sourceId = $btn.data('source-id');

                if (!confirm('Run party investigation on this record? This will use AI API tokens.')) {
                    return;
                }

                const originalText = $btn.html();
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Investigating...');

                $.ajax({
                    url: soothsayerData.ajaxUrl || ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    timeout: 600000, // 10 minutes — investigation can be very slow
                    data: {
                        action: 'rawwire_lead_investigate',
                        nonce: soothsayerData.leadNonce || soothsayerData.nonce,
                        candidate_id: candidateId,
                        source_id: sourceId
                    },
                    success: function (response) {
                        console.log('[RawWire] Investigation response:', response);
                        if (response.success) {
                            // Check if the investigation actually produced data
                            var failureReasons = response.data?.failure_reasons || [];
                            if (failureReasons.length > 0) {
                                Soothsayer.toast('Investigation completed with warnings: ' + failureReasons.join('; '), 'warning');
                            } else {
                                Soothsayer.toast('Investigation completed successfully!', 'success');
                            }
                            // Reload lead to show full intel brief
                            if (Soothsayer.currentLead) {
                                Soothsayer.loadLeadDetails(Soothsayer.currentLead.id);
                                return;
                            }
                            // Fallback: update status badge in place
                            const $section = $btn.closest('.ss-intel-brief');
                            $section.find('.ss-inv-badge').text('Complete').removeClass('ss-inv-badge-pending ss-inv-badge-failed').addClass('ss-inv-badge-complete');
                            $btn.remove();
                        } else {
                            // Investigation completely failed -- show persistent failure message
                            var msg = response.data?.message || 'Investigation failed';
                            var reasons = response.data?.failure_reasons || [];
                            if (reasons.length > 0) {
                                msg += '\n' + reasons.join('\n');
                            }
                            Soothsayer.toast(msg, 'error');

                            // Update badge to show failed status
                            const $section2 = $btn.closest('.ss-intel-brief');
                            $section2.find('.ss-inv-badge')
                                .text('Failed')
                                .removeClass('ss-inv-badge-pending ss-inv-badge-complete ss-inv-badge-incomplete')
                                .addClass('ss-inv-badge-failed');

                            // Reload lead detail to show the persistent failure banner
                            if (Soothsayer.currentLead) {
                                Soothsayer.loadLeadDetails(Soothsayer.currentLead.id);
                                return;
                            }
                            $btn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('[RawWire] Investigation AJAX error:', status, error, xhr.responseText?.substring(0, 500));
                        Soothsayer.toast('Investigation request failed: ' + (status === 'timeout' ? 'Server took too long' : status), 'error');
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });

            // Reset Investigation (Developer Tool)
            $(document).on('click', '[data-action="reset-investigation"]', function () {
                const $btn = $(this);
                const sourceId = $btn.data('source-id');

                if (!confirm('Reset investigation for this record? This will clear all party profiles and allow you to re-run the investigation.')) {
                    return;
                }

                const originalText = $btn.html();
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Resetting...');

                $.ajax({
                    url: soothsayerData.ajaxUrl || ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rawwire_reset_investigation',
                        nonce: soothsayerData.nonce,
                        source_id: sourceId
                    },
                    success: function (response) {
                        if (response.success) {
                            Soothsayer.toast('Investigation reset! You can now re-run it.', 'success');
                            // Reload lead details to show fresh pending state
                            if (Soothsayer.currentLead) {
                                Soothsayer.loadLeadDetails(Soothsayer.currentLead.id);
                            }
                        } else {
                            Soothsayer.toast(response.data?.message || 'Reset failed', 'error');
                        }
                        $btn.prop('disabled', false).html(originalText);
                    },
                    error: function () {
                        Soothsayer.toast('Reset request failed', 'error');
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });

            // Dismiss failure banner
            $(document).on('click', '.ss-failure-dismiss', function () {
                var bannerId = $(this).data('dismiss-banner');
                $('#ss-failure-banner-' + bannerId).slideUp(300, function () {
                    $(this).remove();
                });
            });

            // Trash/Archive Lead
            $(document).on('click', '[data-action="trash-lead"]', function () {
                const $btn = $(this);
                const leadId = $btn.data('lead-id') || $btn.closest('.ss-detail').data('lead-id');
                const sourceId = $btn.data('source-id');

                if (!confirm('Move this lead to trash? It will be archived and removed from the active list.')) {
                    return;
                }

                const originalText = $btn.html();
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Trashing...');

                $.ajax({
                    url: soothsayerData.ajaxUrl || ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rawwire_trash_lead',
                        nonce: soothsayerData.nonce,
                        lead_id: leadId,
                        source_id: sourceId
                    },
                    success: function (response) {
                        if (response.success) {
                            Soothsayer.toast('Lead moved to trash', 'success');
                            // Remove from list and clear detail
                            $(`.lead-item[data-id="${leadId}"]`).fadeOut(300, function () { $(this).remove(); });
                            $('.detail-panel').html('<div class="ss-empty-detail"><p>Select a lead to view details</p></div>');
                            Soothsayer.currentLead = null;
                        } else {
                            Soothsayer.toast(response.data?.message || 'Failed to trash lead', 'error');
                            $btn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function () {
                        Soothsayer.toast('Trash request failed', 'error');
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        },

        sendToPhone: function (contact) {
            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_send_to_phone',
                    nonce: soothsayerData.nonce,
                    contact: contact
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.toast('Contact sent to phone', 'success');
                    } else {
                        Soothsayer.toast(response.data || 'Failed to send', 'error');
                    }
                }
            });
        },

        addToContacts: function (contact) {
            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_add_to_contacts',
                    nonce: soothsayerData.nonce,
                    contact: contact
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.toast('Added to contacts', 'success');
                    } else {
                        Soothsayer.toast(response.data || 'Failed to add contact', 'error');
                    }
                }
            });
        },

        addToCalendar: function (event) {
            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_add_calendar_event',
                    nonce: soothsayerData.nonce,
                    event: event
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.toast('Event added to calendar', 'success');
                    } else {
                        Soothsayer.toast(response.data || 'Failed to add event', 'error');
                    }
                }
            });
        },

        followLead: function (leadId) {
            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_follow_lead',
                    nonce: soothsayerData.nonce,
                    lead_id: leadId
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.toast('Following lead - daily updates enabled', 'success');
                    }
                }
            });
        },

        probeFurther: function (type, itemId, sourceId, parentLeadId, $btn) {
            const originalText = $btn.html();
            $btn.html('<span class="dashicons dashicons-update spin"></span>').prop('disabled', true);

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_probe_further',
                    nonce: soothsayerData.nonce,
                    type: type,
                    item_id: itemId,
                    source_id: sourceId,
                    parent_lead_id: parentLeadId
                },
                success: function (response) {
                    if (response.success) {
                        $btn.html('<span class="dashicons dashicons-yes"></span> Completed').addClass('probed');
                        Soothsayer.toast(`Tier 2 deep dive completed for ${type}`, 'success');
                    } else {
                        $btn.html(originalText).prop('disabled', false);
                        Soothsayer.toast(response.data?.message || 'Failed to run Tier 2 deep dive', 'error');
                    }
                },
                error: function () {
                    $btn.html(originalText).prop('disabled', false);
                    Soothsayer.toast('Network error', 'error');
                }
            });
        },

        launchDeepDive: function (sourceId, leadId, $btn) {
            const originalText = $btn.html();
            $btn.html('<span class="dashicons dashicons-update spin"></span> Launching...').prop('disabled', true);

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_launch_deep_dive',
                    nonce: soothsayerData.nonce,
                    source_id: sourceId,
                    lead_id: leadId
                },
                success: function (response) {
                    if (response.success) {
                        $btn.html('<span class="dashicons dashicons-yes-alt"></span> Tier 2 Complete').addClass('launched');
                        Soothsayer.toast(response.data.message, 'success');

                        // Mark related probe buttons as completed to reflect the source-wide deep dive.
                        $('.btn-probe').filter(function () {
                            return Number($(this).data('source-id')) === Number(sourceId);
                        }).not('.probed').html('<span class="dashicons dashicons-yes"></span> Completed').prop('disabled', true).addClass('probed');
                    } else {
                        $btn.html(originalText).prop('disabled', false);
                        Soothsayer.toast(response.data?.message || 'Failed to launch deep dive', 'error');
                    }
                },
                error: function () {
                    $btn.html(originalText).prop('disabled', false);
                    Soothsayer.toast('Network error', 'error');
                }
            });
        },

        // ============================================
        // Modals
        // ============================================
        bindModals: function () {
            // Close modal
            $(document).on('click', '.modal-close, .modal-overlay', function () {
                $(this).closest('.soothsayer-modal').removeClass('active');
            });

            // Send email
            $(document).on('click', '#send-email-btn', function () {
                Soothsayer.sendEmail();
            });

            // Send AI message
            $(document).on('click', '#send-ai-btn', function () {
                Soothsayer.sendAIMessage();
            });

            // Enter key in AI input
            $(document).on('keypress', '#ai-input', function (e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    Soothsayer.sendAIMessage();
                }
            });
        },

        openEmailModal: function (contact) {
            $('#email-to').val(contact.email);
            $('#email-subject').val('');
            $('#email-body').val('');
            $('#email-modal').data('contact', contact).addClass('active');
        },

        sendEmail: function () {
            const modal = $('#email-modal');
            const data = {
                action: 'rawwire_compose_email',
                nonce: soothsayerData.nonce,
                to: $('#email-to').val(),
                subject: $('#email-subject').val(),
                body: $('#email-body').val()
            };

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: data,
                success: function (response) {
                    if (response.success) {
                        modal.removeClass('active');
                        Soothsayer.toast('Email sent', 'success');
                    } else {
                        Soothsayer.toast(response.data || 'Failed to send', 'error');
                    }
                }
            });
        },

        openAIModal: function (leadId) {
            const lead = this.currentLead;
            if (!lead) return;

            $('#ai-chat').html(`
                <div class="ai-message ai-assistant">
                    <p>I'm ready to discuss <strong>${this.escapeHtml(lead.title)}</strong>. What would you like to know?</p>
                </div>
            `);
            $('#ai-input').val('');
            $('#ai-modal').data('lead-id', leadId).addClass('active');
        },

        sendAIMessage: function () {
            const input = $('#ai-input');
            const message = input.val().trim();
            if (!message) return;

            const chat = $('#ai-chat');
            chat.append(`<div class="ai-message ai-user"><p>${this.escapeHtml(message)}</p></div>`);
            input.val('');

            const leadId = $('#ai-modal').data('lead-id');

            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_discuss_with_ai',
                    nonce: soothsayerData.nonce,
                    lead_id: leadId,
                    message: message
                },
                success: function (response) {
                    if (response.success) {
                        chat.append(`<div class="ai-message ai-assistant"><p>${response.data.reply}</p></div>`);
                        chat.scrollTop(chat[0].scrollHeight);
                    }
                }
            });
        },

        // ============================================
        // Calendar
        // ============================================
        initCalendar: function () {
            this.loadCalendarEvents();
        },

        loadCalendarEvents: function () {
            $.ajax({
                url: soothsayerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rawwire_get_events',
                    nonce: soothsayerData.nonce
                },
                success: function (response) {
                    if (response.success) {
                        Soothsayer.renderCalendar(response.data);
                    }
                }
            });
        },

        renderCalendar: function (events) {
            const grid = $('.calendar-grid');
            const now = new Date();
            const year = now.getFullYear();
            const month = now.getMonth();

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            let html = days.map(d => `<div class="calendar-day header">${d}</div>`).join('');

            // Previous month padding
            for (let i = 0; i < firstDay; i++) {
                html += '<div class="calendar-day other-month"></div>';
            }

            // Current month days
            for (let day = 1; day <= daysInMonth; day++) {
                const isToday = day === now.getDate();
                const dayEvents = events.filter(e => {
                    const eventDate = new Date(e.date);
                    return eventDate.getDate() === day && eventDate.getMonth() === month;
                });

                html += `<div class="calendar-day${isToday ? ' today' : ''}">`;
                html += `<div class="day-number">${day}</div>`;
                dayEvents.forEach(e => {
                    html += `<div class="day-event type-${e.type}" title="${this.escapeHtml(e.title)}">${this.escapeHtml(e.title)}</div>`;
                });
                html += '</div>';
            }

            grid.html(html);
        },

        // ============================================
        // Utilities
        // ============================================

        /**
         * Extract contact data from a button click.
         * Supports both: data-contact JSON on the button (JS fallback)
         * and the new ss-contact-card parent with data-email/data-phone.
         */
        getContactFromButton: function ($btn) {
            // Legacy path: button carries data-contact JSON
            const inline = $btn.data('contact');
            if (inline) return inline;

            // New path: read from closest ss-contact-card
            const $card = $btn.closest('.ss-contact-card');
            if ($card.length) {
                return {
                    name: $card.find('.ss-contact-name').text().trim(),
                    title: $card.find('.ss-contact-title').text().trim(),
                    email: $card.data('email') || '',
                    phone: $card.data('phone') || ''
                };
            }

            return null;
        },

        toast: function (message, type = 'info') {
            const toast = $(`<div class="soothsayer-toast ${type}">${message}</div>`);
            $('body').append(toast);
            setTimeout(() => toast.fadeOut(() => toast.remove()), 3000);
        },

        escapeHtml: function (str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    };

    // Initialize on DOM ready
    $(document).ready(function () {
        if ($('.soothsayer-app').length) {
            Soothsayer.init();
        }
    });

})(jQuery);
