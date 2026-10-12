import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Small reusable Alpine helpers. No network calls, no third-party scripts.
 */

// Repeating form groups (work history, projects, education ...).
Alpine.data('repeater', (initial = [], blank = {}) => ({
    rows: initial.length ? initial : [],
    add() {
        this.rows.push({ ...blank, _k: Math.random().toString(36).slice(2) });
    },
    remove(i) {
        this.rows.splice(i, 1);
    },
}));

// Confirm before destructive submits: <form x-data="confirmSubmit('Delete this?')" @submit="check($event)">
Alpine.data('confirmSubmit', (message) => ({
    check(e) {
        if (!window.confirm(message)) {
            e.preventDefault();
        }
    },
}));

// Disable the submit button after the first click so slow actions cannot be double-submitted.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented || 'allowResubmit' in form.dataset) {
        return;
    }
    const btn = e.submitter ?? form.querySelector('button[type=submit]');
    if (btn && !('noLock' in btn.dataset)) {
        // Defer so the button's own name/value is still submitted.
        setTimeout(() => {
            btn.disabled = true;
            btn.classList.add('opacity-60');
        }, 0);
    }
});

// Back/forward cache restores disabled buttons; re-enable them.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[type=submit][disabled]').forEach((b) => {
        b.disabled = false;
        b.classList.remove('opacity-60');
    });
});

Alpine.start();
