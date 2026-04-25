@php
    /** @var int $poll Polling interval in ms (0 disables). */
    /** @var bool $allowRelease Show inline release button on orphan/zombie rows. */
    /** @var bool $orphanedOnly Filter to orphaned jobs only. */
    $poll ??= 3000;
    $allowRelease ??= true;
    $orphanedOnly ??= false;

    $manager = app(\Ashiqfardus\HorizonRunningJobs\RunningJobsManager::class);
    $result = $manager->getRunningJobs(null, true, null, $orphanedOnly);
    $jobs = $result['jobs'];

    $panelUrl = route('horizon-running-jobs.panel', [
        'panel' => 'running-jobs-table',
        'orphaned_only' => $orphanedOnly ? '1' : '0',
        'allow_release' => $allowRelease ? '1' : '0',
    ]);
    $releaseUrl = route('horizon-running-jobs.release');

    $orphanCount = (int) ($result['orphan_count'] ?? 0);
    $hasZombie = collect($jobs)->contains(fn ($j) => ($j['status'] ?? '') === 'zombie');
@endphp

<div
    class="hrj"
    data-hrj-panel="running-jobs-table"
    @if($poll > 0)
        x-data="hrjPanel({ url: '{{ $panelUrl }}', interval: {{ (int) $poll }} })"
        x-init="start()"
    @endif
