<?php

namespace App\Services\Applications;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Turns the plain-text cover letter / tailored CV into structured, safely escaped HTML and real PDFs.
 *
 * The stored text stays the source of truth (it is what you edited and approved); this class only
 * lays it out. Everything is HTML-escaped by the Blade view. The PDF engine runs with remote resources
 * and embedded PHP disabled, so a document can never trigger a network request or code execution.
 */
class DocumentRenderer
{
    private const SECTIONS = ['SUMMARY', 'SKILLS', 'EXPERIENCE', 'PROJECTS', 'EDUCATION', 'CERTIFICATIONS'];

    /**
     * @return array{kind: string, header: list<string>, sections: list<array{title: ?string, blocks: list<array{type: string, lines: list<string>}>}>}
     */
    public function structure(string $kind, string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        return $kind === 'resume' ? $this->resume($text) : $this->letter($text);
    }

    public function pdf(string $kind, string $text, string $title): string
    {
        $html = view('applications.document', [
            'doc' => $this->structure($kind, $text), 'title' => $title, 'print' => false, 'pdf' => true,
        ])->render();

        $tmp = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($tmp);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');       // full Unicode coverage (accents, dashes, CJK-free but safe)
        $options->set('tempDir', $tmp);
        $options->set('fontCache', $tmp);
        $options->set('chroot', [$tmp]);                   // the renderer may not read any other files

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->addInfo('Title', mb_substr($title, 0, 120));
        $pdf->addInfo('Creator', 'JobPilot AI');

        return (string) $pdf->output();
    }

    /** @return array{kind: string, header: list<string>, sections: list<array<string, mixed>>} */
    private function letter(string $text): array
    {
        $blocks = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $para) {
            $para = trim($para);
            if ($para !== '') {
                $blocks[] = ['type' => 'text', 'lines' => array_map('trim', explode("\n", $para))];
            }
        }

        return ['kind' => 'letter', 'header' => [], 'sections' => [['title' => null, 'blocks' => $blocks]]];
    }

    /** @return array{kind: string, header: list<string>, sections: list<array<string, mixed>>} */
    private function resume(string $text): array
    {
        $lines = explode("\n", $text);
        $header = [];
        $sections = [];
        $current = null;

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if (in_array(trim($line), self::SECTIONS, true)) {
                if ($current) {
                    $sections[] = $current;
                }
                $current = ['title' => ucfirst(strtolower(trim($line))), 'blocks' => []];

                continue;
            }
            if ($current === null) {
                if (trim($line) !== '') {
                    $header[] = trim($line);
                }

                continue;
            }
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (str_starts_with($trim, '- ')) {
                $last = count($current['blocks']) - 1;
                if ($last >= 0 && $current['blocks'][$last]['type'] === 'bullets') {
                    $current['blocks'][$last]['lines'][] = substr($trim, 2);
                } else {
                    $current['blocks'][] = ['type' => 'bullets', 'lines' => [substr($trim, 2)]];
                }
            } elseif ($current['title'] === 'Skills' || $current['title'] === 'Summary') {
                $current['blocks'][] = ['type' => 'text', 'lines' => [$trim]];
            } else {
                $current['blocks'][] = ['type' => 'heading', 'lines' => [$trim]];
            }
        }
        if ($current) {
            $sections[] = $current;
        }

        return ['kind' => 'resume', 'header' => $header, 'sections' => $sections];
    }
}
