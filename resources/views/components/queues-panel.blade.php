@php
    /** @var int $poll Polling interval in ms (0 disables). */
    $poll ??= 5000;

    $payload = app(\Ashiqfardus\HorizonRunningJobs\QueueDepthInspector::class)->inspect();

    $panelUrl = route('horizon-running-jobs.panel', ['panel' => 'queues-panel']);
@endphp

<div
    class="hrj"
    data-hrj-panel="queues-panel"
    @if($poll > 0)
        x-data="hrjPanel({ url: '{{ $panelUrl }}', interval: {{ (int) $poll }} })"
        x-init="start()"
    @endif
>
    <div class="hrj-panel">
        <div class="hrj-panel__header">
            <h3 class="hrj-panel__title">Queue depth</h3>
            <span class="hrj-panel__meta">
                {{ $payload['totals']['total'] }} total ({{ $payload['totals']['pending'] }} pending)
            </span>
        </div>
        <div class="hrj-panel__body">
            @if(empty($payload['queues']))
                <div class="hrj-panel__empty">No queues to inspect.</div>
            @else
                <table class="hrj-table">
                    <thead>
                        <tr>
                            <th>Queue</th>
                            <th>Pending</th>
                            <th>Reserved</th>
                            <th>Delayed</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payload['queues'] as $q)
                            <tr>
                                <td class="hrj-mono">{{ $q['queue'] }}</td>
                                <td class="hrj-mono">{{ $q['pending'] }}</td>
                                <td class="hrj-mono">{{ $q['reserved'] }}</td>
                                <td class="hrj-mono">{{ $q['delayed'] }}</td>
                                <td class="hrj-mono"><strong>{{ $q['total'] }}</strong></td>
                            </tr>
                        @endforeach
                        <tr style="background: var(--hrj-bg-elev);">
                            <td><strong>TOTAL</strong></td>
                            <td class="hrj-mono"><strong>{{ $payload['totals']['pending'] }}</strong></td>
                            <td class="hrj-mono"><strong>{{ $payload['totals']['reserved'] }}</strong></td>
                            <td class="hrj-mono"><strong>{{ $payload['totals']['delayed'] }}</strong></td>
                            <td class="hrj-mono"><strong>{{ $payload['totals']['total'] }}</strong></td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
