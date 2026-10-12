<?php

namespace App\Http\Controllers;

use App\Enums\SourceType;
use App\Jobs\SyncJobSource;
use App\Models\Application;
use App\Models\JobSource;
use App\Models\SourceSyncRun;
use App\Services\Jobs\ConnectorRegistry;
use App\Services\Jobs\Connectors\AdzunaConnector;
use App\Services\Jobs\JobSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(ConnectorRegistry $registry): View
    {
        $sources = JobSource::query()->withCount('jobs')->orderBy('type')->orderBy('name')->get();

        $lastRuns = SourceSyncRun::query()->whereIn('job_source_id', $sources->pluck('id'))
            ->latest('id')->get()->unique('job_source_id')->keyBy('job_source_id');

        return view('sources.index', [
            'sources' => $sources,
            'lastRuns' => $lastRuns,
            'types' => $registry->addableTypes(),
            'adzunaCountries' => AdzunaConnector::COUNTRIES,
            'adzunaEnv' => filled(config('jobpilot.adzuna.app_id')) && filled(config('jobpilot.adzuna.app_key')),
        ]);
    }

    public function store(Request $request, ConnectorRegistry $registry): RedirectResponse
    {
        $type = SourceType::tryFrom((string) $request->input('type'));
        if (! $type || $type === SourceType::Manual) {
            return back()->with('error', 'Choose a source type.')->withInput();
        }

        // Each connector validates its own fields and throws a ValidationException the form can display.
        $clean = $registry->for($type)->validateConfig($request->all());

        foreach (JobSource::query()->where('type', $type->value)->get() as $existing) {
            if ($existing->config === $clean['config']) {
                return back()->with('error', 'That source is already added ("'.$existing->name.'").')->withInput();
            }
        }

        $source = JobSource::create([
            'type' => $type,
            'name' => $clean['name'],
            'config' => $clean['config'],
            'enabled' => true,
            'health_status' => 'never',
            'next_sync_at' => now(),
        ]);

        SyncJobSource::dispatch($source->id, 'manual');

        return redirect()->route('sources.index')->with('status', "Added \"{$source->name}\". The first fetch is queued — jobs appear in your feed within a minute or two.");
    }

    public function update(Request $request, JobSource $source): RedirectResponse
    {
        $d = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        if (array_key_exists('enabled', $d)) {
            $source->enabled = (bool) $d['enabled'];
            $source->health_status = $source->enabled ? $source->health_status : 'disabled';
            if ($source->enabled) {
                $source->next_sync_at = now();
                $source->health_status = $source->health_status === 'disabled' ? 'never' : $source->health_status;
            }
        }
        if (! empty($d['name'])) {
            $source->name = $d['name'];
        }
        $source->save();

        return back()->with('status', $source->enabled ? 'Source enabled.' : 'Source disabled — it will not be fetched.');
    }

    public function test(JobSource $source, JobSyncService $sync): RedirectResponse
    {
        $r = $sync->test($source);

        return back()->with($r->ok ? 'status' : 'error', ($r->ok ? 'Test passed: ' : 'Test failed: ').$r->message);
    }

    public function sync(JobSource $source): RedirectResponse
    {
        if (! $source->type->isSyncable()) {
            return back()->with('error', 'Manual imports have nothing to fetch.');
        }
        SyncJobSource::dispatch($source->id, 'manual');

        return back()->with('status', 'Sync queued for "'.$source->name.'".');
    }

    public function syncAll(): RedirectResponse
    {
        $n = 0;
        JobSource::query()->where('enabled', true)->get()->filter(fn ($s) => $s->type->isSyncable())->each(function ($s) use (&$n) {
            SyncJobSource::dispatch($s->id, 'manual')->delay(now()->addSeconds($n * 2));
            $n++;
        });

        return back()->with($n ? 'status' : 'error', $n
            ? "Discovery queued for {$n} source(s). Results appear as the queue processes them."
            : 'No enabled sources yet — add one first.');
    }

    public function destroy(JobSource $source): RedirectResponse
    {
        if ($source->type === SourceType::Manual) {
            return back()->with('error', 'The manual-import bucket cannot be deleted.');
        }
        $hasApplications = Application::query()->whereIn('job_id', $source->jobs()->select('id'))->exists();
        if ($hasApplications) {
            return back()->with('error', 'You have applications for jobs from this source. Disable it instead so your history stays intact.');
        }
        $name = $source->name;
        $source->delete();

        return back()->with('status', "Deleted \"{$name}\" and its fetched jobs.");
    }
}
