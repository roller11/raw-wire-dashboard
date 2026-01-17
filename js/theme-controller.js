/**
 * RawWire Theme Controller
 * Manages light/dark mode toggle with system preference detection
 * 
 * @version 1.0.0
 * @since 1.0.24
 */

(function() {
    'use strict';

    const STORAGE_KEY = 'rawwire-theme';
    const THEMES = {
        LIGHT: 'light',
        DARK: 'dark',
        SYSTEM: 'system'
    };

    class RawWireTheme {
        constructor() {
            this.dashboard = null;
            this.currentTheme = THEMES.SYSTEM;
            this.init();
        }

        init() {
            // Wait for DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.setup());
            } else {
                this.setup();
            }
        }

        setup() {
            this.dashboard = document.querySelector('.rawwire-dashboard');
            if (!this.dashboard) return;

            // Load saved preference
            const savedTheme = localStorage.getItem(STORAGE_KEY) || THEMES.SYSTEM;
            this.setTheme(savedTheme);

            // Listen for system preference changes
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (this.currentTheme === THEMES.SYSTEM) {
                    this.applyTheme(e.matches ? THEMES.DARK : THEMES.LIGHT);
                }
            });

            // Create theme toggle if it doesn't exist
            this.createToggle();
        }

        createToggle() {
            // Check if toggle already exists
            if (document.querySelector('.rawwire-theme-toggle')) return;

            const toggle = document.createElement('div');
            toggle.className = 'rawwire-theme-toggle';
            toggle.innerHTML = `
                <button type="button" data-theme="light" title="Light mode">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"></circle>
                        <line x1="12" y1="1" x2="12" y2="3"></line>
                        <line x1="12" y1="21" x2="12" y2="23"></line>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                        <line x1="1" y1="12" x2="3" y2="12"></line>
                        <line x1="21" y1="12" x2="23" y2="12"></line>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                    </svg>
                </button>
                <button type="button" data-theme="system" title="System preference">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </button>
                <button type="button" data-theme="dark" title="Dark mode">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                    </svg>
                </button>
            `;

            // Add click handlers
            toggle.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const theme = btn.dataset.theme;
                    this.setTheme(theme);
                });
            });

            // Find hero section or header to append toggle
            const hero = this.dashboard.querySelector('.rawwire-hero-actions');
            const header = this.dashboard.querySelector('.rawwire-dashboard-header');
            
            if (hero) {
                hero.prepend(toggle);
            } else if (header) {
                header.appendChild(toggle);
            } else {
                // Fallback: create a header area
                const headerArea = document.createElement('div');
                headerArea.className = 'rawwire-dashboard-header flex justify-end mb-4';
                headerArea.appendChild(toggle);
                this.dashboard.prepend(headerArea);
            }

            this.toggle = toggle;
            this.updateToggleState();
        }

        setTheme(theme) {
            this.currentTheme = theme;
            localStorage.setItem(STORAGE_KEY, theme);

            if (theme === THEMES.SYSTEM) {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                this.applyTheme(prefersDark ? THEMES.DARK : THEMES.LIGHT);
            } else {
                this.applyTheme(theme);
            }

            this.updateToggleState();
        }

        applyTheme(theme) {
            if (!this.dashboard) return;
            this.dashboard.setAttribute('data-theme', theme);
            
            // Dispatch custom event for other components
            this.dashboard.dispatchEvent(new CustomEvent('rawwire-theme-change', {
                detail: { theme, preference: this.currentTheme }
            }));
        }

        updateToggleState() {
            if (!this.toggle) return;

            this.toggle.querySelectorAll('button').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.theme === this.currentTheme);
            });
        }

        // Public API
        getTheme() {
            return this.currentTheme;
        }

        getAppliedTheme() {
            return this.dashboard?.getAttribute('data-theme') || THEMES.LIGHT;
        }
    }

    // Initialize and expose globally
    window.RawWireTheme = new RawWireTheme();

})();
