<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gedächtnis der Wahrnehmung (übernommen aus organization_memory_entries): Rückmeldungen zu Signalen
 * (baseline/suppression), Beobachtungen der Agenten (entity_profile, relationship, prompt_experience …).
 * Fließt als Kontext in die Auswertung der KI-Templates. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signal_memory_entries')) {
            return;
        }
        Schema::create('signal_memory_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->unsignedBigInteger('signal_template_id')->nullable()->index();
            $table->unsignedBigInteger('signal_id')->nullable()->index();
            $table->string('memory_type', 40); // baseline | suppression | entity_profile | relationship | prompt_experience | source_relevance | …
            $table->text('content');
            $table->json('structured_data')->nullable();
            $table->float('confidence')->default(0.5);
            $table->string('source_type', 40)->nullable(); // signal_feedback | inference | manual | migration
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('reinforcement_count')->default(0);
            $table->unsignedBigInteger('legacy_organization_memory_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['team_id', 'memory_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_memory_entries');
    }
};
