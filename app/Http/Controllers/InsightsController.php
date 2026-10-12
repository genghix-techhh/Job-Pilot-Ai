<?php

namespace App\Http\Controllers;

use App\Services\Insights\InsightsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InsightsController extends Controller
{
    public function index(Request $request, InsightsService $insights): View
    {
        $d = $request->validate(['days' => ['nullable', 'in:30,90,180,365']]);

        return view('insights.index', $insights->build($request->user(), (int) ($d['days'] ?? 90)));
    }
}
