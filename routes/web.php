<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\SavedSearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JobPilot AI routes
|--------------------------------------------------------------------------
| There is deliberately NO /register route: the single owner is created with
| `php artisan app:create-owner` (or the token-protected /setup page).
*/

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/setup', [SetupController::class, 'create'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');
});

Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy.show');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // First-use consent screen (reachable before the gate below).
    Route::get('/privacy/consent', [ConsentController::class, 'show'])->name('privacy.consent');
    Route::post('/privacy/consent', [ConsentController::class, 'store'])->name('privacy.consent.store');

    Route::middleware('privacy')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Job feed & opportunities
        Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
        Route::get('/jobs/import', [JobController::class, 'create'])->name('jobs.create');
        Route::post('/jobs/import', [JobController::class, 'store'])->name('jobs.store');
        Route::post('/jobs/bulk', [JobController::class, 'bulk'])->name('jobs.bulk');
        Route::get('/jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
        Route::patch('/jobs/{job}/status', [JobController::class, 'status'])->name('jobs.status');
        Route::put('/jobs/{job}/details', [JobController::class, 'details'])->name('jobs.details');
        Route::post('/jobs/{job}/rescore', [JobController::class, 'rescore'])->middleware('throttle:heavy')->name('jobs.rescore');
        Route::post('/jobs/{job}/draft', [ApplicationController::class, 'store'])->name('jobs.draft');

        // Saved searches (feed filters + e-mail digest)
        Route::post('/searches', [SavedSearchController::class, 'store'])->name('searches.store');
        Route::patch('/searches/{search}', [SavedSearchController::class, 'update'])->name('searches.update');
        Route::delete('/searches/{search}', [SavedSearchController::class, 'destroy'])->name('searches.destroy');

        // CV & profile
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/discard-pending', [ProfileController::class, 'discardPending'])->name('profile.discard');
        Route::post('/profile/resume', [ResumeController::class, 'store'])->middleware('throttle:10,1')->name('resume.store');
        Route::get('/profile/resume/{resume}', [ResumeController::class, 'download'])->name('resume.download');
        Route::post('/profile/resume/{resume}/activate', [ResumeController::class, 'activate'])->name('resume.activate');
        Route::post('/profile/resume/{resume}/reparse', [ResumeController::class, 'reparse'])->middleware('throttle:heavy')->name('resume.reparse');
        Route::delete('/profile/resume/{resume}', [ResumeController::class, 'destroy'])->name('resume.destroy');

        // Applications: tracker + studio
        Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
        Route::put('/applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
        Route::post('/applications/{application}/question', [ApplicationController::class, 'addQuestion'])->name('applications.question');
        Route::post('/applications/{application}/regenerate', [ApplicationController::class, 'regenerate'])->middleware('throttle:heavy')->name('applications.regenerate');
        Route::post('/applications/{application}/adopt-ai', [ApplicationController::class, 'adoptAiSuggestion'])->name('applications.adopt');
        Route::post('/applications/{application}/request-approval', [ApplicationController::class, 'requestApproval'])->name('applications.request');
        Route::post('/applications/{application}/approve', [ApplicationController::class, 'approve'])->name('applications.approve');
        Route::post('/applications/{application}/revoke', [ApplicationController::class, 'revoke'])->name('applications.revoke');
        Route::post('/applications/{application}/reject', [ApplicationController::class, 'reject'])->name('applications.reject');
        Route::post('/applications/{application}/open', [ApplicationController::class, 'open'])->name('applications.open');
        Route::post('/applications/{application}/submit', [ApplicationController::class, 'submit'])->middleware('throttle:heavy')->name('applications.submit');
        Route::post('/applications/{application}/submitted', [ApplicationController::class, 'markSubmitted'])->name('applications.submitted');
        Route::post('/applications/{application}/status', [ApplicationController::class, 'status'])->name('applications.status');
        Route::patch('/applications/{application}/tracking', [ApplicationController::class, 'tracking'])->name('applications.tracking');
        Route::get('/applications/{application}/prep', [ApplicationController::class, 'prep'])->name('applications.prep');
        Route::post('/applications/{application}/prep', [ApplicationController::class, 'prepGenerate'])->middleware('throttle:heavy')->name('applications.prep.generate');
        Route::delete('/applications/{application}/prep', [ApplicationController::class, 'prepReset'])->name('applications.prep.reset');
        Route::post('/applications/{application}/followup/plan', [ApplicationController::class, 'followUpPlan'])->name('applications.followup.plan');
        Route::post('/applications/{application}/followup/dismiss', [ApplicationController::class, 'followUpDismiss'])->name('applications.followup.dismiss');
        Route::get('/applications/{application}/export/{kind}', [ApplicationController::class, 'export'])->whereIn('kind', ['cover-letter', 'resume'])->name('applications.export');

        // Interviews & follow-ups
        Route::get('/interviews', [InterviewController::class, 'index'])->name('interviews.index');
        Route::post('/interviews', [InterviewController::class, 'store'])->name('interviews.store');
        Route::put('/interviews/{interview}', [InterviewController::class, 'update'])->name('interviews.update');
        Route::post('/interviews/{interview}/confirm', [InterviewController::class, 'confirm'])->name('interviews.confirm');
        Route::post('/interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->name('interviews.cancel');
        Route::post('/interviews/{interview}/complete', [InterviewController::class, 'complete'])->name('interviews.complete');
        Route::get('/interviews/{interview}/ics', [InterviewController::class, 'ics'])->name('interviews.ics');

        // Inbox monitoring (optional Gmail)
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('/inbox/connect', [InboxController::class, 'connect'])->name('inbox.connect');
        Route::get('/inbox/callback', [InboxController::class, 'callback'])->name('inbox.callback');
        Route::post('/inbox/sync', [InboxController::class, 'sync'])->middleware('throttle:heavy')->name('inbox.sync');
        Route::post('/inbox/disconnect', [InboxController::class, 'disconnect'])->name('inbox.disconnect');
        Route::post('/inbox/messages/{message}/resolve', [InboxController::class, 'resolve'])->name('inbox.resolve');
        Route::post('/inbox/messages/{message}/dismiss', [InboxController::class, 'dismiss'])->name('inbox.dismiss');
        Route::post('/inbox/purge-bodies', [InboxController::class, 'purgeBodies'])->name('inbox.purge');

        // Sources
        Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
        Route::post('/sources', [SourceController::class, 'store'])->name('sources.store');
        Route::post('/sources/sync-all', [SourceController::class, 'syncAll'])->middleware('throttle:heavy')->name('sources.sync-all');
        Route::patch('/sources/{source}', [SourceController::class, 'update'])->name('sources.update');
        Route::post('/sources/{source}/test', [SourceController::class, 'test'])->middleware('throttle:heavy')->name('sources.test');
        Route::post('/sources/{source}/sync', [SourceController::class, 'sync'])->middleware('throttle:heavy')->name('sources.sync');
        Route::delete('/sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');

        // Settings
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/settings/account', [SettingsController::class, 'account'])->name('settings.account');
        Route::post('/settings/test-email', [SettingsController::class, 'testEmail'])->middleware('throttle:heavy')->name('settings.test-email');
        Route::get('/settings/export', [SettingsController::class, 'export'])->middleware('throttle:heavy')->name('settings.export');
        Route::post('/settings/send-digest', [SettingsController::class, 'sendDigest'])->middleware('throttle:heavy')->name('settings.send-digest');
        Route::post('/settings/test-ai', [SettingsController::class, 'testAi'])->middleware('throttle:heavy')->name('settings.test-ai');
        Route::post('/settings/consent', [SettingsController::class, 'consent'])->name('settings.consent');
        Route::delete('/settings/data', [SettingsController::class, 'deleteData'])->name('settings.delete-data');

        Route::get('/insights', [InsightsController::class, 'index'])->name('insights.index');
        Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
    });
});
