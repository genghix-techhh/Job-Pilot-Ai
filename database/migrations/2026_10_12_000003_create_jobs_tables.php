<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_sources', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);
            $table->string('name');
            $table->text('config')->nullable();                 // encrypted:array cast (may hold secrets)
            $table->boolean('enabled')->default(true);
            $table->string('health_status', 24)->default('never'); // never|ok|error|unconfigured|disabled
            $table->text('last_error')->nullable();             // sanitised
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->json('last_stats')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'next_sync_at']);
        });

        Schema::create('source_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_source_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('running');   // running|ok|error
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->string('trigger', 16)->default('schedule');  // schedule|manual
            $table->index(['job_source_id', 'started_at']);
        });

        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('job_sources')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('kind', 16)->default('employment');   // employment|freelance
            $table->text('url');
            $table->text('canonical_url')->nullable();
            $table->string('company')->nullable();
            $table->string('title');
            $table->longText('description')->nullable();         // plain text only (HTML stripped on ingest)
            $table->char('description_checksum', 64)->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_remote')->default(false);        // posting says remote / work-from-home
            $table->string('remote_scope', 16)->default('unclear'); // worldwide|region|country|hybrid|onsite|unclear
            $table->json('eligible_countries')->nullable();
            $table->string('employment_type', 16)->default('unknown');
            $table->unsignedBigInteger('salary_min')->nullable();
            $table->unsignedBigInteger('salary_max')->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->string('salary_period', 12)->nullable();     // year|month|week|day|hour|project
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->char('fingerprint', 64);
            $table->string('state', 12)->default('active');      // active|expired
            $table->string('user_status', 12)->default('new');   // new|saved|hidden
            $table->json('skills')->nullable();                  // extracted skill names
            $table->json('meta')->nullable();                    // small allowed extras (tags, department ...)
            $table->json('ai_requirements')->nullable();         // validated Gemini requirement extraction (cached per job version)
            $table->char('ai_requirements_checksum', 64)->nullable();
            $table->boolean('needs_details')->default(false);    // URL-only manual bookmarks
            $table->boolean('is_manual')->default(false);
            $table->foreignId('duplicate_of')->nullable()->constrained('job_postings')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index('fingerprint');
            $table->index(['state', 'user_status']);
            $table->index('posted_at');
            $table->index('last_seen_at');
            $table->index('company');
        });

        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('job_postings')->cascadeOnDelete();
            $table->unsignedInteger('profile_version');
            $table->char('job_checksum', 64);
            $table->string('model_id', 64)->nullable();
            $table->decimal('score', 5, 1)->default(0);
            $table->string('tier', 12)->default('low');
            $table->char('scoring_hash', 40)->nullable();        // weights/thresholds the score was built with
            $table->json('factors')->nullable();
            $table->json('matched_skills')->nullable();
            $table->json('missing_skills')->nullable();
            $table->string('eligibility_status', 12)->default('unclear');
            $table->string('eligibility_note')->nullable();
            $table->text('rationale')->nullable();
            $table->json('ai_analysis')->nullable();             // cached validated Gemini outputs
            $table->string('ai_status', 12)->default('none');    // none|pending|done|skipped|failed
            $table->string('ai_note')->nullable();
            $table->json('freelance_fit')->nullable();
            $table->string('review_status', 12)->default('unreviewed');
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'job_id', 'profile_version', 'job_checksum'], 'job_matches_cache_unique');
            $table->index(['user_id', 'score']);
            $table->index(['ai_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_matches');
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('source_sync_runs');
        Schema::dropIfExists('job_sources');
    }
};
