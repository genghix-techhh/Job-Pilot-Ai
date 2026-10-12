<?php

namespace Tests\Concerns;

use App\Enums\SourceType;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\User;
use App\Services\Http\HostResolver;
use App\Services\Jobs\JobUpserter;
use App\Services\Jobs\NormalizedJob;
use App\Services\Profile\ProfileService;

trait JobPilotHelpers
{
    /** Never touch real DNS in tests. Unknown hosts resolve to a public address. */
    protected function fakeDns(array $map = []): void
    {
        $this->app->instance(HostResolver::class, new class($map) implements HostResolver
        {
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? ['93.184.216.34'];
            }
        });
    }

    protected function owner(array $attrs = []): User
    {
        // Single-owner app: asking twice returns the same owner.
        return User::query()->first() ?? User::create(array_merge([
            'name' => 'Ayesha Khan', 'email' => 'owner@example.test', 'password' => 'correct-horse-battery', 'timezone' => 'Asia/Karachi',
        ], $attrs));
    }

    /** A reviewed profile with AI consent given (policy version current). */
    protected function profile(User $user, array $over = [], bool $consent = true): CandidateProfile
    {
        $svc = app(ProfileService::class);
        $p = $svc->forUser($user);
        $svc->save($p, array_merge([
            'roles' => 'Full-stack Developer, Backend Developer',
            'skills' => 'PHP, Laravel, MySQL, JavaScript, Vue.js, Docker',
            'years_experience' => 4,
            'experience_levels' => ['junior', 'mid', 'senior'],
            'employment_types' => ['full_time', 'contract', 'freelance'],
            'structured' => [
                'name' => 'Ayesha Khan', 'headline' => 'Full-stack developer', 'email' => 'ayesha.khan@example.test', 'phone' => '+92 300 1234567',
                'summary' => 'Full-stack developer building Laravel and Vue applications.',
                'work_history' => [[
                    'title' => 'Full-stack Developer', 'company' => 'Acme Software', 'start' => 'Jan 2021', 'end' => '', 'current' => true,
                    'bullets' => "Built REST APIs in Laravel and MySQL serving 50k users\nCreated Vue.js dashboards for internal reporting\nDockerised services and automated deployments",
                ]],
                'projects' => [['name' => 'Invoice Tracker', 'description' => 'Laravel and MySQL app for invoices. Used Vue.js.', 'technologies' => 'Laravel, MySQL, Vue.js', 'url' => '']],
                'education' => [['degree' => 'BS Computer Science', 'school' => 'Some University', 'year' => '2020']],
                'certifications' => [],
            ],
        ], $over));

        if ($consent) {
            $svc->setConsent($p->refresh(), true);
        }

        return $p->refresh();
    }

    protected function source(SourceType $type = SourceType::Greenhouse, array $config = ['board_token' => 'acme'], string $name = 'Acme (Greenhouse)'): JobSource
    {
        return JobSource::create(['type' => $type, 'name' => $name, 'config' => $config, 'enabled' => true, 'health_status' => 'never']);
    }

    protected function job(JobSource|array|null $source = null, array $over = []): JobPosting
    {
        if (is_array($source)) {
            [$over, $source] = [$source, null];
        }
        $source ??= JobSource::query()->first() ?? $this->source();
        [$job] = app(JobUpserter::class)->upsert($source, new NormalizedJob(...array_merge([
            'title' => 'Senior Laravel Developer',
            'url' => 'https://boards.greenhouse.io/acme/jobs/'.random_int(1000, 999999),
            'externalId' => (string) random_int(1000, 999999),
            'company' => 'Acme',
            'description' => 'We need a Laravel developer. You will build REST APIs with PHP, Laravel and MySQL, work with Vue.js on the frontend and use Docker. 3+ years of experience required. Fully remote, work from anywhere in the world.',
            'location' => 'Remote - Worldwide',
            'isRemote' => true,
            'remoteScope' => 'worldwide',
            'postedAt' => now()->subDay(),
        ], $over)));

        return $job;
    }
}
