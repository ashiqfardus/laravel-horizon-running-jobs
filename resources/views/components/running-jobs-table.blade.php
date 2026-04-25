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
            <span class="hrj-panel__meta">
                {{ count($jobs) }} of {{ $result['total_count'] }}{{ ($result['orphan_count'] ?? 0) > 0 ? ' • ' . $result['orphan_count'] . ' orphan' : '' }}
            </span>
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
                            @endphp
                            <tr>
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
                                    <td>
                                        @if($canRelease)
                                            <button
                                                type="button"
                                                class="hrj-btn hrj-btn--release"
                                                x-data="hrjReleaseButton({ url: '{{ $releaseUrl }}', jobId: '{{ $rowKey }}' })"
                                                @click="release()"
                                                :disabled="busy"
                                            >release</button>
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
