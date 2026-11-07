/*
 * The Floor shell behaviours. Plain JavaScript on purpose: it works on every page, with or without
 * Livewire's bundled Alpine, and every control it enhances is a real link or button first.
 *
 *  - disclosure menus      [data-pk-toggle="id"] toggles #id (hidden attribute + aria-expanded)
 *  - dialogs               [data-pk-dialog="id"] opens <dialog id>, [data-pk-close] closes its dialog
 *  - context drawer        [data-pk-context-open] / [data-pk-context-close] below 1200 px
 *  - theme                 [data-pk-theme] toggles light and dark, remembered in localStorage
 *  - tabs                  [data-pk-tabs] with [data-pk-tab] buttons and [data-pk-panel] panels
 *  - sync meter            draws once on first view; complete under reduced motion or without scripting
 *  - badges                polls GET /api/pulse/pulse-check every 30 s while the page is visible
 *  - toasts                window.pulseToast(message, {kind}) and the Livewire "toast" event
 */

const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/* ---------- Theme ---------- */
const THEME_KEY = 'pulse-theme';

function effectiveTheme() {
    const set = document.documentElement.dataset.theme;
    if (set === 'light' || set === 'dark') return set;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function syncThemeButtons() {
    const current = effectiveTheme();
    $$('[data-pk-theme]').forEach((button) => {
        button.setAttribute('aria-pressed', current === 'dark' ? 'true' : 'false');
        button.setAttribute('aria-label', current === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    });
}

function toggleTheme() {
    const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try {
        localStorage.setItem(THEME_KEY, next);
    } catch (e) {
        /* private window: the choice lasts for this page only */
    }
    syncThemeButtons();
}

/* ---------- Disclosure menus ---------- */
function closeMenus(except) {
    $$('[data-pk-toggle][aria-expanded="true"]').forEach((trigger) => {
        if (trigger === except) return;
        const panel = document.getElementById(trigger.getAttribute('data-pk-toggle'));
        trigger.setAttribute('aria-expanded', 'false');
        if (panel) panel.hidden = true;
    });
}

function toggleMenu(trigger) {
    const panel = document.getElementById(trigger.getAttribute('data-pk-toggle'));
    if (!panel) return;
    const open = trigger.getAttribute('aria-expanded') === 'true';
    closeMenus(open ? null : trigger);
    trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
    panel.hidden = open;
}

/* ---------- Context drawer ---------- */
let scrim = null;

function setContext(open) {
    const drawer = document.getElementById('sh-context');
    if (!drawer) return;
    drawer.classList.toggle('is-open', open);
    $$('[data-pk-context-open]').forEach((b) => b.setAttribute('aria-expanded', open ? 'true' : 'false'));
    if (open && !scrim) {
        scrim = document.createElement('div');
        scrim.className = 'sh-scrim';
        scrim.addEventListener('click', () => setContext(false));
        document.body.appendChild(scrim);
    } else if (!open && scrim) {
        scrim.remove();
        scrim = null;
    }
    if (open) {
        const first = drawer.querySelector('button, a, input, select, textarea');
        if (first) first.focus();
    }
}

/* ---------- Tabs ---------- */
function selectTab(tab) {
    const root = tab.closest('[data-pk-tabs]');
    if (!root) return;
    $$('[data-pk-tab]', root).forEach((t) => {
        const on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
    });
    $$('[data-pk-panel]', root).forEach((p) => {
        p.hidden = p.getAttribute('data-pk-panel') !== tab.getAttribute('data-pk-tab');
    });
}

/* ---------- Toasts ---------- */
let toastTimer = new WeakMap();

window.pulseToast = function pulseToast(message, options = {}) {
    let region = $('.pk-toasts');
    if (!region) {
        region = document.createElement('div');
        region.className = 'pk-toasts';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
    }
    const toast = document.createElement('div');
    toast.className = 'pk-toast' + (options.kind ? ' pk-toast--' + options.kind : '');
    const text = document.createElement('span');
    text.textContent = String(message);
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    close.addEventListener('click', () => toast.remove());
    toast.append(text, close);
    region.appendChild(toast);
    while (region.children.length > 3) region.firstElementChild.remove();
    const timer = setTimeout(() => toast.remove(), options.timeout || 6000);
    toastTimer.set(toast, timer);
};

/* ---------- Sync meter: draw once on first view ---------- */
let meterObserver = null;

function prepareMeters(root = document) {
    const meters = $$('[data-pk-meter]:not([data-pk-ready])', root);
    if (!meters.length) return;
    meters.forEach((el) => el.setAttribute('data-pk-ready', ''));
    if (reduceMotion() || !('IntersectionObserver' in window)) return; // drawn complete
    if (!meterObserver) {
        meterObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    const el = entry.target;
                    meterObserver.unobserve(el);
                    el.classList.add('is-drawn');
                    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.remove('is-pending')));
                });
            },
            { threshold: 0.1 },
        );
    }
    meters.forEach((el) => {
        el.classList.add('is-pending');
        meterObserver.observe(el);
    });
}

/* ---------- Badges: short poll ---------- */
const POLL_MS = 30000;
let pollTimer = null;
let pollFailures = 0;
let pollStopped = false;
let lastPoll = 0;

function setBadge(kind, count) {
    $$('[data-pulse-badge="' + kind + '"]').forEach((el) => {
        const n = Number(count) || 0;
        el.textContent = n > 99 ? '99+' : String(n);
        el.hidden = n < 1;
        const label = el.getAttribute('data-label');
        const host = el.closest('a, button');
        if (host && label) {
            host.setAttribute('aria-label', n > 0 ? label + ', ' + n + ' new' : label);
        }
    });
}

