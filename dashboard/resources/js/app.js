import '@fontsource-variable/inter';
import '@fontsource/jetbrains-mono/400.css';

import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

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
    }
});

document.addEventListener('keydown', (event) => {
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
