/**
 * Raw-Wire Template Builder
 * JavaScript for wizard interface, panel designer, and template management
 */

(function($) {
    'use strict';

    const TemplateBuilder = {
        currentStep: 1,
        totalSteps: 7,
        templateData: {
            meta: {
                name: '',
                id: '',
                description: '',
                author: '',
                version: '1.0.0'
            },
            useCase: '',
            pages: [],
            panels: [],
            toolbox: {
                scraper: false,
                ai_generator: false,
                publisher: false,
                workflow: false
            },
            styling: {
                primaryColor: '#2271b1',
                secondaryColor: '#72aee6',
                accentColor: '#00a32a',
                backgroundColor: '#f0f0f1',
                textColor: '#1d2327'
            }
        },

        init: function() {
            this.setupWizardNavigation();
            this.setupUseCaseSelection();
            this.setupPanelDesigner();
            this.setupTemplateInfo();
            this.setupToolboxConfig();
            this.setupStyling();
            this.setupJSONEditor();
            this.setupImportExport();
            this.setupTabNavigation();
        },

        // Wizard Step Navigation
        setupWizardNavigation: function() {
            const self = this;

            $('.wizard-next').on('click', function() {
                if (self.validateCurrentStep()) {
                    self.goToStep(self.currentStep + 1);
                }
            });

            $('.wizard-prev').on('click', function() {
                self.goToStep(self.currentStep - 1);
            });

            $('.wizard-step-indicator').on('click', function() {
                const step = $(this).data('step');
                if (step < self.currentStep) {
                    self.goToStep(step);
                }
            });
        },

        goToStep: function(step) {
            if (step < 1 || step > this.totalSteps) return;

            // Hide current step
            $('.wizard-step-content[data-step="' + this.currentStep + '"]').removeClass('active');
            $('.wizard-step-indicator[data-step="' + this.currentStep + '"]').removeClass('active');

            // Show new step
            this.currentStep = step;
            $('.wizard-step-content[data-step="' + step + '"]').addClass('active');
            $('.wizard-step-indicator[data-step="' + step + '"]').addClass('active').addClass('completed');

            // Update button states
            this.updateNavigationButtons();

            // Scroll to top
            $('.rawwire-builder-wizard').scrollTop(0);
        },

        updateNavigationButtons: function() {
            const $prevBtn = $('.wizard-prev');
            const $nextBtn = $('.wizard-next');

            // Show/hide previous button
            if (this.currentStep === 1) {
                $prevBtn.hide();
            } else {
                $prevBtn.show();
            }

            // Update next button
            if (this.currentStep === this.totalSteps) {
                $nextBtn.text('Generate Template').addClass('button-primary');
            } else {
                $nextBtn.text('Next Step').removeClass('button-primary');
            }
        },

        validateCurrentStep: function() {
            switch (this.currentStep) {
                case 1: // Template Info
                    const name = $('#template-name').val();
                    if (!name) {
                        alert('Please enter a template name');
                        return false;
                    }
                    this.templateData.meta.name = name;
                    this.templateData.meta.id = this.slugify(name);
                    this.templateData.meta.description = $('#template-description').val();
                    this.templateData.meta.author = $('#template-author').val();
                    break;

                case 2: // Use Case
                    const useCase = $('input[name="use-case"]:checked').val();
                    if (!useCase) {
                        alert('Please select a use case');
                        return false;
                    }
                    this.templateData.useCase = useCase;
                    this.applyUseCaseDefaults(useCase);
                    break;

                case 3: // Pages
                    if (this.templateData.pages.length === 0) {
                        alert('Please add at least one page');
                        return false;
                    }
                    break;

                case 4: // Panels
                    if (this.templateData.panels.length === 0) {
                        alert('Please add at least one panel');
                        return false;
                    }
                    break;

                case 7: // Final step - generate template
                    this.generateTemplate();
                    return false;
            }
            return true;
        },

        // Template Info Setup
        setupTemplateInfo: function() {
            const self = this;

            $('#template-name').on('input', function() {
                const name = $(this).val();
                const slug = self.slugify(name);
                $('#template-id').val(slug);
            });
        },

        slugify: function(text) {
            return text
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/(^-|-$)/g, '');
        },

        // Use Case Selection
        setupUseCaseSelection: function() {
            const self = this;

            $('.use-case-card').on('click', function() {
                $('.use-case-card').removeClass('selected');
                $(this).addClass('selected');
                $(this).find('input[type="radio"]').prop('checked', true);
            });
        },

        applyUseCaseDefaults: function(useCase) {
            // Apply intelligent defaults based on use case
            const defaults = {
                'content-aggregation': {
                    pages: [
                        { id: 'dashboard', title: 'Dashboard', icon: 'dashboard' },
                        { id: 'feeds', title: 'Feeds', icon: 'rss' },
                        { id: 'items', title: 'Items', icon: 'list-view' }
                    ],
                    toolbox: {
                        scraper: true,
                        ai_generator: false,
                        publisher: true,
                        workflow: true
                    }
                },
                'ai-generation': {
                    pages: [
                        { id: 'dashboard', title: 'Dashboard', icon: 'dashboard' },
                        { id: 'generator', title: 'Generator', icon: 'admin-generic' },
                        { id: 'library', title: 'Library', icon: 'book' }
                    ],
                    toolbox: {
                        scraper: false,
                        ai_generator: true,
                        publisher: true,
                        workflow: true
                    }
                },
                'social-monitoring': {
                    pages: [
                        { id: 'dashboard', title: 'Dashboard', icon: 'dashboard' },
                        { id: 'streams', title: 'Streams', icon: 'admin-site-alt3' },
                        { id: 'analytics', title: 'Analytics', icon: 'chart-line' }
                    ],
                    toolbox: {
                        scraper: true,
                        ai_generator: true,
                        publisher: true,
                        workflow: false
                    }
                },
                'data-dashboard': {
                    pages: [
                        { id: 'dashboard', title: 'Dashboard', icon: 'dashboard' },
                        { id: 'reports', title: 'Reports', icon: 'chart-bar' },
                        { id: 'sources', title: 'Sources', icon: 'database' }
                    ],
                    toolbox: {
                        scraper: true,
                        ai_generator: false,
                        publisher: false,
                        workflow: false
                    }
                },
                'workflow-automation': {
                    pages: [
                        { id: 'dashboard', title: 'Dashboard', icon: 'dashboard' },
                        { id: 'workflows', title: 'Workflows', icon: 'networking' },
                        { id: 'queue', title: 'Queue', icon: 'clock' }
                    ],
                    toolbox: {
                        scraper: true,
                        ai_generator: true,
                        publisher: true,
                        workflow: true
                    }
                }
            };

            const config = defaults[useCase] || defaults['content-aggregation'];
            this.templateData.pages = config.pages;
            this.templateData.toolbox = config.toolbox;

            // Update UI to reflect defaults
            this.renderPagesList();
            this.updateToolboxUI();
        },

        // Page Management
        renderPagesList: function() {
            const $list = $('.pages-list');
            $list.empty();

            this.templateData.pages.forEach((page, index) => {
                $list.append(`
                    <div class="page-item" data-index="${index}">
                        <span class="dashicons dashicons-${page.icon}"></span>
                        <span class="page-title">${page.title}</span>
                        <button class="button button-small edit-page" data-index="${index}">Edit</button>
                        <button class="button button-small button-link-delete delete-page" data-index="${index}">Delete</button>
                    </div>
                `);
            });
        },

        // Panel Designer
        setupPanelDesigner: function() {
            const self = this;

            // Make panel types draggable
            $('.panel-type').draggable({
                helper: 'clone',
                connectToSortable: '.panels-canvas',
                revert: 'invalid'
            });

            // Make canvas sortable
            $('.panels-canvas').sortable({
                placeholder: 'panel-placeholder',
                receive: function(event, ui) {
                    const panelType = ui.item.data('type');
                    self.addPanel(panelType);
                    ui.item.remove();
                },
                update: function() {
                    self.updatePanelOrder();
                }
            });

            // Panel type click to add
            $('.panel-type').on('click', function() {
                const type = $(this).data('type');
                self.addPanel(type);
            });

            // Canvas drop zone
            $('.panels-canvas').on('drop', function(e) {
                e.preventDefault();
                const panelType = e.originalEvent.dataTransfer.getData('panel-type');
                if (panelType) {
                    self.addPanel(panelType);
                }
            }).on('dragover', function(e) {
                e.preventDefault();
            });
        },

        addPanel: function(type) {
            const panel = {
                id: this.generatePanelId(),
                type: type,
                title: this.getPanelDefaultTitle(type),
                position: this.templateData.panels.length,
                width: 'half',
                config: {}
            };

            this.templateData.panels.push(panel);
            this.renderPanelsCanvas();
        },

        generatePanelId: function() {
            return 'panel_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        },

        getPanelDefaultTitle: function(type) {
            const titles = {
                'stat-card': 'Statistics',
                'data-table': 'Data Table',
                'chart': 'Chart',
                'feed-list': 'Feed List',
                'content-queue': 'Content Queue',
                'activity-log': 'Activity Log',
                'ai-generator': 'AI Generator',
                'approval-panel': 'Approval Queue',
                'scheduler': 'Scheduler',
                'settings': 'Settings'
            };
            return titles[type] || 'Panel';
        },

        renderPanelsCanvas: function() {
            const $canvas = $('.panels-canvas');
            $canvas.empty();

            if (this.templateData.panels.length === 0) {
                $canvas.html('<div class="canvas-empty-state">Drag panel types here or click to add</div>');
                return;
            }

            this.templateData.panels.forEach((panel, index) => {
                $canvas.append(`
                    <div class="panel-preview" data-index="${index}">
                        <div class="panel-preview-header">
                            <span class="panel-title">${panel.title}</span>
                            <span class="panel-type-badge">${panel.type}</span>
                        </div>
                        <div class="panel-preview-actions">
                            <button class="button button-small edit-panel" data-index="${index}">
                                <span class="dashicons dashicons-edit"></span> Edit
                            </button>
                            <button class="button button-small delete-panel" data-index="${index}">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                `);
            });

            // Bind delete actions
            $('.delete-panel').on('click', function() {
                const index = $(this).data('index');
                TemplateBuilder.deletePanel(index);
            });
        },

        deletePanel: function(index) {
            if (confirm('Are you sure you want to delete this panel?')) {
                this.templateData.panels.splice(index, 1);
                this.renderPanelsCanvas();
            }
        },

        updatePanelOrder: function() {
            const newOrder = [];
            $('.panels-canvas .panel-preview').each(function(index) {
                const oldIndex = $(this).data('index');
                newOrder.push(TemplateBuilder.templateData.panels[oldIndex]);
            });
            this.templateData.panels = newOrder;
            this.renderPanelsCanvas();
        },

        // Toolbox Configuration
        setupToolboxConfig: function() {
            const self = this;

            $('input[name^="toolbox_"]').on('change', function() {
                const feature = $(this).attr('name').replace('toolbox_', '');
                self.templateData.toolbox[feature] = $(this).is(':checked');
            });
        },

        updateToolboxUI: function() {
            $('input[name="toolbox_scraper"]').prop('checked', this.templateData.toolbox.scraper);
            $('input[name="toolbox_ai_generator"]').prop('checked', this.templateData.toolbox.ai_generator);
            $('input[name="toolbox_publisher"]').prop('checked', this.templateData.toolbox.publisher);
            $('input[name="toolbox_workflow"]').prop('checked', this.templateData.toolbox.workflow);
        },

        // Styling
        setupStyling: function() {
            const self = this;

            $('.color-picker').on('change', function() {
                const colorKey = $(this).data('color');
                self.templateData.styling[colorKey] = $(this).val();
            });
        },

        // JSON Editor
        setupJSONEditor: function() {
            const self = this;

            $('.json-validate').on('click', function() {
                self.validateJSON();
            });

            $('.json-format').on('click', function() {
                self.formatJSON();
            });

            $('.json-save').on('click', function() {
                self.saveJSONChanges();
            });
        },

        validateJSON: function() {
            try {
                const json = $('#template-json-editor').val();
                JSON.parse(json);
                alert('✓ Valid JSON');
            } catch (e) {
                alert('✗ Invalid JSON: ' + e.message);
            }
        },

        formatJSON: function() {
            try {
                const json = $('#template-json-editor').val();
                const formatted = JSON.stringify(JSON.parse(json), null, 2);
                $('#template-json-editor').val(formatted);
            } catch (e) {
                alert('Cannot format invalid JSON');
            }
        },

        saveJSONChanges: function() {
            try {
                const json = $('#template-json-editor').val();
                this.templateData = JSON.parse(json);
                alert('Template updated from JSON');
            } catch (e) {
                alert('Cannot save invalid JSON');
            }
        },

        // Import/Export
        setupImportExport: function() {
            const self = this;

            // File upload area
            $('.file-upload-area').on('click', function() {
                $('#template-import-file').click();
            }).on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('drag-over');
            }).on('dragleave', function() {
                $(this).removeClass('drag-over');
            }).on('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('drag-over');
                const files = e.originalEvent.dataTransfer.files;
                if (files.length) {
                    self.importTemplateFile(files[0]);
                }
            });

            $('#template-import-file').on('change', function() {
                if (this.files.length) {
                    self.importTemplateFile(this.files[0]);
                }
            });

            $('.export-template-btn').on('click', function() {
                self.exportTemplate();
            });
        },

        importTemplateFile: function(file) {
            const reader = new FileReader();
            const self = this;

            reader.onload = function(e) {
                try {
                    const template = JSON.parse(e.target.result);
                    self.templateData = template;
                    $('#template-json-editor').val(JSON.stringify(template, null, 2));
                    alert('Template imported successfully');
                } catch (err) {
                    alert('Error importing template: ' + err.message);
                }
            };

            reader.readAsText(file);
        },

        exportTemplate: function() {
            const json = JSON.stringify(this.templateData, null, 2);
            const blob = new Blob([json], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = this.templateData.meta.id + '.template.json';
            a.click();
            URL.revokeObjectURL(url);
        },

        // Tab Navigation
        setupTabNavigation: function() {
            $('.rawwire-nav-tab').on('click', function(e) {
                e.preventDefault();
                const tab = $(this).data('tab');
                
                $('.rawwire-nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.rawwire-tab-content').removeClass('active');
                $('.rawwire-tab-content[data-tab="' + tab + '"]').addClass('active');
            });
        },

        // Generate Template
        generateTemplate: function() {
            const self = this;

            // Show loading state
            $('.wizard-next').prop('disabled', true).text('Generating...');

            // Send AJAX request to save template
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rawwire_save_template',
                    nonce: rawwireTemplateBuilder.nonce,
                    template: JSON.stringify(self.templateData)
                },
                success: function(response) {
                    if (response.success) {
                        alert('Template created successfully!');
                        window.location.href = response.data.redirect;
                    } else {
                        alert('Error creating template: ' + response.data.message);
                        $('.wizard-next').prop('disabled', false).text('Generate Template');
                    }
                },
                error: function() {
                    alert('Error creating template. Please try again.');
                    $('.wizard-next').prop('disabled', false).text('Generate Template');
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        if ($('.rawwire-templates-page').length) {
            TemplateBuilder.init();
        }
    });

})(jQuery);
