<?php

namespace App\Services\Profile;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Server-side content check for CV uploads. The extension and the browser-sent MIME
 * type are not trusted: the file's real signature must match what it claims to be.
 */
class ResumeInspector
{
    /**
     * @return array{ok: bool, extension: ?string, mime: ?string, error: ?string}
     */
    public function inspect(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = config('jobpilot.uploads.extensions');
        if (! in_array($ext, $allowed, true)) {
            return $this->fail('Only PDF, DOCX and TXT files are accepted.');
        }

        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            return $this->fail('The uploaded file could not be read.');
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);

        return match ($ext) {
            'pdf' => str_starts_with($head, '%PDF-')
                ? $this->ok('pdf', 'application/pdf')
                : $this->fail('This file is not a real PDF document.'),
            'docx' => $this->inspectDocx($path, $head),
            'txt' => $this->inspectTxt($path),
        };
    }

    private function inspectDocx(string $path, string $head): array
    {
        if (! str_starts_with($head, "PK\x03\x04")) {
            return $this->fail('This file is not a real DOCX document.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $this->fail('This DOCX file is corrupt.');
        }
        $hasBody = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
        $count = $zip->numFiles;
        $zip->close();

        if (! $hasBody) {
            return $this->fail('This archive is not a Word document.');
        }
        if ($count > 2000) {
            return $this->fail('This document contains an unreasonable number of parts.');
        }

        return $this->ok('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    private function inspectTxt(string $path): array
    {
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        if (str_contains($sample, "\0")) {
            return $this->fail('This file contains binary data, not plain text.');
        }

        return $this->ok('txt', 'text/plain');
    }

    private function ok(string $ext, string $mime): array
    {
        return ['ok' => true, 'extension' => $ext, 'mime' => $mime, 'error' => null];
    }

    private function fail(string $error): array
    {
        return ['ok' => false, 'extension' => null, 'mime' => null, 'error' => $error];
    }
}
