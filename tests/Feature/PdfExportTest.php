<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\DocumentRenderer;
use App\Services\Applications\DraftService;
use App\Services\Profile\ProfileService;
use App\Services\Profile\ResumeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class PdfExportTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobpilot.gemini.api_key' => null]);
        $this->owner = $this->owner();
        $this->profile($this->owner);
        $this->application = app(DraftService::class)->create($this->owner, $this->job());
    }

    private function textOfPdf(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $bytes);
        try {
            return app(ResumeParser::class)->parse($path, 'pdf')['text'];
        } finally {
            @unlink($path);
        }
    }

    public function test_resume_pdf_is_a_real_pdf_with_selectable_text_and_structure(): void
    {
        $res = $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'resume', 'format' => 'pdf']))->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', $res->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.pdf"', $res->headers->get('Content-Disposition'));
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);

        $text = $this->textOfPdf($bytes);
        $this->assertStringContainsString('Ayesha Khan', $text);
        $this->assertStringContainsString('Built REST APIs in Laravel and MySQL serving 50k users', $text);
        $this->assertStringContainsString('EXPERIENCE', strtoupper($text));
    }

    public function test_cover_letter_pdf_contains_the_letter(): void
    {
        $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'cover-letter', 'format' => 'pdf']))->assertOk();
        $bytes = $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'cover-letter', 'format' => 'pdf']))->getContent();
        $text = $this->textOfPdf($bytes);
        $this->assertStringContainsString('Dear Hiring Team', $text);
        $this->assertStringContainsString('Kind regards', $text);
    }

    public function test_pdf_reflects_the_owners_edits_and_handles_unicode(): void
    {
        app(ApplicationService::class)->saveDraft($this->application, ['cover_letter' => "Dear Team,\n\nI built a café-booking system – résumé of results: naïve → fast.\n\nKind regards,\nAyesha"]);
        $bytes = $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'cover-letter', 'format' => 'pdf']))->getContent();
        $text = $this->textOfPdf($bytes);
        $this->assertStringContainsString('café', $text);
        $this->assertStringContainsString('résumé', $text);
    }

    public function test_hostile_markup_in_the_document_is_escaped_not_executed_or_fetched(): void
    {
        $evil = "Dear Team,\n\n<script>alert(1)</script> <img src=\"http://169.254.169.254/latest/meta-data\"> <iframe src=\"file:///etc/passwd\"></iframe> {{ 7*7 }}\n\nRegards";
        app(ApplicationService::class)->saveDraft($this->application, ['cover_letter' => $evil]);

        $html = $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'cover-letter', 'format' => 'html']))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('{{ 7*7 }}', $html, 'template syntax in user text is literal text');

        Http::fake();
        $bytes = $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'cover-letter', 'format' => 'pdf']))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        Http::assertNothingSent();
        $this->assertStringContainsString('<script>', $this->textOfPdf($bytes), 'shown as text inside the PDF, never executed');
    }

    public function test_other_formats_still_work_and_unknown_ones_404(): void
    {
        foreach (['txt' => 'text/plain', 'html' => 'text/html'] as $fmt => $type) {
            $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'resume', 'format' => $fmt]))->assertOk()->assertHeader('Content-Type', $type.'; charset=utf-8');
        }
        $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'resume', 'format' => 'print']))->assertOk()->assertSee('window.print', false);
        $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'resume', 'format' => 'docx']))->assertNotFound();
    }

    public function test_exports_are_owner_only_and_not_cacheable(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'another-long-password', 'timezone' => 'UTC']);
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($other), false);

        $this->actingAs($other)->get(route('applications.export', [$this->application, 'resume', 'format' => 'pdf']))->assertForbidden();
        $this->actingAs($this->owner)->get(route('applications.export', [$this->application, 'resume', 'format' => 'pdf']))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_studio_page_offers_pdf_downloads(): void
    {
        $this->actingAs($this->owner)->get(route('applications.show', $this->application))->assertOk()->assertSee('format=pdf', false);
    }

    public function test_resume_structure_parser(): void
    {
        $doc = app(DocumentRenderer::class)->structure('resume', "Jane Doe\nEngineer\njane@x.test | +1 555\n\nSUMMARY\nBuilder of things.\n\nSKILLS\nPHP, Laravel\n\nEXPERIENCE\nDev — Acme (2020 – Present)\n- Did A\n- Did B\nOps — Beta (2018 – 2020)\n- Did C");
        $this->assertSame(['Jane Doe', 'Engineer', 'jane@x.test | +1 555'], $doc['header']);
        $this->assertSame(['Summary', 'Skills', 'Experience'], array_column($doc['sections'], 'title'));
        $exp = $doc['sections'][2]['blocks'];
        $this->assertSame(['heading', 'bullets', 'heading', 'bullets'], array_column($exp, 'type'));
        $this->assertSame(['Did A', 'Did B'], $exp[1]['lines']);
    }
}
