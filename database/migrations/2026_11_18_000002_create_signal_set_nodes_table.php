<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zuordnungs-Einstellungen je Signal-Set und Knoten.
     *
     * Die Verknüpfung selbst ist ein DimensionLink (Organization-Bridge); die Dimension Bridge kennt
     * aber keine Vererbungs-Option pro Link. Diese Tabelle hält sie modul-eigen: eine Zeile je
     * (Set, Knoten) mit inherit_down = "Set wirkt auch an allen Unterknoten".
     */
    public function up(): void
    {
        if (Schema::hasTable('signal_set_nodes')) {
            return;
        }

        Schema::create('signal_set_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_set_id')->constrained('signal_sets')->cascadeOnDelete();
            // Organisations-Entity (bewusst ohne FK: das Organization-Modul besitzt die Tabelle)
            $table->unsignedBigInteger('entity_id');
            $table->boolean('inherit_down')->default(false);
            $table->timestamps();

            $table->unique(['signal_set_id', 'entity_id']);
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_set_nodes');
    }
};
