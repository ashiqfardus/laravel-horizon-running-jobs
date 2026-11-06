{{-- Stand-in for a copy of the view published before it bypassed the cache itself. --}}
@php
    $result = app(\Ashiqfardus\HorizonRunningJobs\RunningJobsManager::class)->getRunningJobs(null, true, null, false);
@endphp
<div class="hrj">published copy: {{ count($result['jobs']) }} job(s)</div>
