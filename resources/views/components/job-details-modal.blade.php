{{--
    Listens for the `hrj:show-job` window event dispatched by running-jobs-table
    rows. Renders a modal showing the full job payload — class, server, queue,
    timing, attempts, timeout, tags. Survives panel auto-refresh because it
    lives outside the panel root that gets replaceWith()'d.
--}}
<div class="hrj"
    x-data="hrjJobDetails()"
    @hrj:show-job.window="show($event.detail)">
    <template x-if="open && job">
        <div class="hrj-modal-overlay"
            x-cloak
            @click.self="close()"
            @keydown.escape.window="close()">
            <div class="hrj-modal" role="dialog" aria-modal="true" style="max-width: 560px;">
                <h3>Job details</h3>

                <div class="hrj-job-summary">
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Class</span>
                        <span class="hrj-job-summary__value" x-text="job.job_class"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Job ID</span>
                        <span class="hrj-job-summary__value" x-text="job.job_id"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Queue</span>
                        <span class="hrj-job-summary__value" x-text="job.queue"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Server</span>
                        <span class="hrj-job-summary__value" x-text="job.server"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Status</span>
                        <span class="hrj-job-summary__value">
                            <span x-text="job.status"></span><template x-if="job.is_orphaned"><span style="color: var(--hrj-color-orphan);"> + orphan</span></template>
                        </span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Started</span>
                        <span class="hrj-job-summary__value" x-text="job.start_time"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Duration</span>
                        <span class="hrj-job-summary__value" x-text="job.running_for_formatted"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Attempts</span>
                        <span class="hrj-job-summary__value" x-text="job.attempts"></span>
                    </div>
                    <div class="hrj-job-summary__row">
                        <span class="hrj-job-summary__label">Timeout</span>
                        <span class="hrj-job-summary__value" x-text="(job.timeout || 'unset') + ' s'"></span>
                    </div>
                </div>

                <template x-if="job.tags && job.tags.length">
                    <div style="margin-top: 12px;">
                        <div class="hrj-muted" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Tags</div>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                            <template x-for="tag in job.tags" :key="tag">
                                <span class="hrj-badge hrj-badge--neutral" x-text="tag"></span>
                            </template>
                        </div>
                    </div>
                </template>

                <div class="hrj-modal__actions">
                    <button type="button" class="hrj-btn" @click="close()">Close</button>
                </div>
            </div>
        </div>
    </template>
</div>
