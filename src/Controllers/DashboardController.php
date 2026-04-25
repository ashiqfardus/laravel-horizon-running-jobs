<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function show(): View
    {
        return view('horizon-running-jobs::dashboard');
    }
}
