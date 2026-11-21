<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Eskalation eine Ebene höher: aus welchem Gremium kam das Signal? Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('signals', 'origin_committee_entity_id')) {
            Schema::table('signals', fn (Blueprint $t) => $t->unsignedBigInteger('origin_committee_entity_id')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('signals', 'origin_committee_entity_id')) {
            Schema::table('signals', fn (Blueprint $t) => $t->dropColumn('origin_committee_entity_id'));
        }
    }
};
