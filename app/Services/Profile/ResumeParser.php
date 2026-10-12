<?php

namespace App\Services\Profile;

use App\Support\Text;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Extracts text from PDF / DOCX / TXT locally on the server. Nothing is executed:
 * DOCX is read as plain XML (macros, embedded objects and external links are ignored),
 * PDFs are only text-extracted. Scanned/image-only PDFs are reported, not OCR'd.
 */
class ResumeParser
{
    public const MIN_TEXT_CHARS = 40;

    /** @return array{status: string, text: string, error: ?string} status: parsed|empty|failed */
    public function parse(string $absolutePath, string $extension): array
    {
        try {
            $text = match (strtolower($extension)) {
                'txt' => $this->txt($absolutePath),
                'docx' => $this->docx($absolutePath),
                'pdf' => $this->pdf($absolutePath),
                default => throw new \InvalidArgumentException('Unsupported file type.'),
            };
        } catch (Throwable $e) {
            report_safely($e);

            return ['status' => 'failed', 'text' => '', 'error' => 'The file could not be read ('.class_basename($e).'). It may be corrupt or password-protected.'];
        }

        $text = Text::normalizeWhitespace($text);

        if (mb_strlen($text) < self::MIN_TEXT_CHARS) {
            $hint = strtolower($extension) === 'pdf'
                ? 'No text layer was found. This looks like a scanned or image-only PDF. OCR is not part of this version — export a text PDF/DOCX from your editor, or paste the content into a .txt file.'
                : 'The document contains almost no text.';

            return ['status' => 'empty', 'text' => $text, 'error' => $hint];
        }

        return ['status' => 'parsed', 'text' => Text::limit($text, 120_000), 'error' => null];
    }

    private function txt(string $path): string
    {
        $raw = (string) file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Not a valid DOCX archive.');
        }

        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false) {
                throw new \RuntimeException('word/document.xml missing.');
            }
            $limit = (int) config('jobpilot.uploads.docx_max_xml_bytes');
            if ($stat['size'] > $limit) {
                throw new \RuntimeException('Document body is unreasonably large.');
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if ($xml === false || $xml === '') {
            throw new \RuntimeException('Empty document body.');
        }

        return $this->docxXmlToText($xml);
    }

    public function docxXmlToText(string $xml): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        // LIBXML_NONET: never fetch external resources. No DTD/entity substitution is enabled.
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new \RuntimeException('Document XML is malformed.');
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $lines = [];
        foreach ($xp->query('//w:p') ?: [] as $p) {
            $line = '';
            foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) ?: [] as $node) {
                $line .= match ($node->localName) {
                    'tab' => "\t",
                    'br' => "\n",
                    default => $node->textContent,
                };
            }
            // Bullet paragraphs carry numbering properties; keep them recognisable.
            if ($xp->query('./w:pPr/w:numPr', $p)->length > 0 && trim($line) !== '') {
                $line = '- '.ltrim($line);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function pdf(string $path): string
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        return (new Parser([], $config))->parseFile($path)->getText();
    }
}
