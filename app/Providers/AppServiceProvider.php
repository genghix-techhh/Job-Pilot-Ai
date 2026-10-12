<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\InboundMessage;
use App\Models\Interview;
use App\Models\Resume;
use App\Policies\InboundMessagePolicy;
use App\Policies\OwnedPolicy;
use App\Services\Ai\AiProvider;
use App\Services\Ai\GeminiService;
use App\Services\Applications\Submission\SubmissionManager;
use App\Services\Http\DnsHostResolver;
use App\Services\Http\HostResolver;
use App\Services\Matching\MatchService;
use App\Services\SettingsService;
use App\View\Composers\NavComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped = reset between queue jobs, so a long-running worker never uses stale settings.
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(MatchService::class);

        $this->app->singleton(AiProvider::class, GeminiService::class);
        $this->app->singleton(HostResolver::class, DnsHostResolver::class);
        // No submission gateways are registered: public job-board APIs cannot accept candidate
        // applications, and restricted platforms are intentionally not automated.
        $this->app->singleton(SubmissionManager::class);
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Behind a reverse proxy the real client IP and scheme come from X-Forwarded-* headers.
        if ($proxies = config('jobpilot.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        // Catch accidental N+1 queries while testing.
        Model::preventLazyLoading($this->app->environment('testing'));

        View::composer('partials.nav', NavComposer::class);

        Gate::policy(Application::class, OwnedPolicy::class);
        Gate::policy(Resume::class, OwnedPolicy::class);
        Gate::policy(Interview::class, OwnedPolicy::class);
        Gate::policy(InboundMessage::class, InboundMessagePolicy::class);

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        RateLimiter::for('heavy', fn (Request $r) => Limit::perMinute(10)->by((string) ($r->user()?->id ?: $r->ip())));

        Vite::prefetch(concurrency: 3);
    }
}
