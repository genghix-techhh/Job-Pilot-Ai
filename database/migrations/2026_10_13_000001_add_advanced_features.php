<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_matches', function (Blueprint $table) {
            // Why the owner's own rules (blocked company, keyword, salary floor) hide this job; null = allowed.
            $table->string('exclusion', 200)->nullable()->after('eligibility_note');
            $table->index('exclusion');
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');                       // the feed's query-string filters
            $table->boolean('digest')->default(true);      // include in the e-mail digest
            $table->timestamp('last_digest_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::table('job_matches', function (Blueprint $table) {
            $table->dropIndex(['exclusion']);
            $table->dropColumn('exclusion');
        });
    }
};
