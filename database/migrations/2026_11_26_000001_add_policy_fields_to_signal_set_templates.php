<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zustellung und Eskalation (Z1/Z3): Zuordnung eines Templates in einem Set trägt ihre Regeln.
 * - is_binding: verbindlich (unten anpassbar, nicht abwählbar)
 * - opts_out: Abwahl in einem Set weiter unten („gilt bei uns nicht“)
 * - Politik (null = Standard aus config signals.delivery): Reaktionszeit (Arbeitstage), wiederkehrend N× in X Tagen,
 *   dauerhaft offen (Tage), Eskalationsziel none|line|committee, Zustellung overview|task, Empfänger-Fähigkeit.
 * Idempotent (hasColumn-Guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signal_set_templates')) {
            return;
        }
        $columns = [
            'is_binding' => fn (Blueprint $t) => $t->boolean('is_binding')->default(false),
            'opts_out' => fn (Blueprint $t) => $t->boolean('opts_out')->default(false),
            'reaction_days' => fn (Blueprint $t) => $t->unsignedSmallInteger('reaction_days')->nullable(),
            'recurrence_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('recurrence_count')->nullable(),
            'recurrence_window_days' => fn (Blueprint $t) => $t->unsignedSmallInteger('recurrence_window_days')->nullable(),
            'persistence_days' => fn (Blueprint $t) => $t->unsignedSmallInteger('persistence_days')->nullable(),
            'escalation_target' => fn (Blueprint $t) => $t->string('escalation_target', 20)->nullable(), // none | line | committee
            'delivery' => fn (Blueprint $t) => $t->string('delivery', 20)->nullable(), // overview | task
            'recipient_capability' => fn (Blueprint $t) => $t->string('recipient_capability', 50)->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('signal_set_templates', $name)) {
                Schema::table('signal_set_templates', $add);
            }
        }
    }

    public function down(): void
    {
        foreach (['is_binding', 'opts_out', 'reaction_days', 'recurrence_count', 'recurrence_window_days', 'persistence_days', 'escalation_target', 'delivery', 'recipient_capability'] as $name) {
            if (Schema::hasColumn('signal_set_templates', $name)) {
                Schema::table('signal_set_templates', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};
