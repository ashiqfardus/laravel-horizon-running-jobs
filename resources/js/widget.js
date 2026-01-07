/**
 * Horizon Running Jobs Widget
 *
 * A standalone JavaScript widget that can be embedded in any page
 * to display currently running jobs.
 *
 * Usage:
 *
 * <div id="running-jobs-widget"></div>
 * <script src="/vendor/horizon-running-jobs/widget.js"></script>
 * <script>
 *   HorizonRunningJobs.init({
 *     container: '#running-jobs-widget',
 *     apiUrl: '/api/horizon/running-jobs',
 *     refreshInterval: 5000,
 *     showAllServers: false
 *   });
 * </script>
 */

(function(global) {
    'use strict';

    const HorizonRunningJobs = {
        options: {
            container: '#running-jobs-widget',
            apiUrl: '/api/horizon/running-jobs',
            refreshInterval: 5000,
            showAllServers: false
        },

        intervalId: null,

        init: function(options) {
            this.options = { ...this.options, ...options };
            this.render();
            this.fetchJobs();

            if (this.options.refreshInterval > 0) {
                this.intervalId = setInterval(() => {
                    this.fetchJobs();
                }, this.options.refreshInterval);
            }
        },

        destroy: function() {
            if (this.intervalId) {
                clearInterval(this.intervalId);
            }
        },

        render: function() {
            const container = document.querySelector(this.options.container);
            if (!container) return;

            container.innerHTML = `
                <div class="hrj-widget" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                    <div class="hrj-header" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #1a1a2e; color: white; border-radius: 8px 8px 0 0;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600;">
                            🔄 Running Jobs
                        </h3>
                        <div>
                            <label style="font-size: 12px; margin-right: 10px; cursor: pointer;">
                                <input type="checkbox" id="hrj-show-all" ${this.options.showAllServers ? 'checked' : ''} style="margin-right: 4px;">
                                All Servers
                            </label>
                            <button id="hrj-refresh" style="padding: 4px 12px; font-size: 12px; background: #6366f1; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                Refresh
                            </button>
                        </div>
                    </div>
                    <div class="hrj-body" style="background: #16162a; border-radius: 0 0 8px 8px; overflow: hidden;">
                        <div id="hrj-loading" style="padding: 20px; text-align: center; color: #888;">
                            Loading...
                        </div>
                        <div id="hrj-error" style="padding: 16px; background: #dc3545; color: white; display: none;"></div>
                        <div id="hrj-empty" style="padding: 40px; text-align: center; color: #666; display: none;">
                            ✓ No jobs currently running
                        </div>
                        <table id="hrj-table" style="width: 100%; border-collapse: collapse; display: none;">
                            <thead>
                                <tr style="background: #1e1e3f; color: #aaa; font-size: 11px; text-transform: uppercase;">
                                    <th style="padding: 10px 16px; text-align: left;">Job</th>
                                    <th style="padding: 10px 16px; text-align: left;">Queue</th>
                                    <th style="padding: 10px 16px; text-align: left;">Server</th>
                                    <th style="padding: 10px 16px; text-align: left;">Duration</th>
                                    <th style="padding: 10px 16px; text-align: center;">Attempts</th>
                                </tr>
                            </thead>
                            <tbody id="hrj-jobs"></tbody>
                        </table>
                        <div id="hrj-footer" style="padding: 10px 16px; background: #1e1e3f; color: #666; font-size: 11px; display: none;"></div>
                    </div>
                </div>
            `;

            // Bind events
            document.getElementById('hrj-refresh').addEventListener('click', () => this.fetchJobs());
            document.getElementById('hrj-show-all').addEventListener('change', (e) => {
                this.options.showAllServers = e.target.checked;
                this.fetchJobs();
            });
        },

        fetchJobs: async function() {
            const loading = document.getElementById('hrj-loading');
            const error = document.getElementById('hrj-error');
            const empty = document.getElementById('hrj-empty');
            const table = document.getElementById('hrj-table');
            const tbody = document.getElementById('hrj-jobs');
            const footer = document.getElementById('hrj-footer');

            loading.style.display = 'block';
            error.style.display = 'none';
            empty.style.display = 'none';
            table.style.display = 'none';
            footer.style.display = 'none';

            try {
                const url = new URL(this.options.apiUrl, window.location.origin);
                if (this.options.showAllServers) {
                    url.searchParams.append('all', 'true');
                }

                const response = await fetch(url.toString());
                const data = await response.json();

                loading.style.display = 'none';

                if (!data.success) {
                    throw new Error(data.message || 'Failed to fetch jobs');
                }

                if (data.jobs.length === 0) {
                    empty.style.display = 'block';
                    empty.textContent = this.options.showAllServers
                        ? '✓ No jobs currently running'
                        : '✓ No jobs running on ' + data.hostname;
                    return;
                }

                // Render jobs
                tbody.innerHTML = data.jobs.map(job => `
                    <tr style="border-bottom: 1px solid #2a2a4a; ${job.running_for_seconds > 300 ? 'background: #3d2020;' : ''}">
                        <td style="padding: 12px 16px; color: #fff;">
                            <span style="background: #4a4a6a; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-right: 8px; color: #aaa;">
                                ${job.job_id.substring(0, 8)}
                            </span>
                            ${this.formatJobName(job.job_class)}
                        </td>
                        <td style="padding: 12px 16px;">
                            <span style="background: #6366f1; padding: 2px 8px; border-radius: 3px; font-size: 11px; color: white;">
                                ${job.queue}
                            </span>
                        </td>
                        <td style="padding: 12px 16px; color: #aaa; font-size: 13px;">${job.server}</td>
                        <td style="padding: 12px 16px; color: ${job.running_for_seconds > 300 ? '#ff6b6b' : '#4ade80'}; font-weight: 500;">
                            ${job.running_for_formatted}
                        </td>
                        <td style="padding: 12px 16px; text-align: center; color: #aaa;">${job.attempts}</td>
                    </tr>
                `).join('');

                table.style.display = 'table';
                footer.style.display = 'block';
                footer.innerHTML = `
                    ${data.jobs.length} running job(s) | Host: ${data.hostname} | Updated: ${new Date().toLocaleTimeString()}
                    ${data.warnings.length > 0 ? '<span style="color: #fbbf24; margin-left: 10px;">⚠️ ' + data.warnings.join(', ') + '</span>' : ''}
                `;

            } catch (e) {
                loading.style.display = 'none';
                error.style.display = 'block';
                error.textContent = 'Error: ' + e.message;
            }
        },

        formatJobName: function(className) {
            const parts = className.split('\\');
            return parts[parts.length - 1];
        }
    };

    // Export to global
    global.HorizonRunningJobs = HorizonRunningJobs;

})(typeof window !== 'undefined' ? window : this);

