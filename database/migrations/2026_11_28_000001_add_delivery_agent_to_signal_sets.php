<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Z4: „Zustellung durch“ – der Agent, der die Parlan-Vorgänge eines Sets führt (z. B. Ironhide), getrennt vom
 * verantwortlichen Agenten (Sensor, wertet die KI-Templates aus, z. B. Perceptor). Leer = der verantwortliche Agent.
 * Idempotent (hasColumn-Guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signal_sets') && ! Schema::hasColumn('signal_sets', 'delivery_agent_user_id')) {
            Schema::table('signal_sets', fn (Blueprint $t) => $t->unsignedBigInteger('delivery_agent_user_id')->nullable()->after('responsible_agent_user_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('signal_sets') && Schema::hasColumn('signal_sets', 'delivery_agent_user_id')) {
            Schema::table('signal_sets', fn (Blueprint $t) => $t->dropColumn('delivery_agent_user_id'));
        }
    }
};