>
    <div class="hrj-panel">
        <div class="hrj-panel__header">
            <h3 class="hrj-panel__title">{{ $orphanedOnly ? 'Orphaned jobs' : 'Running jobs' }}</h3>
            <div class="hrj-panel__controls">
                <span class="hrj-panel__meta">
                    {{ count($jobs) }} of {{ $result['total_count'] }}{{ $orphanCount > 0 ? ' • ' . $orphanCount . ' orphan' : '' }}
                </span>

                @if($allowRelease && ($orphanCount > 0 || $hasZombie))
                    <div x-data="hrjBulkRelease({ url: '{{ $releaseUrl }}' })" style="display: contents;">
                        <button type="button" class="hrj-btn hrj-btn--release" @click="open = true">
                            release all{{ $orphanCount > 0 ? ' orphans' : ' zombies' }}
                        </button>

                        <div x-show="open" x-cloak class="hrj-modal-overlay"
                            @click.self="if (!busy) open = false"
                            @keydown.escape.window="if (!busy) open = false">
                            <div class="hrj-modal" role="dialog" aria-modal="true">
                                <h3>Release every orphan and zombie?</h3>
                                <p>
                                    Every reserved job whose worker is gone (orphan) or whose
                                    reservation has expired (zombie) will be moved back to the
                                    front of its pending list. A healthy worker will pick them up
                                    on its next loop.
                                </p>
                                <p class="hrj-muted">
                                    This is the bulk equivalent of <code>php artisan horizon:release --orphaned --force</code>
                                    plus <code>--zombie</code>. Each release is logged.
                                </p>
                                <div class="hrj-modal__actions">
                                    <button type="button" class="hrj-btn" @click="open = false" :disabled="busy">Cancel</button>
                                    <button type="button" class="hrj-btn hrj-btn--primary"
                                        @click="target = 'orphaned'; submit()"
                                        :disabled="busy">
                                        <span x-show="!busy">Release all</span>
                                        <span x-show="busy" x-cloak>releasing…</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if($poll > 0)
                    <button type="button" class="hrj-icon-btn"
                        @click="togglePause()"
                        :aria-pressed="paused"
                        :title="paused ? 'Resume auto-refresh' : 'Pause auto-refresh'"
                        :aria-label="paused ? 'Resume auto-refresh' : 'Pause auto-refresh'"
                    ><span x-show="!paused">⏸</span><span x-show="paused" x-cloak>▶</span></button>
                @endif
            </div>
        </div>
        <div class="hrj-panel__body">
            @if(empty($jobs))
                <div class="hrj-panel__empty">
                    {{ $orphanedOnly ? '✓ No orphaned jobs.' : '✓ No jobs currently running.' }}
                </div>
            @else
                <table class="hrj-table">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Queue</th>
                            <th>Server</th>
                            <th>Status</th>
                            <th>Duration</th>
                            @if($allowRelease)<th>Action</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($jobs as $job)
                            @php
                                $isOrphan = ($job['is_orphaned'] ?? false) === true;
                                $status = $job['status'] ?? 'running';
                                $rowKey = $job['job_id'];

                                [$badgeClass, $badgeLabel] = match (true) {
                                    $isOrphan && $status === 'zombie' => ['orphan', 'orphan + zombie'],
                                    $isOrphan => ['orphan', 'orphan'],
                                    $status === 'zombie' => ['zombie', 'zombie'],
                                    default => ['pass', 'running'],
                                };

                                $canRelease = $allowRelease && ($isOrphan || $status === 'zombie');
                                $jobJson = htmlspecialchars(json_encode($job, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
                            @endphp
                            <tr style="cursor: pointer;" @click="$dispatch('hrj:show-job', @js($job))">
                                <td>
                                    <span class="hrj-mono hrj-truncate" title="{{ $job['job_class'] }}">{{ class_basename($job['job_class']) }}</span>
                                    <div class="hrj-mono hrj-muted" style="font-size: 11px;">{{ substr($rowKey, 0, 8) }}…</div>
                                </td>
                                <td class="hrj-mono">{{ $job['queue'] }}</td>
                                <td class="hrj-mono hrj-truncate">{{ $job['server'] }}</td>
                                <td>
                                    <span class="hrj-badge hrj-badge--{{ $badgeClass }}">{{ $badgeLabel }}</span>
                                </td>
                                <td class="hrj-mono">{{ $job['running_for_formatted'] }}</td>
                                @if($allowRelease)
                                    <td @click.stop>
                                        @if($canRelease)
                                            <div x-data="hrjReleaseButton({
                                                url: '{{ $releaseUrl }}',
                                                jobId: '{{ $rowKey }}',
                                                jobClass: @js($job['job_class']),
                                                queue: @js($job['queue']),
                                                reason: '{{ $badgeLabel }}'
                                            })" style="display: contents;">
                                                <button type="button" class="hrj-btn hrj-btn--release" @click="open = true" :disabled="busy">release</button>

                                                <div x-show="open" x-cloak class="hrj-modal-overlay"
                                                    @click.self="cancel()"
                                                    @keydown.escape.window="cancel()">
                                                    <div class="hrj-modal" role="dialog" aria-modal="true">
                                                        <h3>Release this reserved job?</h3>
                                                        <p>The job will be moved back to the front of its pending list so a healthy worker picks it up.</p>
                                                        <div class="hrj-job-summary">
                                                            <div class="hrj-job-summary__row">
                                                                <span class="hrj-job-summary__label">Job</span>
                                                                <span class="hrj-job-summary__value" x-text="jobClass"></span>
                                                            </div>
                                                            <div class="hrj-job-summary__row">
                                                                <span class="hrj-job-summary__label">Queue</span>
                                                                <span class="hrj-job-summary__value" x-text="queue"></span>
                                                            </div>
                                                            <div class="hrj-job-summary__row">
                                                                <span class="hrj-job-summary__label">Why</span>
                                                                <span class="hrj-job-summary__value" x-text="reason"></span>
                                                            </div>
                                                            <div class="hrj-job-summary__row">
                                                                <span class="hrj-job-summary__label">UUID</span>
                                                                <span class="hrj-job-summary__value">{{ $rowKey }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="hrj-modal__actions">
                                                            <button type="button" class="hrj-btn" @click="cancel()" :disabled="busy">Cancel</button>
                                                            <button type="button" class="hrj-btn hrj-btn--primary" @click="submit()" :disabled="busy">
                                                                <span x-show="!busy">Yes, release</span>
                                                                <span x-show="busy" x-cloak>releasing…</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
