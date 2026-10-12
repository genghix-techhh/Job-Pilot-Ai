<?php

namespace App\Http\Controllers;

use App\Services\Profile\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsentController extends Controller
{
    public function show(Request $request, ProfileService $profiles): View
    {
        return view('privacy.consent', [
            'profile' => $profiles->forUser($request->user()),
            'model' => config('jobpilot.gemini.model'),
            'keyPresent' => filled(config('jobpilot.gemini.api_key')),
        ]);
    }

    public function store(Request $request, ProfileService $profiles): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accept,decline']]);

        $profile = $profiles->forUser($request->user());
        $profiles->setConsent($profile, $data['decision'] === 'accept');

        return redirect()->intended(route($profile->hasContent() ? 'dashboard' : 'profile.edit'))
            ->with('status', $data['decision'] === 'accept'
                ? 'Thanks — redacted AI analysis is enabled. You can change this any time under Settings.'
                : 'Understood — AI features stay off. Discovery, scoring, drafts and tracking all work without Gemini.');
    }
}
