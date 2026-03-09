/**
 * Party Investigation Display - Interactive Features
 * 
 * Handles collapsible cards, copy buttons, and export functionality.
 * 
 * @package RawWire_Dashboard
 * @since 1.0.31
 */

(function () {
    'use strict';

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', initInvestigationDisplay);

    function initInvestigationDisplay() {
        const investigations = document.querySelectorAll('.rw-investigation');

        investigations.forEach(investigation => {
            initCollapsibleCards(investigation);
            initCopyButtons(investigation);
            initExportButtons(investigation);
        });
    }

    /**
     * Collapsible category cards
     */
    function initCollapsibleCards(container) {
        const toggles = container.querySelectorAll('.rw-investigation__card-toggle');

        toggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                const card = toggle.closest('.rw-investigation__card');
                const body = card.querySelector('.rw-investigation__card-body');
                const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

                toggle.setAttribute('aria-expanded', !isExpanded);

                if (isExpanded) {
                    body.style.maxHeight = body.scrollHeight + 'px';
                    body.offsetHeight; // Force reflow
                    body.style.maxHeight = '0';
                    body.style.paddingTop = '0';
                    body.style.paddingBottom = '0';
                    body.style.overflow = 'hidden';
                } else {
                    body.style.maxHeight = body.scrollHeight + 'px';
                    body.style.paddingTop = '';
                    body.style.paddingBottom = '';
                    body.style.overflow = '';

                    // Reset after animation
                    setTimeout(() => {
                        body.style.maxHeight = '400px';
                    }, 300);
                }
            });
        });
    }

    /**
     * Copy to clipboard buttons
     */
    function initCopyButtons(container) {
        const copyButtons = container.querySelectorAll('.rw-entry__copy, .rw-investigation__copy');

        copyButtons.forEach(btn => {
            btn.addEventListener('click', async () => {
                const textToCopy = btn.dataset.copy || getInvestigationSummary(container);

                try {
                    await navigator.clipboard.writeText(textToCopy);
                    showToast('Copied to clipboard!', 'success');

                    // Visual feedback
                    const icon = btn.querySelector('.dashicons');
                    if (icon) {
                        icon.classList.remove('dashicons-clipboard');
                        icon.classList.add('dashicons-yes');
                        setTimeout(() => {
                            icon.classList.remove('dashicons-yes');
                            icon.classList.add('dashicons-clipboard');
                        }, 1500);
                    }
                } catch (err) {
                    showToast('Failed to copy', 'error');
                }
            });
        });
    }

    /**
     * Export buttons (PDF, JSON)
     */
    function initExportButtons(container) {
        const exportButtons = container.querySelectorAll('.rw-investigation__export');

        exportButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const format = btn.dataset.format;
                const data = extractInvestigationData(container);

                if (format === 'json') {
                    downloadJSON(data);
                } else if (format === 'pdf') {
                    // For PDF, we'll use print styling
                    window.print();
                }
            });
        });
    }

    /**
     * Extract investigation data from DOM
     */
    function extractInvestigationData(container) {
        const data = {
            id: container.dataset.investigationId,
            exported_at: new Date().toISOString(),
            company_overview: {},
            contacts: [],
            projects: [],
            relationships: [],
            gatherings: [],
            affiliations: [],
            community: [],
            edge_intel: [],
            entry_points: []
        };

        // Company info from header
        const companyName = container.querySelector('.rw-investigation__company-name');
        if (companyName) {
            data.company_overview.name = companyName.textContent.trim();
        }

        // Extract entries from each category
        const cards = container.querySelectorAll('.rw-investigation__card');
        cards.forEach(card => {
            const category = card.dataset.category;
            if (!category || !data[category]) return;

            const entries = card.querySelectorAll('.rw-entry');
            entries.forEach(entry => {
                const title = entry.querySelector('.rw-entry__title');
                const value = entry.querySelector('.rw-entry__value');
                const confidence = entry.classList.contains('rw-entry--confirmed') ? 'confirmed'
                    : entry.classList.contains('rw-entry--inferred') ? 'inferred'
                        : 'speculative';

                data[category].push({
                    title: title ? title.textContent.trim() : '',
                    value: value ? value.textContent.trim() : '',
                    confidence: confidence
                });
            });
        });

        // Extract entry points
        const entryPoints = container.querySelectorAll('.rw-entry-point');
        entryPoints.forEach(ep => {
            const approach = ep.querySelector('.rw-entry-point__approach');
            const target = ep.querySelector('.rw-entry-point__target');
            const timing = ep.querySelector('.rw-entry-point__timing');
            const angle = ep.querySelector('.rw-entry-point__angle');

            data.entry_points.push({
                approach: approach ? approach.textContent.trim() : '',
                target: target ? target.textContent.replace('Target:', '').trim() : '',
                timing: timing ? timing.textContent.replace('When:', '').trim() : '',
                angle: angle ? angle.textContent.trim() : ''
            });
        });

        return data;
    }

    /**
     * Generate text summary for clipboard
     */
    function getInvestigationSummary(container) {
        const data = extractInvestigationData(container);
        let summary = [];

        summary.push(`=== ${data.company_overview.name || 'Investigation'} ===`);
        summary.push(`Exported: ${new Date().toLocaleDateString()}`);
        summary.push('');

        const categories = [
            ['contacts', 'CONTACTS'],
            ['projects', 'PROJECTS'],
            ['relationships', 'RELATIONSHIPS'],
            ['gatherings', 'GATHERINGS'],
            ['affiliations', 'AFFILIATIONS'],
            ['community', 'COMMUNITY'],
            ['edge_intel', 'EDGE INTEL']
        ];

        categories.forEach(([key, label]) => {
            if (data[key] && data[key].length > 0) {
                summary.push(`--- ${label} ---`);
                data[key].forEach(entry => {
                    const conf = entry.confidence === 'confirmed' ? '[✓]'
                        : entry.confidence === 'inferred' ? '[~]' : '[?]';
                    summary.push(`${conf} ${entry.title}: ${entry.value}`);
                });
                summary.push('');
            }
        });

        if (data.entry_points && data.entry_points.length > 0) {
            summary.push('--- RECOMMENDED ENTRY POINTS ---');
            data.entry_points.forEach((ep, i) => {
                summary.push(`${i + 1}. ${ep.approach}`);
                summary.push(`   Target: ${ep.target}`);
                if (ep.timing) summary.push(`   When: ${ep.timing}`);
                summary.push(`   Angle: ${ep.angle}`);
                summary.push('');
            });
        }

        return summary.join('\n');
    }

    /**
     * Download data as JSON file
     */
    function downloadJSON(data) {
        const filename = `investigation-${data.company_overview.name || 'export'}-${Date.now()}.json`;
        const jsonStr = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonStr], { type: 'application/json' });
        const url = URL.createObjectURL(blob);

        const link = document.createElement('a');
        link.href = url;
        link.download = filename.replace(/[^a-z0-9.-]/gi, '-');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        showToast('JSON exported!', 'success');
    }

    /**
     * Show toast notification
     */
    function showToast(message, type = 'info') {
        // Remove existing toasts
        document.querySelectorAll('.rw-toast').forEach(t => t.remove());

        const toast = document.createElement('div');
        toast.className = `rw-toast rw-toast--${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 24px;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
            color: white;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 2500);
    }

    // Add animation keyframes
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100px); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

})();
