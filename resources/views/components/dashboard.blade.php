@php
    /**
     * Composable dashboard. Drop this anywhere with `<x-horizon-running-jobs::dashboard />`.
     * Assumes the host page has loaded the package CSS + Alpine.js.
     */
    $poll ??= 5000;
    $jobsPoll ??= 3000;
@endphp

<div class="hrj" style="display: flex; flex-direction: column; gap: 1rem;">
    <x-horizon-running-jobs::diagnose-banner :poll="$poll" />

    <div class="hrj-grid">
        <x-horizon-running-jobs::supervisors-panel :poll="$poll" />
        <x-horizon-running-jobs::queues-panel :poll="$poll" />
    </div>

    <x-horizon-running-jobs::running-jobs-table :poll="$jobsPoll" />

    <x-horizon-running-jobs::job-details-modal />
</div>
