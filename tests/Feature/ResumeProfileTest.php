<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\User;
use App\Services\Matching\MatchService;
use App\Services\Profile\LocalProfileExtractor;
use App\Services\Profile\ProfileService;
use App\Services\Profile\ResumeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class ResumeProfileTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private const CV = <<<'TXT'
Ayesha Khan
Full-stack Developer
ayesha.khan@example.test | +92 300 1234567 | https://github.com/ayeshak

SUMMARY
Full-stack developer with four years of experience building Laravel and Vue.js applications.

EXPERIENCE
Full-stack Developer, Acme Software    Jan 2021 - Present
- Built REST APIs in Laravel and MySQL serving 50k users
- Created Vue.js dashboards for internal reporting

Junior PHP Developer at Beta Studio    Jun 2019 - Dec 2020
- Maintained WordPress and PHP sites for 12 clients

PROJECTS
Invoice Tracker - Laravel app for freelancers to track invoices
- Built with Laravel, MySQL and Vue.js

EDUCATION
BS Computer Science
Some University 2019

SKILLS
PHP, Laravel, MySQL, JavaScript, Vue.js, Docker, Git
TXT;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('resumes');
        config(['jobpilot.gemini.api_key' => null]);
    }

    private function user()
    {
        $u = $this->owner();
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($u), false);

        return $u;
    }

    /** Smallest valid single-page PDF with (or without) a text layer. */
    private function pdf(string $text): string
    {
        $content = $text === '' ? '' : "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }

        return $out."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function docx(string $text, bool $withBody = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        if ($withBody) {
            $paras = implode('', array_map(fn ($l) => '<w:p><w:r><w:t>'.htmlspecialchars($l).'</w:t></w:r></w:p>', explode("\n", $text)));
            $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paras.'</w:body></w:document>');
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function upload(string $name, string $bytes, $user = null)
    {
        return $this->actingAs($user ?? $this->user())->post(route('resume.store'), ['cv' => UploadedFile::fake()->createWithContent($name, $bytes)]);
    }

    // ---- validation -------------------------------------------------------------------------

    public function test_only_pdf_docx_txt_are_accepted(): void
    {
        $u = $this->user();
        foreach (['evil.php', 'run.exe', 'page.html', 'photo.png', 'macro.docm', 'x.pdf.php'] as $name) {
            $this->upload($name, 'some content '.str_repeat('x', 100), $u)->assertSessionHasErrors('cv');
        }
        $this->assertSame(0, Resume::count());
    }

    public function test_file_content_must_match_the_extension(): void
    {
        $u = $this->user();
        $this->upload('cv.pdf', "MZ\x90\x00 this is really an exe", $u)->assertSessionHasErrors('cv');
        $this->upload('cv.docx', 'plain text pretending to be docx', $u)->assertSessionHasErrors('cv');
        $this->upload('cv.txt', "binary\0\0\0data", $u)->assertSessionHasErrors('cv');
        $this->assertSame(0, Resume::count());
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['jobpilot.uploads.max_kb' => 10]);
        $this->upload('cv.txt', str_repeat('PHP developer ', 2000))->assertSessionHasErrors('cv');
        $this->assertSame(0, Resume::count());
    }

    // ---- private storage --------------------------------------------------------------------

    public function test_cv_is_stored_privately_with_a_random_name_and_never_publicly_served(): void
    {
        $u = $this->user();
        $this->upload('My Secret CV ../../x.txt', self::CV, $u)->assertSessionHasNoErrors();

        $r = Resume::first();
        Storage::disk('resumes')->assertExists($r->disk_path);
        $this->assertStringNotContainsString('Secret', $r->disk_path, 'stored name must not be the user-supplied one');
        $this->assertMatchesRegularExpression('#^u\d+/[0-9a-f\-]{36}\.txt$#', $r->disk_path);
        $this->assertSame(hash('sha256', self::CV), $r->checksum);
        $this->assertStringNotContainsString('..', $r->original_filename);

        // The disk is private: it is not under public/ and is not served.
        $cfg = config('filesystems.disks.resumes');
        $this->assertFalse($cfg['serve']);
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'public', $cfg['root']);
        $this->get('/storage/'.$r->disk_path)->assertNotFound();
    }

    public function test_download_requires_login_and_the_owner(): void
    {
        $u = $this->user();
        $this->upload('cv.txt', self::CV, $u);
        $r = Resume::first();

        auth()->logout();
        $this->get(route('resume.download', $r))->assertRedirect(route('login'));

        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'another-long-password', 'timezone' => 'UTC']);
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($other), false);
        $this->actingAs($other)->get(route('resume.download', $r))->assertForbidden();

        $this->actingAs($u)->get(route('resume.download', $r))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ---- parsing ----------------------------------------------------------------------------

    public function test_txt_upload_is_parsed_locally_and_staged_for_review_without_any_network_call(): void
    {
        Http::fake();
        $u = $this->user();
        $this->upload('cv.txt', self::CV, $u)->assertRedirect(route('profile.edit'));

        Http::assertNothingSent();
        $p = $u->refresh()->profile;
        $pending = $p->pending_extraction;
        $this->assertNotNull($pending);
        $this->assertContains('Laravel', $pending['skills']);
        $this->assertContains('Vue.js', $pending['skills']);
        $this->assertSame('Ayesha Khan', $pending['structured']['name']);
        $this->assertSame('ayesha.khan@example.test', $pending['structured']['email']);
        $this->assertCount(2, $pending['structured']['work_history']);
        $this->assertSame('Full-stack Developer', $pending['structured']['work_history'][0]['title']);
        $this->assertTrue($pending['structured']['work_history'][0]['current']);
        $this->assertGreaterThan(3.5, $pending['years_experience']);
        $this->assertSame(0, $p->profile_version, 'nothing is used for matching until the owner reviews and saves');
        $this->assertNull(app(MatchService::class)->activeProfile());
    }

    public function test_docx_is_parsed(): void
    {
        $u = $this->user();
        $this->upload('cv.docx', $this->docx(self::CV), $u)->assertSessionHasNoErrors();
        $r = Resume::first();
        $this->assertSame('parsed', $r->parse_status);
        $this->assertStringContainsString('Built REST APIs in Laravel', $r->parsed_text);
    }

    public function test_pdf_with_a_text_layer_is_parsed(): void
    {
        $u = $this->user();
        $this->upload('cv.pdf', $this->pdf('Ayesha Khan - Full-stack developer with Laravel PHP MySQL and Vue.js experience'), $u)->assertSessionHasNoErrors();
        $r = Resume::first();
        $this->assertSame('parsed', $r->parse_status, (string) $r->parse_error);
        $this->assertStringContainsString('Laravel', $r->parsed_text);
    }

    public function test_scanned_pdf_without_text_is_reported_clearly(): void
    {
        $u = $this->user();
        $this->upload('scan.pdf', $this->pdf(''), $u)->assertSessionHas('error');
        $r = Resume::first();
        $this->assertSame('empty', $r->parse_status);
        $this->assertStringContainsString('OCR', $r->parse_error);
        $this->assertFalse($r->is_active);
        $this->assertNull($u->refresh()->profile->pending_extraction);
    }

    public function test_corrupt_documents_fail_cleanly_without_a_500(): void
    {
        $u = $this->user();
        $this->upload('broken.pdf', "%PDF-1.4\n garbage garbage no objects", $u)->assertSessionHas('error');
        $this->upload('broken.docx', $this->docx('x', withBody: false), $u)->assertSessionHasErrors('cv');
        $this->assertNotContains('parsed', Resume::pluck('parse_status')->all());
    }

    public function test_docx_xml_with_external_entities_does_not_read_local_files(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'sec');
        file_put_contents($secret, 'TOP-SECRET-CONTENT');
        $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://'.str_replace('\\', '/', $secret).'">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';

        try {
            $text = app(ResumeParser::class)->docxXmlToText($xml);
        } catch (\Throwable) {
            $text = '';
        }
        @unlink($secret);
        $this->assertStringNotContainsString('TOP-SECRET', $text);
    }

    public function test_replacing_a_cv_keeps_versions_and_switches_the_active_one(): void
    {
        $u = $this->user();
        $this->upload('one.txt', self::CV, $u);
        $this->upload('two.txt', self::CV."\n- Another line about Docker deployments", $u);

        $this->assertSame([1, 2], Resume::orderBy('version')->pluck('version')->all());
        $this->assertSame([false, true], Resume::orderBy('version')->pluck('is_active')->all());

        $this->actingAs($u)->delete(route('resume.destroy', Resume::where('is_active', true)->first()))->assertSessionHas('error');
        $this->actingAs($u)->delete(route('resume.destroy', Resume::where('is_active', false)->first()))->assertSessionHas('status');
        $this->assertSame(1, Resume::count());
    }

    // ---- local extractor ---------------------------------------------------------------------

    public function test_local_extractor_finds_structure_without_inventing_anything(): void
    {
        $x = (new LocalProfileExtractor)->extract(self::CV);
        $this->assertSame(['Docker', 'Git'], array_values(array_intersect(['Docker', 'Git', 'Kubernetes'], $x['skills'])));
        $this->assertNotContains('Kubernetes', $x['skills']);
        $this->assertSame('BS Computer Science', $x['structured']['education'][0]['degree']);
        $this->assertSame('Invoice Tracker', $x['structured']['projects'][0]['name']);
        $this->assertContains('Laravel', $x['structured']['projects'][0]['technologies']);

        $empty = (new LocalProfileExtractor)->extract("Just a few words here.\nNothing structured.");
        $this->assertSame([], $empty['structured']['work_history']);
        $this->assertNull($empty['years_experience']);
    }

    // ---- profile review & versions ------------------------------------------------------------

    public function test_saving_the_reviewed_profile_creates_versions_only_when_something_changes(): void
    {
        $u = $this->user();
        $this->upload('cv.txt', self::CV, $u);
        $payload = fn (array $o = []) => array_merge([
            'skills' => 'PHP, Laravel, MySQL', 'roles' => 'Backend Developer', 'years_experience' => 4,
            'structured' => ['name' => 'Ayesha Khan', 'headline' => 'Dev', 'work_history' => [['title' => 'Dev', 'company' => 'Acme', 'bullets' => "Did things\nDid more"]]],
        ], $o);

        $this->actingAs($u)->put(route('profile.update'), $payload())->assertRedirect(route('profile.edit'));
        $p = $u->refresh()->profile;
        $this->assertSame(1, $p->profile_version);
        $this->assertNull($p->pending_extraction);
        $this->assertNotNull($p->reviewed_at);
        $this->assertSame(['Did things', 'Did more'], $p->structured['work_history'][0]['bullets']);

        $this->actingAs($u)->put(route('profile.update'), $payload());
        $this->assertSame(1, $u->refresh()->profile->profile_version, 'identical save keeps the version');

        $this->actingAs($u)->put(route('profile.update'), $payload(['skills' => 'PHP, Laravel, MySQL, Redis']));
        $p = $u->refresh()->profile;
        $this->assertSame(2, $p->profile_version);
        $this->assertSame(2, $p->versions()->count());
        $this->assertSame(['PHP', 'Laravel', 'MySQL'], $p->versions()->where('version', 1)->first()->data['skills'], 'old versions are immutable snapshots');
        $this->assertNotNull(app(MatchService::class)->activeProfile());
    }

    public function test_profile_form_validates_and_rejects_bad_urls(): void
    {
        $u = $this->user();
        $this->actingAs($u)->put(route('profile.update'), ['structured' => ['email' => 'not-an-email', 'links' => [['label' => 'x', 'url' => 'javascript:alert(1)']]]])
            ->assertSessionHasErrors(['structured.email', 'structured.links.0.url']);
    }

    public function test_profile_page_renders_with_a_pending_cv(): void
    {
        $u = $this->user();
        $this->upload('cv.txt', self::CV, $u);
        $this->actingAs($u)->get(route('profile.edit'))->assertOk()->assertSee('Review needed')->assertSee('Built REST APIs in Laravel');
    }
}
