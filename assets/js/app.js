/**
 * Gestion de Pagos - Shared Application Module
 * Handles: i18n, theme (dark/light), Iconify icons
 */

const App = (function() {
    'use strict';

    // ==========================================
    // I18N - Internationalization
    // ==========================================
    const i18n = {
        currentLang: 'es',
        translations: {},
        supportedLangs: ['es', 'en'],

        /**
         * Detect browser/OS language
         */
        detectLanguage() {
            const saved = localStorage.getItem('appLang');
            if (saved && this.supportedLangs.includes(saved)) {
                return saved;
            }

            const browserLang = (navigator.language || navigator.userLanguage || 'es').split('-')[0].toLowerCase();
            return this.supportedLangs.includes(browserLang) ? browserLang : 'es';
        },

        /**
         * Load translation file
         */
        async load(lang) {
            try {
                const response = await fetch(`assets/lang/${lang}.json`);
                if (response.ok) {
                    this.translations = await response.json();
                    this.currentLang = lang;
                    localStorage.setItem('appLang', lang);
                    document.documentElement.lang = lang;
                    return true;
                }
            } catch (e) {
                console.warn(`Failed to load language: ${lang}`, e);
            }
            return false;
        },

        /**
         * Get translation by dot-notation key
         * e.g., t('dashboard.loading') => 'Cargando...'
         */
        t(key, replacements) {
            const keys = key.split('.');
            let value = this.translations;

            for (const k of keys) {
                if (value && typeof value === 'object' && k in value) {
                    value = value[k];
                } else {
                    return key; // Return key as fallback
                }
            }

            if (typeof value === 'string' && replacements) {
                for (const [rKey, rVal] of Object.entries(replacements)) {
                    value = value.replace(`{${rKey}}`, rVal);
                }
            }

            return value;
        },

        /**
         * Apply translations to all elements with data-i18n attribute
         */
        applyToDOM() {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                const translated = this.t(key);
                if (translated !== key) {
                    el.textContent = translated;
                }
            });

            // Handle placeholders
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                const translated = this.t(key);
                if (translated !== key) {
                    el.placeholder = translated;
                }
            });

            // Handle titles
            document.querySelectorAll('[data-i18n-title]').forEach(el => {
                const key = el.getAttribute('data-i18n-title');
                const translated = this.t(key);
                if (translated !== key) {
                    el.title = translated;
                }
            });
        },

        /**
         * Initialize i18n
         */
        async init() {
            const lang = this.detectLanguage();
            await this.load(lang);
            this.applyToDOM();
        },

        /**
         * Switch language (programmatic only, no UI button)
         */
        async switchTo(lang) {
            if (await this.load(lang)) {
                this.applyToDOM();
                document.dispatchEvent(new CustomEvent('langChanged', { detail: { lang } }));
            }
        }
    };

    // ==========================================
    // THEME - Dark/Light Mode
    // ==========================================
    const theme = {
        current: 'system', // 'light', 'dark', 'system'

        /**
         * Detect system preference
         */
        getSystemPreference() {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        },

        /**
         * Get resolved theme (actual light/dark value)
         */
        getResolved() {
            if (this.current === 'system') {
                return this.getSystemPreference();
            }
            return this.current;
        },

        /**
         * Apply theme to DOM
         */
        apply() {
            const resolved = this.getResolved();
            document.documentElement.setAttribute('data-theme', resolved);
            // Clean any leftover inline --bg-main from old code or cache
            document.documentElement.style.removeProperty('--bg-main');
            document.documentElement.style.removeProperty('--bg-card');
            document.documentElement.style.removeProperty('--text-primary');
            document.documentElement.style.removeProperty('--text-secondary');
            document.documentElement.style.removeProperty('--border');
            document.documentElement.style.removeProperty('--input-bg');
            this.renderToggle();
        },

        /**
         * Initialize theme
         */
        init() {
            const saved = localStorage.getItem('appThemeMode');
            this.current = saved || 'system';
            this.apply();

            // Listen for system theme changes
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                if (this.current === 'system') {
                    this.apply();
                }
            });
        },

        /**
         * Set theme mode directly
         */
        setMode(mode) {
            this.current = mode;
            localStorage.setItem('appThemeMode', mode);
            this.apply();
        },

        /**
         * Render 3 theme toggle buttons (light / system / dark)
         */
        renderToggle() {
            const container = document.getElementById('themeToggle');
            if (!container) return;

            const modes = [
                { key: 'light',  icon: 'mdi:white-balance-sunny', label: i18n.t('nav.theme_light') },
                { key: 'system', icon: 'mdi:monitor',             label: i18n.t('nav.theme_system') },
                { key: 'dark',   icon: 'mdi:moon-waning-crescent', label: i18n.t('nav.theme_dark') }
            ];

            container.innerHTML = modes.map(m =>
                `<button onclick="App.theme.setMode('${m.key}')" class="theme-toggle-btn ${this.current === m.key ? 'active' : ''}" title="${m.label}">
                    <iconify-icon icon="${m.icon}" width="18" height="18"></iconify-icon>
                </button>`
            ).join('');
        }
    };

    // ==========================================
    // ICONS - Iconify MDI mapping
    // ==========================================
    const icons = {
        /**
         * Service icon map (service name -> MDI icon)
         */
        serviceMap: {
            'limpieza': 'mdi:broom',
            'jardinero': 'mdi:flower',
            'jardineria': 'mdi:flower',
            'pintor': 'mdi:palette',
            'albanil': 'mdi:wall',
            'albañil': 'mdi:wall',
            'electricista': 'mdi:flash',
            'fontanero': 'mdi:wrench',
            'luz': 'mdi:lightbulb',
            'gas': 'mdi:fire',
            'agua': 'mdi:water',
            'internet': 'mdi:access-point'
        },

        /**
         * Get Iconify element for a service
         */
        getServiceIcon(name) {
            const key = name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const icon = this.serviceMap[key] || 'mdi:hammer-wrench';
            return `<iconify-icon icon="${icon}" width="20" height="20"></iconify-icon>`;
        },

        /**
         * Generic icon helper
         */
        get(name, size) {
            size = size || 20;
            return `<iconify-icon icon="${name}" width="${size}" height="${size}"></iconify-icon>`;
        }
    };

    // ==========================================
    // COLOR THEME (custom colors from admin)
    // ==========================================
    const colorTheme = {
        defaults: {
            primary: '#3C3C3C',
            secondary: '#E8750A',
            accent: '#C97064',
            success: '#4A9B8E',
            danger: '#C97064',
            background: '#F9F7F4'
        },

        /**
         * Load custom color theme
         */
        load() {
            try {
                const localTheme = localStorage.getItem('appTheme');
                if (localTheme) {
                    this.apply(JSON.parse(localTheme));
                    return;
                }

                fetch('data/theme.json')
                    .then(response => response.ok ? response.json() : null)
                    .then(themeData => {
                        if (themeData) {
                            this.apply(themeData);
                            localStorage.setItem('appTheme', JSON.stringify(themeData));
                        }
                    })
                    .catch(() => {});
            } catch (e) {
                console.log('Using default color theme');
            }
        },

        /**
         * Apply color theme
         */
        apply(themeData) {
            const root = document.documentElement;

            if (themeData.primary) {
                root.style.setProperty('--primary', themeData.primary);
                // Calculate primary-light
                const r = parseInt(themeData.primary.substr(1, 2), 16);
                const g = parseInt(themeData.primary.substr(3, 2), 16);
                const b = parseInt(themeData.primary.substr(5, 2), 16);
                const lighter = `rgb(${Math.min(r + 30, 255)}, ${Math.min(g + 30, 255)}, ${Math.min(b + 30, 255)})`;
                root.style.setProperty('--primary-light', lighter);
            }
            if (themeData.secondary) root.style.setProperty('--secondary', themeData.secondary);
            if (themeData.accent) root.style.setProperty('--accent', themeData.accent);
            if (themeData.success) root.style.setProperty('--success', themeData.success);
            if (themeData.danger) root.style.setProperty('--danger', themeData.danger);
            // Note: --bg-main is NOT set here. Background is controlled
            // exclusively by the light/dark mode CSS variables in common.css.
        }
    };

    // ==========================================
    // INITIALIZATION
    // ==========================================
    async function init() {
        // 0. Clean any stale inline styles from old code/cache
        const root = document.documentElement;
        ['--bg-main', '--bg-card', '--text-primary', '--text-secondary',
         '--border', '--input-bg', '--card-border'].forEach(prop => {
            root.style.removeProperty(prop);
        });

        // 1. Apply dark/light theme first (instant, no flicker)
        theme.init();

        // 2. Load custom color theme (accent colors only)
        colorTheme.load();

        // 3. Load i18n (async)
        await i18n.init();
    }

    // ==========================================
    // PUBLIC API
    // ==========================================
    return {
        init,
        i18n,
        theme,
        icons,
        colorTheme,
        t: (key, replacements) => i18n.t(key, replacements)
    };

})();
