<?php

namespace App\Http\Controllers;

use App\Models\SavedSearch;
use App\Services\Jobs\JobFeedQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80']] + JobFeedQuery::rules());

        $filters = collect($d)->only(JobFeedQuery::SEARCH_KEYS)->filter(fn ($v) => $v !== null && $v !== '' && $v !== '0')->all();
        if ($filters === []) {
            return back()->with('error', 'Set at least one filter before saving a search — an empty search is just the whole feed.');
        }
        if (SavedSearch::query()->where('user_id', $request->user()->id)->count() >= 25) {
            return back()->with('error', 'You can keep up to 25 saved searches. Delete one first.');
        }

        SavedSearch::updateOrCreate(
            ['user_id' => $request->user()->id, 'name' => trim($d['name'])],
            ['filters' => $filters, 'digest' => true]
        );

        return back()->with('status', 'Search saved. It will also feed your e-mail digest (toggle that on the chip).');
    }

    public function update(Request $request, SavedSearch $search): RedirectResponse
    {
        abort_unless($search->user_id === $request->user()->id, 403);
        $search->update(['digest' => ! $search->digest]);

        return back()->with('status', $search->digest ? 'Included in your digest.' : 'No longer included in your digest.');
    }

    public function destroy(Request $request, SavedSearch $search): RedirectResponse
    {
        abort_unless($search->user_id === $request->user()->id, 403);
        $search->delete();

        return back()->with('status', 'Saved search deleted.');
    }
}
