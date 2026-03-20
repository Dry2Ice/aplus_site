// Shared public-page runtime contract:
// - shared tokens/components belong in /assets/common.css
// - shared Tailwind theme sync and runtime token updates belong in /assets/common.js
// - HTML entry points should only keep page-exclusive composition, never duplicate the shared system
// Shared runtime helpers reused across HTML entry points.
(function initAplusCommon() {
  const SETTINGS_KEY = 'aplus_site_settings';
  const defaultSettings = { lang: 'ru', isDark: false, isAccessibility: false };

  const normalizeSettings = (settings = {}) => ({
    ...defaultSettings,
    ...(settings && typeof settings === 'object' ? settings : {})
  });

  const loadSiteSettings = () => {
    try {
      const stored = localStorage.getItem(SETTINGS_KEY);
      if (stored) return normalizeSettings(JSON.parse(stored));
    } catch (e) {
      // settings load failed — fallback to defaults
    }
    return { ...defaultSettings };
  };

  const saveSiteSettings = (settings) => {
    try {
      const normalized = normalizeSettings(settings);
      localStorage.setItem(SETTINGS_KEY, JSON.stringify(normalized));
      window.dispatchEvent(new CustomEvent('aplus:settings-changed', { detail: normalized }));
      return normalized;
    } catch (e) {
      return normalizeSettings(settings);
    }
  };

  const updateSiteSettings = (patch = {}) => {
    const current = loadSiteSettings();
    return saveSiteSettings({ ...current, ...patch });
  };

  const trackEvent = (eventName, payload = {}) => {
    try {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({ event: eventName, page: window.location.pathname, ...payload, timestamp: Date.now() });
    } catch (e) {
      // analytics push failed — silently continue
    }
  };

  window.AplusCommon = {
    SETTINGS_KEY,
    loadSiteSettings,
    saveSiteSettings,
    updateSiteSettings,
    defaultSettings,
    trackEvent
  };
})();

