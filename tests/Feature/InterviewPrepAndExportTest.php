<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Applications\DraftService;
use App\Services\Applications\InterviewPrepService;
use App\Services\Matching\SkillCatalog;
use App\Services\Profile\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class InterviewPrepAndExportTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['jobpilot.gemini.api_key' => null]);
        $this->owner = $this->owner();
        $this->profile($this->owner);
        $job = $this->job(null, ['title' => 'Senior Laravel Developer', 'description' => "You will build APIs with PHP, Laravel and MySQL, deploy with Docker and Kubernetes.\nNice to have\nRedis"]);
        $this->application = app(DraftService::class)->create($this->owner, $job);
    }

    // ---- skill mention helper ---------------------------------------------------------------------

    public function test_skill_mentions_use_aliases_and_word_boundaries(): void
    {
        $this->assertTrue(SkillCatalog::mentions('Built REST APIs with node js and nodejs', 'Node.js'));
        $this->assertTrue(SkillCatalog::mentions('worked on k8s clusters', 'Kubernetes'));
        $this->assertFalse(SkillCatalog::mentions('JavaScript heavy work', 'Java'));
        $this->assertFalse(SkillCatalog::mentions('nothing relevant here', 'Laravel'));
    }

    // ---- offline plan -------------------------------------------------------------------------------

    public function test_offline_plan_links_required_skills_to_real_evidence_and_flags_gaps(): void
    {
        $plan = app(InterviewPrepService::class)->local($this->application);

        $topics = collect($plan['topics'])->keyBy('skill');
        $this->assertTrue($topics['Laravel']['have']);
        $this->assertTrue($topics['Laravel']['required']);
        $this->assertStringContainsString('Built REST APIs in Laravel', $topics['Laravel']['evidence'][0]);
        $this->assertFalse($topics['Kubernetes']['have']);
        $this->assertSame([], $topics['Kubernetes']['evidence']);
        $this->assertStringContainsString('upfront', $topics['Kubernetes']['advice']);
        $this->assertFalse($topics['Redis']['required'], 'a nice-to-have is marked as such');

        $questions = collect($plan['questions']);
        $this->assertTrue($questions->contains(fn ($q) => str_contains($q['question'], 'project where you used Laravel')));
        $this->assertTrue($questions->contains(fn ($q) => str_contains($q['question'], 'This role uses Kubernetes')), 'a required gap gets an honest "how would you ramp up" question');
        $this->assertTrue($questions->contains(fn ($q) => $q['category'] === 'behavioural'));
        $this->assertTrue($questions->contains(fn ($q) => $q['category'] === 'logistics'));
        $this->assertNotEmpty($plan['stories']);
        $this->assertSame('local', $plan['generator']);
        $this->assertContains('Kubernetes', array_column($plan['gap_plan'], 'skill'));
    }

    public function test_outlines_never_exist_without_evidence(): void
    {
        $plan = app(InterviewPrepService::class)->local($this->application);
        foreach ($plan['questions'] as $q) {
            if ($q['answer_outline'] !== '') {
                $this->assertNotEmpty($q['evidence'], 'an answer outline must always come with the evidence it is based on');
            }
        }
    }

    public function test_prep_page_renders_offline_without_any_network_call(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->get(route('applications.prep', $this->application))->assertOk()
            ->assertSee('Offline plan')->assertSee('Likely questions')->assertSee('Story seeds')->assertSee('Closing the gaps honestly')->assertSee('Gap');
        Http::assertNothingSent();
        $this->actingAs($this->owner)->get(route('applications.show', $this->application))->assertSee('Interview prep');
    }

    public function test_prep_is_owner_only(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'another-long-password', 'timezone' => 'UTC']);
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($other), false);
        $this->actingAs($other)->get(route('applications.prep', $this->application))->assertForbidden();
        $this->actingAs($other)->post(route('applications.prep.generate', $this->application))->assertForbidden();
    }

    // ---- Gemini upgrade -------------------------------------------------------------------------------

    private function fakePrep(array $json): void
    {
        config(['jobpilot.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]], 'finishReason' => 'STOP']]], 200)]);
    }

    public function test_ai_prep_keeps_only_evidence_backed_outlines_and_sends_no_pii(): void
    {
        $this->fakePrep([
            'questions' => [
                ['category' => 'technical', 'question' => 'Walk me through a Laravel API you built.', 'why_asked' => 'Core skill.', 'answer_outline' => 'Describe the REST API work.', 'evidence_ids' => ['W1.1']],
                ['category' => 'technical', 'question' => 'Tell me about your Kubernetes clusters.', 'why_asked' => 'Required.', 'answer_outline' => 'I ran 50 clusters at Google.', 'evidence_ids' => ['W42.9']],
                ['category' => 'behavioural', 'question' => 'Describe a <script>alert(1)</script> conflict.', 'why_asked' => 'Teamwork.', 'answer_outline' => '', 'evidence_ids' => []],
            ],
            'gap_plan' => [['skill' => 'Kubernetes', 'how_to_prepare' => 'Build a small cluster locally and deploy one app.']],
            'questions_to_ask' => ['How is on-call handled?'],
        ]);

        $this->actingAs($this->owner)->post(route('applications.prep.generate', $this->application))->assertSessionHas('status');

        $plan = $this->application->refresh()->artifacts['prep'];
        $this->assertSame('gemini', $plan['generator']);
        $qs = collect($plan['questions']);
        $this->assertStringContainsString('Describe the REST API work', $qs[0]['answer_outline']);
        $this->assertStringContainsString('Built REST APIs in Laravel', $qs[0]['evidence'][0]);
        $this->assertSame('', $qs[1]['answer_outline'], 'an outline citing evidence that does not exist is discarded');
        $this->assertSame([], $qs[1]['evidence']);
        $this->assertStringNotContainsString('<script>', json_encode($plan), 'free text is sanitised');
        $this->assertSame(['Kubernetes'], array_column($plan['gap_plan'], 'skill'));

        $sent = collect(Http::recorded())->map(fn ($p) => json_encode($p[0]->data()))->implode("\n");
        foreach (['Ayesha', 'Khan', 'ayesha.khan@example.test', '+92 300', 'Acme Software', 'Some University'] as $secret) {
            $this->assertStringNotContainsString($secret, $sent, "leaked to Gemini: {$secret}");
        }

        $this->actingAs($this->owner)->get(route('applications.prep', $this->application))->assertOk()->assertSee('Improved with Gemini')->assertSee('Walk me through a Laravel API');
        $this->actingAs($this->owner)->delete(route('applications.prep.reset', $this->application));
        $this->assertArrayNotHasKey('prep', $this->application->refresh()->artifacts);
    }

    public function test_ai_prep_failure_is_explained_and_changes_nothing(): void
    {
        config(['jobpilot.gemini.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'message' => 'denied']], 403)]);

        $this->actingAs($this->owner)->post(route('applications.prep.generate', $this->application))->assertSessionHas('error');
        $this->assertArrayNotHasKey('prep', (array) $this->application->refresh()->artifacts);
    }

    public function test_ai_button_is_hidden_with_a_reason_when_ai_is_unavailable(): void
    {
        $this->actingAs($this->owner)->get(route('applications.prep', $this->application))->assertDontSee('Improve with Gemini')->assertSee('AI is not available');
        $this->fakePrep(['questions' => [], 'gap_plan' => [], 'questions_to_ask' => []]);
        $this->actingAs($this->owner)->get(route('applications.prep', $this->application))->assertSee('Improve with Gemini');
    }

    // ---- data export -------------------------------------------------------------------------------------

    public function test_export_is_a_complete_json_file_without_any_secret(): void
    {
        config(['jobpilot.gemini.api_key' => 'AIzaSyEXPORT-SECRET-KEY-1234567890', 'services.google.client_secret' => 'GOOGLE-EXPORT-SECRET']);
        $this->source(SourceType::Adzuna, ['app_id' => 'id', 'app_key' => 'ADZUNA-EXPORT-SECRET', 'country' => 'gb', 'what' => 'php'], 'Adz');
        ConnectedAccount::create(['user_id' => $this->owner->id, 'provider' => 'google', 'email' => 'me@gmail.test', 'access_token' => 'ACCESS-EXPORT-SECRET', 'refresh_token' => 'REFRESH-EXPORT-SECRET', 'status' => 'connected']);

        $res = $this->actingAs($this->owner)->get(route('settings.export'))->assertOk();
        $body = $res->getContent();

        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('jobpilot-export-', $res->headers->get('Content-Disposition'));
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('jobpilot-export-1', $data['format']);
        $this->assertSame('Ayesha Khan', $data['profile']['structured']['name']);
        $this->assertContains('Laravel', $data['profile']['skills']);
        $this->assertCount(1, $data['applications']);
        $this->assertStringContainsString('Dear Hiring Team', $data['applications'][0]['cover_letter']);
        $this->assertSame('draft_ready', $data['applications'][0]['status']);
        $this->assertNotEmpty($data['applications'][0]['events']);
        $adz = collect($data['sources'])->firstWhere('name', 'Adz');
        $this->assertSame('adzuna', $adz['type']);
        $this->assertArrayNotHasKey('config', $adz);

        foreach (['SUPER', 'EXPORT-SECRET', 'GOOGLE-EXPORT', 'ADZUNA-EXPORT', 'ACCESS-EXPORT', 'REFRESH-EXPORT', $this->owner->password] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "export leaked: {$secret}");
        }
        $this->assertTrue(AuditLog::where('action', 'privacy.data_exported')->exists());
    }

    public function test_export_requires_login(): void
    {
        auth()->logout();
        $this->get(route('settings.export'))->assertRedirect(route('login'));
    }

    public function test_settings_page_offers_the_export(): void
    {
        $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk()->assertSee('Export my data')->assertSee('Download JSON export');
    }
}
