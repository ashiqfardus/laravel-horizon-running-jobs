@php
    /** @var int $poll Polling interval in ms (0 disables). */
    $poll ??= 5000;

    $payload = app(\Ashiqfardus\HorizonRunningJobs\SupervisorInspector::class)->inspect();
    $supervisors = $payload['supervisors'];
    $stale = array_filter($supervisors, fn ($s) => ! empty($s['is_stale']));

    $panelUrl = route('horizon-running-jobs.panel', ['panel' => 'supervisors-panel']);
@endphp

<div
    class="hrj"
    data-hrj-panel="supervisors-panel"
    @if($poll > 0)
        x-data="hrjPanel({ url: '{{ $panelUrl }}', interval: {{ (int) $poll }} })"
        x-init="start()"
    @endif
>
    <div class="hrj-panel">
        <div class="hrj-panel__header">
            <h3 class="hrj-panel__title">Supervisors</h3>
            <div class="hrj-panel__controls">
                <span class="hrj-panel__meta">
                    {{ count($supervisors) }} total{{ count($stale) > 0 ? ' • ' . count($stale) . ' stale' : '' }}
                </span>
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
            @if(empty($supervisors))
                <div class="hrj-panel__empty">No supervisors registered.</div>
            @else
                <table class="hrj-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>PID</th>
                            <th>Queues</th>
                            <th>Procs</th>
                            <th>Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($supervisors as $s)
                            <tr>
                                <td>
                                    <span class="hrj-mono hrj-truncate" title="{{ $s['name'] }}">{{ $s['name'] }}</span>
                                </td>
                                <td>
                                    @php
                                        $badge = match (true) {
                                            ! empty($s['is_stale']) => 'fail',
                                            ($s['status'] ?? '') === 'paused' => 'warn',
                                            default => 'pass',
                                        };
                                        $label = ! empty($s['is_stale']) ? 'stale' : ($s['status'] ?? 'unknown');
                                    @endphp
                                    <span class="hrj-badge hrj-badge--{{ $badge }}">{{ $label }}</span>
                                </td>
                                <td class="hrj-mono">{{ $s['pid'] ?? '—' }}</td>
                                <td class="hrj-mono">{{ empty($s['queues']) ? '—' : implode(', ', $s['queues']) }}</td>
                                <td class="hrj-mono">{{ $s['process_count'] ?? 0 }}</td>
                                <td class="hrj-mono">
                                    @if(! empty($s['is_stale']))
                                        <span class="hrj-badge hrj-badge--fail">OVERDUE {{ abs(($s['expires_at'] ?? 0) - time()) }}s</span>
                                    @else
                                        {{ ($s['seconds_until_expiry'] ?? 0) }}s
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
