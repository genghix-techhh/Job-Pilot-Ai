<?php

namespace App\Http\Controllers;

use App\Models\Resume;
use App\Services\Profile\ResumeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResumeController extends Controller
{
    public function store(Request $request, ResumeService $resumes): RedirectResponse
    {
        $request->validate([
            'cv' => ['required', 'file', 'max:'.config('jobpilot.uploads.max_kb'), 'extensions:pdf,docx,txt'],
        ], [
            'cv.max' => 'The CV must be smaller than '.round(config('jobpilot.uploads.max_kb') / 1024, 1).' MB.',
            'cv.extensions' => 'Only PDF, DOCX and TXT files are accepted.',
        ]);

        $result = $resumes->handleUpload($request->user(), $request->file('cv'));

        if ($result['error']) {
            return back()->withErrors(['cv' => $result['error']]);
        }
        $resume = $result['resume'];
        if ($resume->parse_status !== 'parsed') {
            return back()->with('error', 'Uploaded, but no text could be extracted: '.$resume->parse_error);
        }

        return redirect()->route('profile.edit')->with('status', 'CV uploaded and parsed on this server. Review every field below, fix anything wrong, then save — nothing is used for matching until you do.');
    }

    public function download(Resume $resume): StreamedResponse
    {
        $this->authorize('view', $resume);
        abort_unless(Storage::disk('resumes')->exists($resume->disk_path), 404);

        return Storage::disk('resumes')->download($resume->disk_path, $resume->original_filename, [
            'Content-Type' => $resume->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function activate(Resume $resume, ResumeService $resumes): RedirectResponse
    {
        $this->authorize('update', $resume);
        $resumes->activate($resume);

        return back()->with('status', "CV v{$resume->version} is now your master CV.");
    }

    public function reparse(Request $request, Resume $resume, ResumeService $resumes): RedirectResponse
    {
        $this->authorize('update', $resume);
        abort_unless($resume->parse_status === 'parsed', 422);
        $resumes->stageExtraction($request->user(), $resume);

        return redirect()->route('profile.edit')->with('status', 'CV parsed again. Review the fields below and save.');
    }

    public function destroy(Resume $resume, ResumeService $resumes): RedirectResponse
    {
        $this->authorize('delete', $resume);

        return $resumes->delete($resume)
            ? back()->with('status', 'CV file deleted.')
            : back()->with('error', 'The active CV, or one used by an application, cannot be deleted. Make another CV active first.');
    }
}
