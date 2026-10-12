<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('roles')->nullable();                  // target roles
            $table->json('skills')->nullable();                 // list<string>, owner-reviewed
            $table->decimal('years_experience', 4, 1)->nullable();
            $table->json('experience_levels')->nullable();      // junior|mid|senior
            $table->json('remote_preferences')->nullable();     // location, countries, timezones
            $table->json('salary_preferences')->nullable();     // min, currency, period
            $table->json('employment_types')->nullable();       // full_time, contract, freelance ...
            $table->json('structured')->nullable();             // editable profile document
            $table->json('pending_extraction')->nullable();     // parsed from a CV, awaiting owner review
            $table->unsignedInteger('profile_version')->default(0);
            $table->timestamp('reviewed_at')->nullable();       // owner confirmed the parsed data
            $table->boolean('ai_consent')->nullable();          // null = not asked yet
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('privacy_consent_version', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('data');
            $table->string('reason', 120)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['candidate_profile_id', 'version']);
        });

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk_path');                        // relative to the private "resumes" disk
            $table->string('original_filename');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->longText('parsed_text')->nullable();
            $table->string('parse_status', 20)->default('pending'); // parsed|empty|failed
            $table->string('parse_error')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
            $table->unique(['user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumes');
        Schema::dropIfExists('candidate_profile_versions');
        Schema::dropIfExists('candidate_profiles');
    }
};
