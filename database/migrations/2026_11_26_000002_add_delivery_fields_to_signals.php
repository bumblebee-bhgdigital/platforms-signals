<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zustellung und Eskalation (Z2–Z4): wer hat das Signal, warum, seit wann, wie oft kam es wieder.
 * - signal_set_template_id: die gewinnende Zuordnung (bestimmt Politik und Eskalation)
 * - recipient_*: Empfänger aus der Zuständigkeits-Abfrage der Organisation, mit lesbarer Begründung
 * - delivered_at / reacted_at: Reaktionsuhr (erste Reaktion: gesehen, gelöst, abgelehnt)
 * - escalation_level / escalation_reason, recurrence_count / reopened_at
 * Idempotent (hasColumn-Guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signals')) {
            return;
        }
        $columns = [
            'signal_set_template_id' => fn (Blueprint $t) => $t->unsignedBigInteger('signal_set_template_id')->nullable()->index(),
            'recipient_node_entity_id' => fn (Blueprint $t) => $t->unsignedBigInteger('recipient_node_entity_id')->nullable(),
            'recipient_entity_ids' => fn (Blueprint $t) => $t->json('recipient_entity_ids')->nullable(),
            'recipient_user_ids' => fn (Blueprint $t) => $t->json('recipient_user_ids')->nullable(),
            'recipient_reason' => fn (Blueprint $t) => $t->text('recipient_reason')->nullable(),
            'delivered_at' => fn (Blueprint $t) => $t->timestamp('delivered_at')->nullable(),
            'reacted_at' => fn (Blueprint $t) => $t->timestamp('reacted_at')->nullable(),
            'escalation_level' => fn (Blueprint $t) => $t->unsignedSmallInteger('escalation_level')->default(0),
            'escalation_reason' => fn (Blueprint $t) => $t->string('escalation_reason', 500)->nullable(),
            'recurrence_count' => fn (Blueprint $t) => $t->unsignedInteger('recurrence_count')->default(1),
            'reopened_at' => fn (Blueprint $t) => $t->timestamp('reopened_at')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('signals', $name)) {
                Schema::table('signals', $add);
            }
        }
    }

    public function down(): void
    {
        foreach (['signal_set_template_id', 'recipient_node_entity_id', 'recipient_entity_ids', 'recipient_user_ids', 'recipient_reason', 'delivered_at', 'reacted_at', 'escalation_level', 'escalation_reason', 'recurrence_count', 'reopened_at'] as $name) {
            if (Schema::hasColumn('signals', $name)) {
                Schema::table('signals', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};
