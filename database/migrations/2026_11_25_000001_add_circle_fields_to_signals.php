<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regelkreis Einheit → Lenkungsausschuss (Architektur v2): verantwortliche Einheit, Stufe, zuständiges Gremium,
 * Frist = nächste Sitzung, Verweis auf den LA-Punkt, Entscheidungsrahmen und Ausgang. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'unit_entity_id' => fn (Blueprint $t) => $t->unsignedBigInteger('unit_entity_id')->nullable()->index(),
            'stage' => fn (Blueprint $t) => $t->string('stage', 20)->default('unit')->index(), // unit | committee | escalated | strategy
            'committee_entity_id' => fn (Blueprint $t) => $t->unsignedBigInteger('committee_entity_id')->nullable()->index(),
            'meeting_ref_id' => fn (Blueprint $t) => $t->unsignedBigInteger('meeting_ref_id')->nullable(),
            'due_at' => fn (Blueprint $t) => $t->timestamp('due_at')->nullable(),
            'agenda_ref_type' => fn (Blueprint $t) => $t->string('agenda_ref_type', 100)->nullable(),
            'agenda_ref_id' => fn (Blueprint $t) => $t->unsignedBigInteger('agenda_ref_id')->nullable(),
            'frame_ref_id' => fn (Blueprint $t) => $t->unsignedBigInteger('frame_ref_id')->nullable(),
            'outcome' => fn (Blueprint $t) => $t->string('outcome', 30)->nullable(), // solved_locally | measure | returned_to_unit | escalated | strategy_topic | dismissed
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('signals', $name)) {
                Schema::table('signals', fn (Blueprint $t) => $add($t));
            }
        }
    }

    public function down(): void
    {
        foreach (['unit_entity_id', 'stage', 'committee_entity_id', 'meeting_ref_id', 'due_at', 'agenda_ref_type', 'agenda_ref_id', 'frame_ref_id', 'outcome'] as $c) {
            if (Schema::hasColumn('signals', $c)) {
                Schema::table('signals', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
