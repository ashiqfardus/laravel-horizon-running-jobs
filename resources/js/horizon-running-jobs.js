/**
 * Horizon Running Jobs — Alpine.js factories used by the Blade components.
 *
 * Two factories are registered globally so the Blade components can call
 * x-data="hrjPanel(...)" and x-data="hrjReleaseButton(...)" without an
 * Alpine.data() registration step on the host app's side.
 */
(function () {
    'use strict';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    function hrjPanel({ url, interval }) {
        return {
            timer: null,
            start() {
                if (!url || !interval) return;
                this.timer = setInterval(() => this.refresh(), interval);
            },
            stop() {
                if (this.timer) clearInterval(this.timer);
                this.timer = null;
            },
            async refresh() {
                try {
                    const res = await fetch(url, {
                        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) return;
                    const html = await res.text();
                    // Replace this panel's outer HTML with the fresh markup.
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    const fresh = wrapper.firstElementChild;
                    if (fresh && this.$root) {
                        this.stop();
                        this.$root.replaceWith(fresh);
                    }
                } catch (e) {
                    // Network blip — try again next tick.
                }
            },
        };
    }

    function hrjReleaseButton({ url, jobId }) {
        return {
            busy: false,
            async release() {
                if (this.busy) return;
                if (!confirm('Release this job back to the pending queue?')) return;

                this.busy = true;
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ job_id: jobId }),
                    });

                    const body = await res.json().catch(() => ({}));

                    if (!res.ok || body.success !== true) {
                        showToast(body.message || 'Release failed', 'error');
                        return;
                    }

                    showToast(`Released job ${jobId.substr(0, 8)}…`);
                    // Trigger a global refresh of any open panels.
                    document.querySelectorAll('[data-hrj-panel]').forEach((el) => {
                        // Each panel's Alpine state has a refresh() — fire it via a custom event.
                        el.dispatchEvent(new CustomEvent('hrj:refresh'));
                    });
                } finally {
                    this.busy = false;
                }
            },
        };
    }

    function showToast(message, kind = 'success') {
        const el = document.createElement('div');
        el.className = 'hrj-toast' + (kind === 'error' ? ' hrj-toast--error' : '');
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    // Register factories for Alpine. We support both:
    //   1. Alpine already loaded — register immediately.
    //   2. Alpine loads later (script defer) — register on alpine:init.
    function register() {
        if (typeof window.Alpine === 'undefined') return false;
        window.Alpine.data('hrjPanel', hrjPanel);
        window.Alpine.data('hrjReleaseButton', hrjReleaseButton);
        return true;
    }

    if (!register()) {
        document.addEventListener('alpine:init', register);
    }

    // Also expose globally for direct use without Alpine registration.
    window.hrjPanel = hrjPanel;
    window.hrjReleaseButton = hrjReleaseButton;
})();
