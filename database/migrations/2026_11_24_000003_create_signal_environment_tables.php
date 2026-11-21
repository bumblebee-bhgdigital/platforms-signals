<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Umwelt (VSM S4, #1046): Quellen (RSS, KPI) und Snapshots je Abruf – übernommen aus
 * organization_environment_sources / _snapshots. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signal_environment_sources')) {
            Schema::create('signal_environment_sources', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name');
                $table->string('source_type', 30)->default('rss'); // rss | kpi
                $table->string('category', 50);
                $table->string('cluster', 50)->nullable()->index();
                $table->json('config');
                $table->unsignedInteger('pull_interval_hours')->default(24);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_pulled_at')->nullable();
                $table->unsignedBigInteger('legacy_organization_source_id')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['team_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('signal_environment_snapshots')) {
            Schema::create('signal_environment_snapshots', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('source_id')->constrained('signal_environment_sources')->cascadeOnDelete();
                $table->date('snapshot_date');
                $table->json('metrics');
                $table->text('summary');
                $table->json('raw_items')->nullable();
                $table->unsignedBigInteger('legacy_organization_snapshot_id')->nullable()->unique();
                $table->timestamp('created_at')->nullable();
                $table->index(['source_id', 'snapshot_date']);
                $table->index(['team_id', 'snapshot_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_environment_snapshots');
        Schema::dropIfExists('signal_environment_sources');
    }
};
