<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // RESTRICT: removing a job/source must never silently erase application history.
            $table->foreignId('job_id')->constrained('job_postings')->restrictOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->unsignedInteger('profile_version')->nullable();
            $table->string('type', 16)->default('employment');   // employment|freelance
            $table->string('status', 28)->default('draft_ready');
            $table->longText('cover_letter')->nullable();
            $table->longText('tailored_resume')->nullable();     // text of the tailored CV sections
            $table->json('screening_answers')->nullable();       // [{question, answer, needs_confirmation, evidence}]
            $table->json('artifacts')->nullable();               // generation metadata, evidence flags
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approved_url')->nullable();
            $table->json('approved_hashes')->nullable();         // sha256 of the approved documents
            $table->string('submission_method', 16)->nullable(); // manual|api
            $table->timestamp('submitted_at')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->string('next_task')->nullable();
            $table->timestamp('next_task_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'job_id']);
            $table->index('status');
        });

        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->string('from_status', 28)->nullable();
            $table->string('to_status', 28)->nullable();
            $table->string('actor', 24)->default('system');     // owner|system|gmail
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->index(['application_id', 'occurred_at']);
        });

        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('provider_user_id')->nullable();
            $table->string('email')->nullable();
            $table->text('access_token')->nullable();            // encrypted cast
            $table->text('refresh_token')->nullable();           // encrypted cast
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status', 16)->default('connected');  // connected|error|revoked
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('thread_id')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->text('body')->nullable();                    // only when the owner enabled body storage
            $table->timestamp('received_at')->nullable();
            $table->string('classification', 24)->default('uncertain');
            $table->decimal('confidence', 4, 2)->default(0);
            $table->json('extracted')->nullable();               // times, meeting url, deadline, action
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->json('candidate_application_ids')->nullable();
            $table->string('review_status', 16)->default('needs_review'); // auto|needs_review|confirmed|dismissed
            $table->string('dedupe_key', 64);
            $table->string('web_link', 500)->nullable();
            $table->boolean('ai_classified')->default(false);
            $table->timestamps();

            $table->unique('dedupe_key');
            $table->unique(['connected_account_id', 'provider_message_id'], 'inbound_provider_unique');
            $table->index(['review_status', 'received_at']);
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained('inbound_messages')->nullOnDelete();
            $table->string('kind', 16)->default('interview');    // interview|assessment|follow_up
            $table->string('title');
            $table->timestamp('starts_at')->nullable();          // UTC
            $table->timestamp('ends_at')->nullable();            // UTC
            $table->timestamp('deadline_at')->nullable();        // UTC (assessment due date)
            $table->string('source_timezone', 64)->default('UTC');
            $table->string('meeting_url', 500)->nullable();
            $table->string('state', 12)->default('tentative');   // tentative|confirmed|cancelled|completed
            $table->timestamp('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['state', 'starts_at']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 12)->default('mail');
            $table->string('type', 48);
            $table->string('event_key', 190)->unique();           // dedupe: one row per event
            $table->string('recipient')->nullable();
            $table->json('payload')->nullable();                 // safe, minimal
            $table->string('status', 12)->default('pending');    // pending|sent|failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->default('gemini');
            $table->string('model', 64);
            $table->string('task', 40);
            $table->string('prompt_version', 16)->nullable();
            $table->date('called_on');                           // UTC date, used by the local daily guard
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('status', 16);                        // ok|error|rate_limited|invalid|blocked
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 48)->nullable();
            $table->string('error_message')->nullable();         // sanitised, never the prompt
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['called_on', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('inbound_messages');
        Schema::dropIfExists('connected_accounts');
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('applications');
    }
};
