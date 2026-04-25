@php
    /** @var int $poll Polling interval in milliseconds (0 disables polling). */
    $poll ??= 5000;

    $diagnosis = app(\Ashiqfardus\HorizonRunningJobs\HealthDiagnoser::class)->diagnose();
    $overall = $diagnosis['overall_status'];
    $issues = array_values(array_filter($diagnosis['checks'], fn ($c) => $c['status'] !== 'pass'));

    $icon = match ($overall) {
        'pass' => '✓',
        'warn' => '⚠',
        'fail' => '✗',
    };
    $title = match ($overall) {
        'pass' => 'All systems healthy',
        'warn' => count($issues) . ' issue(s) detected — investigate when convenient',
        'fail' => 'Horizon is not healthy — immediate attention needed',
    };

    $panelUrl = route('horizon-running-jobs.panel', ['panel' => 'diagnose-banner']);
@endphp

<div
    class="hrj"
    data-hrj-panel="diagnose-banner"
    @if($poll > 0)
        x-data="hrjPanel({ url: '{{ $panelUrl }}', interval: {{ (int) $poll }} })"
        x-init="start()"
    @endif
>
    <div class="hrj-banner hrj-banner--{{ $overall }}">
        <div class="hrj-banner__icon">{{ $icon }}</div>
        <div style="flex: 1;">
            <p class="hrj-banner__title">{{ $title }}</p>
            @if(! empty($issues))
                <ul class="hrj-banner__list">
                    @foreach($issues as $issue)
                        <li>
                            <span class="hrj-banner__check">{{ $issue['name'] }}</span>
                            {{ $issue['message'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <span class="hrj-badge hrj-badge--{{ $overall }}">{{ strtoupper($overall) }}</span>
    </div>
</div>
