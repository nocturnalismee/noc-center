import '@fontsource-variable/inter';
import '@fontsource/jetbrains-mono/400.css';

import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import { initAlertSound } from './alert-sound';
import { initSeriesCharts } from './charts';

window.Alpine = Alpine;

Livewire.start();

const root = document.documentElement;
const mobileQuery = window.matchMedia('(max-width: 767px)');

function sidebarElement() {
    return document.getElementById('sidebar');
}

function backdropElement() {
    return document.querySelector('[data-sidebar-backdrop]');
}

function openSidebar() {
    const sidebar = sidebarElement();
    const backdrop = backdropElement();

    if (!sidebar) {
        return;
    }

    sidebar.classList.add('is-open');

    if (backdrop) {
        backdrop.hidden = false;
        backdrop.classList.add('is-open');
    }

    document.querySelector('[data-sidebar-open]')?.setAttribute('aria-expanded', 'true');
    sidebar.focus();
}

function closeSidebar({ restoreFocus = true } = {}) {
    const sidebar = sidebarElement();
    const backdrop = backdropElement();

    if (!sidebar?.classList.contains('is-open')) {
        return;
    }

    sidebar.classList.remove('is-open');

    if (backdrop) {
        backdrop.classList.remove('is-open');
        backdrop.hidden = true;
    }

    document.querySelector('[data-sidebar-open]')?.setAttribute('aria-expanded', 'false');

    if (restoreFocus) {
        document.querySelector('[data-sidebar-open]')?.focus();
    }
}

function applyTheme(theme) {
    const dark = theme === 'dark';

    root.classList.toggle('dark', dark);
    root.setAttribute('data-theme', dark ? 'dark' : 'light');

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
    });

    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme } }));
}

function toggleTheme() {
    const next = root.classList.contains('dark') ? 'light' : 'dark';

    try {
        localStorage.setItem('theme', next);
    } catch (error) {
        // Storage can be unavailable; the in-page theme still changes.
    }

    applyTheme(next);
}

function applySidebar(state) {
    const collapsed = state === 'collapsed';

    root.setAttribute('data-sidebar', collapsed ? 'collapsed' : 'expanded');

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    });
}

function applyPlatformHints() {
    const isApple = /Mac|iPhone|iPad|iPod/.test(navigator.platform);

    document.querySelectorAll('[data-search-hint]').forEach((hint) => {
        hint.textContent = isApple ? '⌘K' : 'Ctrl K';
    });
}

function toggleSidebar() {
    const next = root.getAttribute('data-sidebar') === 'collapsed' ? 'expanded' : 'collapsed';

    try {
        localStorage.setItem('sidebar', next);
    } catch (error) {
        // Storage can be unavailable; the in-page state still changes.
    }

    applySidebar(next);
}

function togglePassword(button) {
    const input = button.closest('[data-password-field]')?.querySelector('input');

    if (!input) {
        return;
    }

    const reveal = input.type === 'password';

    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
    button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
}

function copyStatus(message) {
    const status = document.getElementById('copy-status');

    if (status) {
        status.textContent = message;
    }
}

async function copyText(text) {
    if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch (error) {
            // Fall through to the selection-based fallback.
        }
    }

    const helper = document.createElement('textarea');

    helper.value = text;
    helper.setAttribute('readonly', '');
    helper.style.position = 'fixed';
    helper.style.top = '0';
    helper.style.opacity = '0';
    document.body.appendChild(helper);
    helper.select();

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    }

    helper.remove();

    return copied;
}

async function copyFrom(button) {
    const target = document.getElementById(button.dataset.copyTarget);

    if (!target) {
        return;
    }

    const copied = await copyText(target.textContent.trim());

    copyStatus(copied ? 'Copied to clipboard.' : 'Copy failed. Select the text and copy it manually.');

    if (!copied) {
        return;
    }

    const label = button.textContent;

    button.textContent = 'Copied';
    window.setTimeout(() => {
        button.textContent = label;
    }, 1500);
}

document.addEventListener('click', (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
        return;
    }

    if (target.closest('[data-theme-toggle]')) {
        toggleTheme();
        return;
    }

    if (target.closest('[data-sidebar-toggle]')) {
        toggleSidebar();
        return;
    }

    if (target.closest('[data-sidebar-open]')) {
        openSidebar();
        return;
    }

    if (target.closest('[data-sidebar-close]') || target.closest('[data-sidebar-backdrop]')) {
        closeSidebar();
        return;
    }

    const passwordButton = target.closest('[data-password-toggle]');

    if (passwordButton) {
        togglePassword(passwordButton);
        return;
    }

    const copyButton = target.closest('[data-copy-target]');

    if (copyButton) {
        copyFrom(copyButton);
    }
});

document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        const search = document.getElementById('global-search');

        if (search && search.getClientRects().length > 0) {
            event.preventDefault();
            search.focus();
            search.select();
        }

        return;
    }

    if (event.key === 'Escape') {
        closeSidebar();
    }
});

mobileQuery.addEventListener('change', (event) => {
    if (!event.matches) {
        closeSidebar({ restoreFocus: false });
    }
});

applyTheme(root.classList.contains('dark') ? 'dark' : 'light');
applySidebar(root.getAttribute('data-sidebar') === 'collapsed' ? 'collapsed' : 'expanded');
applyPlatformHints();

document.addEventListener('DOMContentLoaded', () => {
    initSeriesCharts();
    initAlertSound();
});
document.addEventListener('livewire:navigated', () => {
    initSeriesCharts();
    initAlertSound();
});
