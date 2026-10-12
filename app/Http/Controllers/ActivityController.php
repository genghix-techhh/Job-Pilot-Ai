<?php

namespace App\Http\Controllers;

use App\Models\AiUsageLog;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\SourceSyncRun;
use App\Services\Diagnostics\Diagnostics;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Diagnostics $diag): View
    {
        return view('activity.index', [
            'runs' => SourceSyncRun::query()->with('source')->latest('id')->limit(20)->get(),
            'aiLogs' => AiUsageLog::query()->latest('id')->limit(25)->get(),
            'notifications' => NotificationLog::query()->latest('id')->limit(25)->get(),
            'audit' => AuditLog::query()->latest('id')->limit(20)->get(),
            'ai' => $diag->ai(),
            'queue' => $diag->queue(),
            'scheduler' => $diag->scheduler(),
            'system' => $diag->system(),
        ]);
    }
}
