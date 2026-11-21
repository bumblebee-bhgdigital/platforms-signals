<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signals sammelt keine Daten mehr vor: Umweltquellen/-Snapshots und data_sources der KI-Templates entfallen.
 * Externe Daten gehören in ihre Module, der Agent liest sie selbst. Idempotent (auf office waren die Tabellen leer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('signal_environment_snapshots');
        Schema::dropIfExists('signal_environment_sources');
        if (Schema::hasColumn('signal_templates', 'data_sources')) {
            Schema::table('signal_templates', fn (Blueprint $t) => $t->dropColumn('data_sources'));
        }
    }

    public function down(): void
    {
        // bewusst ohne Rückweg
    }
};