async function poll() {
    if (pollStopped || document.hidden) return;
    const url = document.body.getAttribute('data-pulse-check');
    if (!url) return;
    lastPoll = Date.now();
    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (response.status === 404 || response.status === 401 || response.status === 403) {
            pollStopped = true; // the endpoint is not there (yet) or the session ended: stay quiet
            return;
        }
        if (!response.ok) throw new Error('status ' + response.status);
        const data = await response.json();
        pollFailures = 0;
        setBadge('lines', (Number(data.lines_unread) || 0) + (Number(data.requests) || 0));
        setBadge('notifications', data.notifications);
    } catch (e) {
        pollFailures += 1; // network or server trouble: back off, never complain
    }
}

function schedulePoll() {
    clearTimeout(pollTimer);
    if (pollStopped) return;
    const wait = Math.min(POLL_MS * Math.pow(2, Math.min(pollFailures, 3)), 300000);
    pollTimer = setTimeout(async () => {
        await poll();
        schedulePoll();
    }, wait);
}

/* ---------- Wiring ---------- */
document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const themeButton = target.closest('[data-pk-theme]');
    if (themeButton) {
        toggleTheme();
        return;
    }

    const toggle = target.closest('[data-pk-toggle]');
    if (toggle) {
        event.preventDefault();
        toggleMenu(toggle);
        return;
    }

    const dialogOpen = target.closest('[data-pk-dialog]');
    if (dialogOpen) {
        const dialog = document.getElementById(dialogOpen.getAttribute('data-pk-dialog'));
        if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
            event.preventDefault();
            closeMenus(null);
            dialog.showModal();
        }
        return;
    }

    const dialogClose = target.closest('[data-pk-close]');
    if (dialogClose) {
        const dialog = dialogClose.closest('dialog');
        if (dialog) dialog.close();
        return;
    }

    if (target.matches('dialog.pk-dialog')) {
        target.close(); // a click on the backdrop area
        return;
    }

    if (target.closest('[data-pk-context-open]')) {
        setContext(true);
        return;
    }
    if (target.closest('[data-pk-context-close]')) {
        setContext(false);
        return;
    }

    const tab = target.closest('[data-pk-tab]');
    if (tab) {
        selectTab(tab);
        return;
    }

    // Click outside any open menu closes it.
    if (!target.closest('.sh-menuwrap')) closeMenus(null);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        const open = $('[data-pk-toggle][aria-expanded="true"]');
        if (open) {
            closeMenus(null);
            open.focus();
        }
        const drawer = document.getElementById('sh-context');
        if (drawer && drawer.classList.contains('is-open')) setContext(false);
        return;
    }

    const tab = event.target instanceof Element ? event.target.closest('[data-pk-tab]') : null;
    if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        const tabs = $$('[data-pk-tab]', tab.closest('[data-pk-tabs]'));
        let index = tabs.indexOf(tab);
        if (event.key === 'ArrowRight') index = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') index = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') index = 0;
        if (event.key === 'End') index = tabs.length - 1;
        event.preventDefault();
        tabs[index].focus();
        selectTab(tabs[index]);
    }
});

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearTimeout(pollTimer);
        return;
    }
    if (Date.now() - lastPoll > POLL_MS) poll();
    schedulePoll();
});

window.addEventListener('pulse-toast', (event) => {
    const detail = event.detail || {};
    window.pulseToast(detail.message || '', detail);
});

document.addEventListener('livewire:init', () => {
    if (!window.Livewire) return;

    window.Livewire.on('toast', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        if (data && data.message) window.pulseToast(data.message, data);
    });

    // Rate-limited actions answer HTTP 429. Livewire would show its raw error overlay; say it in a toast.
    window.Livewire.hook('request', ({ respond, fail }) => {
        let retryAfter = null;
        respond(({ response }) => {
            retryAfter = response && response.headers ? response.headers.get('Retry-After') : null;
        });
        fail(({ status, preventDefault }) => {
            if (status !== 429) return;
            preventDefault();
            window.pulseToast(
                retryAfter
                    ? 'Slow down. Try again in ' + retryAfter + ' seconds.'
                    : 'Slow down. Try again in a moment.',
                { kind: 'bad' },
            );
        });
    });
});

/*
 * Forms that fail: focus goes to the first invalid field (so a keyboard or screen reader person lands on the problem, and
 * typed text is not typed into the button), and a closed sheet hands focus back to whatever opened it.
 */
let lastSubmitted = null;
let lastOpener = null;

document.addEventListener('submit', (event) => {
    lastSubmitted = event.target instanceof HTMLFormElement ? event.target : null;
}, true);

document.addEventListener('click', (event) => {
    const el = event.target instanceof Element ? event.target.closest('button, a') : null;
    if (el && !el.closest('dialog')) lastOpener = el;
}, true);

document.addEventListener('close', (event) => {
    if (!(event.target instanceof HTMLDialogElement)) return;
    const opener = lastOpener;
    if (opener && opener.isConnected) setTimeout(() => opener.focus({ preventScroll: true }), 0);
}, true);

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            const form = lastSubmitted;
            if (!form) return;
            setTimeout(() => {
                lastSubmitted = null;
                const live = form.isConnected ? form : document.querySelector('dialog[open] form, form[wire\\:submit]');
                const bad = live && live.querySelector('[aria-invalid="true"]');
                if (bad) bad.focus();
            }, 80);
        });
    });
});

function init() {
    syncThemeButtons();
    prepareMeters();
    if (document.body.getAttribute('data-pulse-check')) {
        poll();
        schedulePoll();
    }

    // Meters inserted later by Livewire.
    new MutationObserver(() => requestAnimationFrame(() => prepareMeters())).observe(document.body, {
        childList: true,
        subtree: true,
    });

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', syncThemeButtons);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