// Shared catalog page bootstrapping: Tailwind theme + persisted UI settings + dynamic design tokens.
(function initCatalogShared() {
  const PUBLIC_DESIGN_SETTINGS_URL = '/admin/api.php?action=public-design-settings';
  const PUBLIC_DESIGN_SETTINGS_CACHE_KEY = 'aplus_public_design_settings_v1';
  const PUBLIC_DESIGN_SETTINGS_EVENT = 'aplus:public-design-settings-changed';
  const PUBLIC_DESIGN_SETTINGS_TTL_MS = 5 * 60 * 1000;

  if (window.tailwind) {
    window.tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            sans: ['Onest', 'sans-serif'],
            display: ['Unbounded', 'sans-serif'],
          },
          colors: {
            brand: {
              purple: '#4c019c',
              'purple-light': '#6523b1',
              'purple-soft': '#bd9cf7',
              dark: '#11071e',
              milky: '#f7f2ff',
              base: '#f7f2ff',
              'base-elevated': '#efe6ff',
              accent: '#201136',
              'dark-base': '#11071e',
              'dark-card': '#1b1031',
              'dark-surface': '#1d1233',
              'dark-text': '#f7f3ff'
            }
          },
          animation: {
            float: 'float 8s ease-in-out infinite',
            fadeIn: 'fadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            slideUp: 'slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            slideIn: 'slideIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'pulse-slow': 'pulse 4s ease-in-out infinite',
            'spin-slow': 'spin 20s linear infinite',
          },
          keyframes: {
            float: {
              '0%, 100%': { transform: 'translateY(0) rotate(0deg)' },
              '33%': { transform: 'translateY(-12px) rotate(1deg)' },
              '66%': { transform: 'translateY(-6px) rotate(-1deg)' },
            },
            fadeIn: { '0%': { opacity: '0', transform: 'translateY(24px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            slideUp: { '0%': { transform: 'translateY(100%)' }, '100%': { transform: 'translateY(0)' } },
            slideIn: { '0%': { opacity: '0', transform: 'translateX(-20px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
          },
          backdropBlur: {
            xs: '2px',
          }
        }
      }
    };
  }

  (function applyStoredSettings() {
    try {
      const settings = window.AplusCommon?.loadSiteSettings ? window.AplusCommon.loadSiteSettings() : null;
      if (!settings) return;
      if (settings.isDark) document.documentElement.classList.add('dark');
      if (settings.isAccessibility) document.documentElement.classList.add('accessibility-mode');
      document.documentElement.lang = settings.lang === 'en' ? 'en' : 'ru';
    } catch (e) {
      // settings apply failed — continue with defaults
    }
  })();

  (function applyPublicDesignSettings() {
    const DEFAULT_PUBLIC_DESIGN_SETTINGS = {
      fontBody: 'Onest',
      fontDisplay: 'Unbounded',
      fontScale: 100,
      colors: {
        primary: '#4c019c',
        secondary: '#6523b1',
        tertiary: '#bd9cf7',
        background: '#f7f2ff',
        text: '#201136',
        surface: '#ffffff',
      },
      colorsDark: {
        primary: '#bd9cf7',
        secondary: '#6523b1',
        tertiary: '#4c019c',
        background: '#11071e',
        text: '#f7f3ff',
        surface: '#1d1233',
      },
      tokenMode: 'auto',
      tokens: {
        backgroundElevated: '#efe6ff',
        textMuted: '#6b5a8c',
        border: 'rgb(76 1 156 / 0.12)',
        glow: 'rgb(76 1 156 / 0.24)',
        overlay: 'rgb(247 242 255 / 0.9)',
        meshIntensity: '0.08',
      },
      tokensDark: {
        backgroundElevated: '#1b1031',
        textMuted: '#c8bae7',
        border: 'rgb(189 156 247 / 0.18)',
        glow: 'rgb(189 156 247 / 0.28)',
        overlay: 'rgb(17 7 30 / 0.9)',
        meshIntensity: '0.16',
      },
      gradients: {
        main: 'linear-gradient(135deg, #4c019c 0%, #6523b1 72%, #bd9cf7 100%)',
      },
      accessibility: {
        highContrast: false,
        reduceMotion: false,
      },
      layout: {
        homeSections: ['hero', 'stats', 'divider', 'about', 'workflow', 'products', 'principles', 'faq', 'cta'],
      },
    };
    const FONT_STACKS = {
      Onest: "'Onest', sans-serif",
      Unbounded: "'Unbounded', sans-serif",
      Inter: "'Inter', 'Onest', sans-serif",
      Roboto: "'Roboto', 'Onest', sans-serif",
      Arial: 'Arial, sans-serif',
      Georgia: 'Georgia, serif',
      Montserrat: "'Montserrat', 'Onest', sans-serif",
      Poppins: "'Poppins', 'Onest', sans-serif",
      Oswald: "'Oswald', sans-serif",
    };
    const FONT_STYLESHEET_MAP = {
      Inter: 'family=Inter:wght@300;400;500;600;700;800',
      Roboto: 'family=Roboto:wght@300;400;500;700',
      Montserrat: 'family=Montserrat:wght@400;500;600;700;800',
      Poppins: 'family=Poppins:wght@400;500;600;700',
      Oswald: 'family=Oswald:wght@400;500;600;700',
    };
    const loadedFontKeys = new Set();

    const ensureFontLoaded = (fontKey) => {
      const descriptor = FONT_STYLESHEET_MAP[fontKey];
      if (!descriptor || loadedFontKeys.has(fontKey) || document.querySelector(`link[data-font-key="${fontKey}"]`)) return;
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = `https://fonts.googleapis.com/css2?${descriptor}&display=swap`;
      link.dataset.fontKey = fontKey;
      document.head.appendChild(link);
      loadedFontKeys.add(fontKey);
    };

    const clampFontScale = (value) => `${Math.max(90, Math.min(130, Number(value) || 100))}%`;
    const clampUnit = (value, fallback) => {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) return String(fallback);
      return String(Math.max(0, Math.min(1, numeric)));
    };

    const hexToCssRgb = (value, fallback) => {
      const safe = String(value || fallback || '').trim().replace('#', '');
      const hex = /^[0-9a-fA-F]{6}$/.test(safe) ? safe : String(fallback || '000000').replace('#', '');
      return `${parseInt(hex.slice(0, 2), 16)} ${parseInt(hex.slice(2, 4), 16)} ${parseInt(hex.slice(4, 6), 16)}`;
    };
    const normalizeHex = (value, fallback) => {
      const normalized = String(value || '').trim().toLowerCase();
      return /^#[0-9a-f]{6}$/.test(normalized) ? normalized : String(fallback || '#000000').toLowerCase();
    };
    const hexToRgbObject = (value, fallback) => {
      const safe = normalizeHex(value, fallback).slice(1);
      return {
        r: parseInt(safe.slice(0, 2), 16),
        g: parseInt(safe.slice(2, 4), 16),
        b: parseInt(safe.slice(4, 6), 16),
      };
    };
    const mixHex = (base, target, ratio, fallbackBase, fallbackTarget) => {
      const from = hexToRgbObject(base, fallbackBase);
      const to = hexToRgbObject(target, fallbackTarget);
      const blend = (start, end) => Math.round(start + (end - start) * ratio);
      return `#${[blend(from.r, to.r), blend(from.g, to.g), blend(from.b, to.b)].map((part) => part.toString(16).padStart(2, '0')).join('')}`;
    };
    const rgbWithAlpha = (value, alpha, fallback) => `rgb(${hexToCssRgb(value, fallback)} / ${clampUnit(alpha, 1)})`;
    // IMPORTANT: This must stay in sync with deriveDesignTokens() in admin/api.php and deriveSemanticTokens() in admin/index.html
    const deriveSemanticTokens = (palette, isDark = false) => {
      const background = normalizeHex(palette?.background, isDark ? '#11071e' : '#f7f2ff');
      const surface = normalizeHex(palette?.surface, isDark ? '#1d1233' : '#ffffff');
      const text = normalizeHex(palette?.text, isDark ? '#f7f3ff' : '#201136');
      const primary = normalizeHex(palette?.primary, isDark ? '#bd9cf7' : '#4c019c');
      const secondary = normalizeHex(palette?.secondary, '#6523b1');

      return {
        backgroundElevated: isDark
          ? mixHex(background, secondary, 0.22, '#11071e', '#6523b1')
          : mixHex(background, secondary, 0.08, '#f7f2ff', '#6523b1'),
        textMuted: isDark
          ? mixHex(text, background, 0.22, '#f7f3ff', '#11071e')
          : mixHex(text, background, 0.32, '#201136', '#f7f2ff'),
        border: rgbWithAlpha(primary, isDark ? 0.18 : 0.12, isDark ? '#bd9cf7' : '#4c019c'),
        glow: rgbWithAlpha(primary, isDark ? 0.28 : 0.24, isDark ? '#bd9cf7' : '#4c019c'),
        overlay: rgbWithAlpha(background, 0.9, isDark ? '#11071e' : '#f7f2ff'),
        meshIntensity: clampUnit(palette?.meshIntensity ?? (isDark ? 0.16 : 0.08), isDark ? 0.16 : 0.08),
        surface,
        background,
        text,
      };
    };
    const mergePublicDesignSettings = (settings = {}) => {
      const merged = {
        ...DEFAULT_PUBLIC_DESIGN_SETTINGS,
        ...(settings && typeof settings === 'object' ? settings : {}),
      };
      merged.colors = { ...DEFAULT_PUBLIC_DESIGN_SETTINGS.colors, ...((settings || {}).colors || {}) };
      merged.colorsDark = { ...DEFAULT_PUBLIC_DESIGN_SETTINGS.colorsDark, ...((settings || {}).colorsDark || {}) };
      merged.gradients = { ...DEFAULT_PUBLIC_DESIGN_SETTINGS.gradients, ...((settings || {}).gradients || {}) };
      merged.accessibility = { ...DEFAULT_PUBLIC_DESIGN_SETTINGS.accessibility, ...((settings || {}).accessibility || {}) };
      merged.layout = { ...DEFAULT_PUBLIC_DESIGN_SETTINGS.layout, ...((settings || {}).layout || {}) };
      merged.tokenMode = 'auto';
      merged.tokens = deriveSemanticTokens({ ...merged.colors, ...((settings || {}).tokens || {}) }, false);
      merged.tokensDark = deriveSemanticTokens({ ...merged.colorsDark, ...((settings || {}).tokensDark || {}) }, true);
      return merged;
    };

    const applySharedDesignSettings = (settings) => {
      const resolvedSettings = mergePublicDesignSettings(settings);
      const colors = resolvedSettings.colors || {};
      const colorsDark = resolvedSettings.colorsDark || {};
      const tokens = resolvedSettings.tokens || deriveSemanticTokens(colors, false);
      const tokensDark = resolvedSettings.tokensDark || deriveSemanticTokens(colorsDark, true);
      const root = document.documentElement;

      root.style.setProperty('--purple-rgb', hexToCssRgb(colors.primary || '#4c019c', '#4c019c'));
      root.style.setProperty('--purple-light-rgb', hexToCssRgb(colors.secondary || '#6523b1', '#6523b1'));
      root.style.setProperty('--purple-soft-rgb', hexToCssRgb(colors.tertiary || '#bd9cf7', '#bd9cf7'));
      root.style.setProperty('--purple', colors.primary || '#4c019c');
      root.style.setProperty('--purple-light', colors.secondary || '#6523b1');
      root.style.setProperty('--purple-soft', colors.tertiary || '#bd9cf7');
      root.style.setProperty('--site-bg', colors.background || '#f7f2ff');
      root.style.setProperty('--site-bg-elevated', tokens.backgroundElevated);
      root.style.setProperty('--site-text', colors.text || '#201136');
      root.style.setProperty('--site-text-muted', tokens.textMuted);
      root.style.setProperty('--site-surface', colors.surface || '#ffffff');
      root.style.setProperty('--site-border', tokens.border);
      root.style.setProperty('--site-glow', tokens.glow);
      root.style.setProperty('--site-overlay', tokens.overlay);
      root.style.setProperty('--site-mesh-intensity', tokens.meshIntensity);
      root.style.setProperty('--mobile-menu-bg', `rgb(${hexToCssRgb(colors.background || '#f7f2ff', '#f7f2ff')} / 0.98)`);
      root.style.setProperty('--mobile-menu-bg-dark', `rgb(${hexToCssRgb(colorsDark.background || '#11071e', '#11071e')} / 0.98)`);
      root.style.setProperty('--purple-dark', colorsDark.primary || '#bd9cf7');
      root.style.setProperty('--purple-light-dark', colorsDark.secondary || '#6523b1');
      root.style.setProperty('--purple-soft-dark', colorsDark.tertiary || '#4c019c');
      root.style.setProperty('--site-bg-dark', colorsDark.background || '#11071e');
      root.style.setProperty('--site-bg-dark-elevated', tokensDark.backgroundElevated);
      root.style.setProperty('--site-text-dark', colorsDark.text || '#f7f3ff');
      root.style.setProperty('--site-text-dark-muted', tokensDark.textMuted);
      root.style.setProperty('--site-surface-dark', colorsDark.surface || '#1d1233');
      root.style.setProperty('--site-border-dark', tokensDark.border);
      root.style.setProperty('--site-glow-dark', tokensDark.glow);
      root.style.setProperty('--site-overlay-dark', tokensDark.overlay);
      root.style.setProperty('--site-mesh-intensity-dark', tokensDark.meshIntensity);

      const gradientMain = resolvedSettings.gradients?.main || DEFAULT_PUBLIC_DESIGN_SETTINGS.gradients.main;
      root.style.setProperty('--gradient-main', gradientMain);
      root.style.setProperty('--gradient-surface', `linear-gradient(180deg, rgb(${hexToCssRgb(colors.surface || '#ffffff', '#ffffff')} / 0.96) 0%, rgb(${hexToCssRgb(tokens.backgroundElevated, '#efe6ff')} / 0.72) 100%)`);
      root.style.setProperty('--gradient-surface-dark', `linear-gradient(180deg, rgb(${hexToCssRgb(tokensDark.backgroundElevated, '#1b1031')} / 0.92) 0%, rgb(${hexToCssRgb(colorsDark.surface || '#1d1233', '#1d1233')} / 0.96) 100%)`);
      root.style.setProperty('--gradient-section-dark', `linear-gradient(135deg, ${colorsDark.background || '#11071e'} 0%, ${tokensDark.backgroundElevated} 52%, rgb(${hexToCssRgb(colorsDark.primary || '#bd9cf7', '#bd9cf7')} / 0.84) 100%)`);
      root.style.setProperty('--hero-mesh-light', `radial-gradient(ellipse 80% 60% at 20% 30%, rgb(${hexToCssRgb(colors.primary || '#4c019c', '#4c019c')} / ${tokens.meshIntensity}) 0%, transparent 60%), radial-gradient(ellipse 60% 50% at 80% 70%, rgb(${hexToCssRgb(colors.secondary || '#6523b1', '#6523b1')} / ${clampUnit(Number(tokens.meshIntensity) * 0.88, 0.07)}) 0%, rgb(${hexToCssRgb(colors.tertiary || '#bd9cf7', '#bd9cf7')} / ${clampUnit(Number(tokens.meshIntensity) * 0.88, 0.07)}) 38%, transparent 68%)`);
      root.style.setProperty('--hero-mesh-dark', `radial-gradient(ellipse 80% 60% at 20% 30%, rgb(${hexToCssRgb(colorsDark.primary || '#bd9cf7', '#bd9cf7')} / ${tokensDark.meshIntensity}) 0%, transparent 60%), radial-gradient(ellipse 60% 50% at 80% 70%, rgb(${hexToCssRgb(colorsDark.secondary || '#6523b1', '#6523b1')} / ${clampUnit(Number(tokensDark.meshIntensity) * 0.75, 0.12)}) 0%, rgb(${hexToCssRgb(colorsDark.tertiary || '#4c019c', '#4c019c')} / ${clampUnit(Number(tokensDark.meshIntensity) * 0.62, 0.1)}) 38%, transparent 68%)`);
      root.style.setProperty('--product-glow-light', `radial-gradient(ellipse at center, rgb(${hexToCssRgb(colors.primary || '#4c019c', '#4c019c')} / 0.08) 0%, rgb(${hexToCssRgb(colors.tertiary || '#bd9cf7', '#bd9cf7')} / 0.05) 34%, transparent 72%)`);
      root.style.setProperty('--product-glow-dark', `radial-gradient(ellipse at center, rgb(${hexToCssRgb(colorsDark.primary || '#bd9cf7', '#bd9cf7')} / 0.16) 0%, rgb(${hexToCssRgb(colorsDark.tertiary || '#4c019c', '#4c019c')} / 0.08) 38%, transparent 74%)`);
      if (resolvedSettings.fontScale) root.style.setProperty('--font-scale', clampFontScale(resolvedSettings.fontScale));

      // Update theme-color meta tag dynamically
      const themeMeta = document.querySelector('meta[name="theme-color"]');
      if (themeMeta) {
        themeMeta.setAttribute('content', colors.secondary || '#6523b1');
      }

      ensureFontLoaded(resolvedSettings.fontBody);
      ensureFontLoaded(resolvedSettings.fontDisplay);
      root.style.setProperty('--font-body', FONT_STACKS[resolvedSettings.fontBody] || FONT_STACKS.Onest);
      root.style.setProperty('--font-display', FONT_STACKS[resolvedSettings.fontDisplay] || FONT_STACKS.Unbounded);
      root.classList.toggle('site-high-contrast', Boolean(resolvedSettings.accessibility?.highContrast));
      root.classList.toggle('site-reduce-motion', Boolean(resolvedSettings.accessibility?.reduceMotion));
      return resolvedSettings;
    };

    window.AplusCommon = {
      ...(window.AplusCommon || {}),
      PUBLIC_DESIGN_SETTINGS_DEFAULTS: mergePublicDesignSettings(DEFAULT_PUBLIC_DESIGN_SETTINGS),
      mergePublicDesignSettings,
      applyPublicDesignSettings: applySharedDesignSettings,
      derivePublicSemanticTokens: deriveSemanticTokens,
    };

    const readCachedDesignSettings = () => {
      try {
        const raw = localStorage.getItem(PUBLIC_DESIGN_SETTINGS_CACHE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed?.settings || !parsed.cachedAt) return null;
        return parsed;
      } catch {
        return null;
      }
    };

    const writeCachedDesignSettings = (settings) => {
      const payload = {
        cachedAt: Date.now(),
        settings,
      };

      try {
        localStorage.setItem(PUBLIC_DESIGN_SETTINGS_CACHE_KEY, JSON.stringify(payload));
      } catch {
        // Ignore quota/privacy mode errors and continue with live settings.
      }

      try {
        window.dispatchEvent(new CustomEvent(PUBLIC_DESIGN_SETTINGS_EVENT, { detail: payload }));
      } catch {
        // Ignore CustomEvent issues in legacy contexts.
      }
    };

    const syncIncomingDesignSettings = (payload) => {
      const settings = payload?.settings || payload;
      if (!settings || typeof settings !== 'object') return;
      applySharedDesignSettings(settings);
    };

    window.addEventListener(PUBLIC_DESIGN_SETTINGS_EVENT, (event) => {
      syncIncomingDesignSettings(event?.detail);
    });

    window.addEventListener('storage', (event) => {
      if (event.key !== PUBLIC_DESIGN_SETTINGS_CACHE_KEY || !event.newValue) return;
      try {
        syncIncomingDesignSettings(JSON.parse(event.newValue));
      } catch {
        // Ignore malformed cross-tab cache payloads.
      }
    });

    const cached = readCachedDesignSettings();
    const hasFreshCache = cached && (Date.now() - cached.cachedAt) < PUBLIC_DESIGN_SETTINGS_TTL_MS;
    if (cached?.settings) {
      applySharedDesignSettings(cached.settings);
      if (hasFreshCache) return;
    }

    fetch(PUBLIC_DESIGN_SETTINGS_URL, { credentials: 'same-origin' })
      .then((res) => (res.ok ? res.json() : null))
      .then((res) => {
        if (!res?.ok || !res.settings) return;
        applySharedDesignSettings(res.settings);
        writeCachedDesignSettings(res.settings);
      })
      .catch(() => {});
  })();
})();
