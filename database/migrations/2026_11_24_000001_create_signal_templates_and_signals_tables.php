<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messlogik des Signals-Moduls (#1047, #1059, #1061):
 * - signal_templates: wiederverwendbare Signaldefinition, kind = rule (Muster auf Kennzahlen) | inference (Prompt für den Agenten)
 * - signal_set_templates: welches Template in welchem Set, mit überschreibbaren Bedingungen/Schwere/Takt
 * - signals: ausgelöste Signale inkl. Lebenszyklus (Eskalation, Aggregation, Snooze) und Auflösung (Notiz + Verweis)
 * - signal_comments / signal_actions / signal_focuses: wie bisher in der Organisation
 *
 * Knoten (entity_id …) hängen bewusst ohne Fremdschlüssel an (Modulgrenze zur Organisation, wie signal_set_nodes).
 * Idempotent (hasTable-Guards), damit abgebrochene Deploys die Kette nicht blockieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signal_templates')) {
            Schema::create('signal_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->string('kind', 20)->default('rule'); // rule | inference
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('severity', 20)->default('warning'); // info | warning | critical | algedonic
                $table->boolean('is_active')->default(true);
                // kind = rule
                $table->string('pattern_type', 30)->nullable(); // threshold | trend | ratio | cross_dimension
                $table->json('conditions')->nullable();
                $table->string('frequency', 30)->default('every_snapshot'); // every_snapshot | daily | weekly
                // kind = inference
                $table->longText('prompt')->nullable();
                $table->string('vsm_system', 10)->nullable(); // s1 … s5, s3_star
                $table->json('data_sources')->nullable();
                $table->string('dimension', 50)->nullable();
                $table->unsignedInteger('schedule_interval_hours')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['team_id', 'kind', 'is_active']);
            });
        }

        if (! Schema::hasTable('signal_set_templates')) {
            Schema::create('signal_set_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('signal_set_id')->constrained('signal_sets')->cascadeOnDelete();
                $table->foreignId('signal_template_id')->constrained('signal_templates')->cascadeOnDelete();
                $table->json('conditions_override')->nullable(); // wird über die conditions des Templates gelegt
                $table->string('severity_override', 20)->nullable();
                $table->string('frequency_override', 30)->nullable();
                $table->unsignedInteger('schedule_interval_hours_override')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->timestamp('last_evaluated_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['signal_set_id', 'signal_template_id']);
            });
        }

        if (! Schema::hasTable('signals')) {
            Schema::create('signals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                // Herkunft
                $table->unsignedBigInteger('signal_set_id')->nullable()->index();
                $table->unsignedBigInteger('signal_template_id')->nullable()->index();
                $table->string('source', 20)->default('rule'); // rule | inference | human | migration
                $table->string('source_type', 40)->nullable(); // rule_cron | inference | human_algedonic | aggregation | …
                $table->unsignedBigInteger('entity_id')->index();
                $table->json('affected_entity_ids')->nullable();
                // Inhalt
                $table->string('status', 20)->default('open'); // open | acknowledged | resolved | dismissed
                $table->string('severity', 20)->default('warning');
                $table->text('message');
                $table->json('trigger_metrics')->nullable();
                $table->json('evidence')->nullable();
                // Zuständigkeit / Routing (VSM)
                $table->unsignedBigInteger('responsible_user_id')->nullable()->index(); // verantwortlicher Agent des Sets
                $table->unsignedBigInteger('assignee_entity_id')->nullable()->index();
                $table->unsignedBigInteger('perspective_entity_id')->nullable()->index();
                $table->unsignedBigInteger('current_owner_entity_id')->nullable();
                $table->unsignedBigInteger('created_by_agent_entity_id')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->string('vsm_level', 10)->nullable();
                $table->timestamp('deadline_at')->nullable();
                $table->timestamp('escalated_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('snooze_until')->nullable();
                $table->timestamp('aggregated_at')->nullable();
                $table->unsignedBigInteger('aggregated_to_signal_id')->nullable();
                // Auflösung (#1061)
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->text('dismissed_reason')->nullable();
                $table->text('resolution_note')->nullable();
                $table->string('resolution_link_type', 100)->nullable();
                $table->unsignedBigInteger('resolution_link_id')->nullable();
                // Herkunft beim Umzug aus der Organisation
                $table->unsignedBigInteger('legacy_organization_signal_id')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['team_id', 'status', 'severity']);
                $table->index(['signal_set_id', 'signal_template_id', 'entity_id', 'status'], 'signals_dedupe_idx');
            });
        }

        if (! Schema::hasTable('signal_comments')) {
            Schema::create('signal_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('author_context', 30)->default('user'); // user | agent | system
                $table->text('content');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('signal_actions')) {
            Schema::create('signal_actions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status', 20)->default('pending'); // pending | applied | dismissed
                $table->text('decision_reason')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('signal_focuses')) {
            Schema::create('signal_focuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->timestamp('focused_at')->nullable();
                $table->timestamps();
                $table->unique(['signal_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_focuses');
        Schema::dropIfExists('signal_actions');
        Schema::dropIfExists('signal_comments');
        Schema::dropIfExists('signals');
        Schema::dropIfExists('signal_set_templates');
        Schema::dropIfExists('signal_templates');
    }
};
