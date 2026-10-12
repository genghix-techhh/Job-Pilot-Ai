<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PrivacyController extends Controller
{
    public function show(): View
    {
        return view('privacy.show', [
            'model' => config('jobpilot.gemini.model'),
            'version' => config('jobpilot.privacy_version'),
        ]);
    }
}
