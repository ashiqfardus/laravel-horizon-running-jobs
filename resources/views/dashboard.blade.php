@php
    /**
     * Standalone Horizon Running Jobs dashboard. Loads CSS, defines Alpine
     * data factories inline (so they're guaranteed in scope before Alpine
     * starts), then loads Alpine itself.
     */
    $cssUrl = route('horizon-running-jobs.assets', ['file' => 'css']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Horizon Queue Monitor</title>
    <link rel="stylesheet" href="{{ $cssUrl }}">

    {{-- Define Alpine factories synchronously BEFORE Alpine loads, so the
         x-data expressions on our panels can resolve them. This script runs
         during HTML parse (no defer / async) so the globals + the
         alpine:init listener are in place before Alpine starts. --}}
    <script>
        (function () {
            function csrfToken() {
                const m = document.querySelector('meta[name="csrf-token"]');
                return m ? m.getAttribute('content') : null;
            }

            function showToast(message, kind) {
                const el = document.createElement('div');
                el.className = 'hrj-toast' + (kind === 'error' ? ' hrj-toast--error' : '');
                el.textContent = message;
                document.body.appendChild(el);
                setTimeout(function () { el.remove(); }, 3500);
            }

            function hrjPanel(config) {
                const url = config && config.url;
                const interval = config && config.interval;
                return {
                    timer: null,
                    paused: false,
                    start: function () {
                        if (!url || !interval || this.paused) return;
                        const self = this;
                        this.timer = setInterval(function () { self.refresh(); }, interval);
                    },
                    stop: function () {
                        if (this.timer) clearInterval(this.timer);
                        this.timer = null;
                    },
                    togglePause: function () {
                        this.paused = !this.paused;
                        if (this.paused) {
                            this.stop();
                        } else {
                            this.start();
                            this.refresh();
                        }
                    },
                    refresh: async function () {
                        try {
                            const res = await fetch(url, {
                                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                            });
                            if (!res.ok) return;
                            const html = await res.text();
                            const wrapper = document.createElement('div');
                            wrapper.innerHTML = html.trim();
                            const fresh = wrapper.firstElementChild;
                            if (fresh && this.$root) {
                                this.stop();
                                // Alpine doesn't auto-init nodes inserted via replaceWith /
                                // innerHTML, so the new panel would lose its polling +
                                // release-button bindings. Use Alpine.initTree to walk
                                // the new subtree and wire up the directives.
                                this.$root.replaceWith(fresh);
                                if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                                    window.Alpine.initTree(fresh);
                                }
                            }
                        } catch (e) {
                            console.warn('[hrj] panel refresh failed', e);
                        }
                    },
                };
            }

            // Force every panel on the page to refresh — used after release/bulk
            // actions so the user sees the new state without waiting for a poll.
            function refreshAllPanels() {
                document.querySelectorAll('[data-hrj-panel]').forEach(function (el) {
                    if (el._x_dataStack && el._x_dataStack[0] && typeof el._x_dataStack[0].refresh === 'function') {
                        el._x_dataStack[0].refresh();
                    }
                });
            }

            async function postRelease(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                return [res, await res.json().catch(function () { return {}; })];
            }

            function hrjReleaseButton(config) {
                const url = config && config.url;
                const jobId = config && config.jobId;
                return {
                    open: false,
                    busy: false,
                    jobClass: (config && config.jobClass) || '',
                    queue: (config && config.queue) || '',
                    reason: (config && config.reason) || '',
                    cancel: function () {
                        if (this.busy) return;
                        this.open = false;
                    },
                    submit: async function () {
                        if (this.busy) return;
                        this.busy = true;
                        try {
                            const [res, body] = await postRelease(url, { job_id: jobId });
                            if (!res.ok || body.success !== true) {
                                showToast(body.message || ('Release failed (HTTP ' + res.status + ')'), 'error');
                                return;
                            }
                            showToast('Released job ' + jobId.substr(0, 8) + '…');
                            this.open = false;
                            refreshAllPanels();
                        } catch (e) {
                            console.error('[hrj] release request failed', e);
                            showToast('Release failed: ' + (e && e.message ? e.message : 'network error'), 'error');
                        } finally {
                            this.busy = false;
                        }
                    },
                };
            }

            function hrjBulkRelease(config) {
                const url = config && config.url;
                return {
                    open: false,
                    busy: false,
                    target: 'orphaned',
                    submit: async function () {
                        if (this.busy) return;
                        this.busy = true;
                        try {
                            const payload = {};
                            payload[this.target] = true;
                            const [res, body] = await postRelease(url, payload);
                            if (!res.ok || body.success !== true) {
                                showToast(body.message || ('Bulk release failed (HTTP ' + res.status + ')'), 'error');
                                return;
                            }
                            const n = body.released || 0;
                            showToast(n === 0 ? 'No matching jobs to release.' : ('Released ' + n + ' job(s).'));
                            this.open = false;
                            refreshAllPanels();
                        } catch (e) {
                            console.error('[hrj] bulk release request failed', e);
                            showToast('Bulk release failed: ' + (e && e.message ? e.message : 'network error'), 'error');
                        } finally {
                            this.busy = false;
                        }
                    },
                };
            }

            function hrjJobDetails() {
                return {
                    open: false,
                    job: null,
                    show: function (job) {
                        this.job = job;
                        this.open = true;
                    },
                    close: function () {
                        this.open = false;
                        this.job = null;
                    },
                };
            }

            // Make available globally for x-data="hrjPanel({...})" expressions.
            window.hrjPanel = hrjPanel;
            window.hrjReleaseButton = hrjReleaseButton;
            window.hrjBulkRelease = hrjBulkRelease;
            window.hrjJobDetails = hrjJobDetails;

            // Also register as Alpine data factories for x-data="hrjPanel" (no parens).
            document.addEventListener('alpine:init', function () {
                if (window.Alpine) {
                    window.Alpine.data('hrjPanel', hrjPanel);
                    window.Alpine.data('hrjReleaseButton', hrjReleaseButton);
                    window.Alpine.data('hrjBulkRelease', hrjBulkRelease);
                    window.Alpine.data('hrjJobDetails', hrjJobDetails);
                }
            });
        })();
    </script>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body {
            margin: 0;
            background: #f9fafb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; }
        }
        .hrj-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .hrj-page__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .hrj-page__header h1 {
            font-size: 18px;
            margin: 0;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>
    <div class="hrj-page">
        <div class="hrj hrj-page__header">
            <h1>Horizon Queue Monitor</h1>
            <span class="hrj-muted hrj-mono" style="font-size: 12px;">
                {{ gethostname() }} • {{ config('app.env') }}
            </span>
        </div>

        <x-horizon-running-jobs::dashboard />
    </div>
</body>
</html>
