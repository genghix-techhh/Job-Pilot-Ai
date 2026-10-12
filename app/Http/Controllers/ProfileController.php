<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileRequest;
use App\Jobs\RecalculateMatches;
use App\Models\Resume;
use App\Services\Matching\Geo;
use App\Services\Matching\SkillCatalog;
use App\Services\Profile\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request, ProfileService $profiles): View
    {
        $profile = $profiles->forUser($request->user());
        $pending = $profile->pending_extraction ?: null;

        // A freshly parsed CV is shown for review; nothing is used for matching until it is saved.
        $source = $pending ?? [
            'structured' => $profile->structured ?? $profiles->emptyStructured(),
            'skills' => $profile->skills ?? [],
            'years_experience' => $profile->years_experience,
            'roles' => $profile->roles ?? [],
        ];
        $structured = array_replace($profiles->emptyStructured(), (array) $source['structured']);

        return view('profile.edit', [
            'profile' => $profile,
            'pending' => $pending,
            'structured' => $structured,
            'skills' => (array) $source['skills'],
            'years' => $source['years_experience'] ?? null,
            // Target roles always come from the saved profile / settings, not from the CV.
            'roles' => $profile->roles ?: (array) settings()->get('target_roles'),
            'resumes' => Resume::query()->where('user_id', $request->user()->id)->orderByDesc('version')->get(),
            'versions' => $profile->versions()->latest('version')->limit(8)->get(),
            'roleOptions' => array_keys(SkillCatalog::ROLE_KEYWORDS),
            'countries' => Geo::countryNames(),
            'homeCountry' => (string) settings()->get('home_country'),
            'reviewed' => $profile->reviewed_at !== null && $pending === null,
        ]);
    }

    public function update(ProfileRequest $request, ProfileService $profiles): RedirectResponse
    {
        $d = $request->validated();
        $profile = $profiles->forUser($request->user());

        $d['structured']['portfolio_urls'] = preg_split('/[\s,]+/', (string) ($d['structured']['portfolio_urls'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $changed = $profiles->save($profile, $d, 'Owner review');

        if (array_key_exists('home_country', $d)) {
            settings()->set('home_country', trim((string) $d['home_country']));
        }

        if ($changed || $request->has('home_country')) {
            RecalculateMatches::dispatch(notify: false);
        }

        return redirect()->route('profile.edit')->with('status', $changed
            ? "Profile saved (version {$profile->refresh()->profile_version}). Jobs are being re-scored against it."
            : 'Profile reviewed — nothing had changed, so the current version stays.');
    }

    public function discardPending(Request $request, ProfileService $profiles): RedirectResponse
    {
        $profile = $profiles->forUser($request->user());
        $profile->update(['pending_extraction' => null]);

        return back()->with('status', 'Discarded the parsed CV data. Your saved profile is unchanged.');
    }
}
