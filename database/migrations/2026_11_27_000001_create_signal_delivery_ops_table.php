<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Z4 „ein Signal, ein Vorgang“ (Schritt 3): Zustellung als Parlan-Vorgang über den Agenten des Sets.
 * - signals.delivery_thread: Parlan-Vorgang (Thread-ID), über den die Rückmeldungen zugeordnet werden
 * - signals.delivery_room_entity_id: Knoten, in dessen Kontext-Raum der Vorgang liegt (= entity_id des Signals)
 * - signals.delivery_state: null (kein Vorgang) | pending (Auftrag liegt beim Agenten) | open | closed
 * - signal_delivery_ops: Aufträge an den Agenten (create, handover, comment, reopen, close), der Reihe nach
 * - signal_delivery_events: bereits verarbeitete Rückmeldungen (event_id eindeutig – Doppelte werden ignoriert)
 * Idempotent (hasTable/hasColumn-Guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signals')) {
            $columns = [
                'delivery_thread' => fn (Blueprint $t) => $t->string('delivery_thread', 191)->nullable()->index(),
                'delivery_room_entity_id' => fn (Blueprint $t) => $t->unsignedBigInteger('delivery_room_entity_id')->nullable(),
                'delivery_state' => fn (Blueprint $t) => $t->string('delivery_state', 16)->nullable(),
            ];
            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('signals', $name)) {
                    Schema::table('signals', $add);
                }
            }
        }

        if (! Schema::hasTable('signal_delivery_ops')) {
            Schema::create('signal_delivery_ops', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('signal_id')->index();
                $t->string('op', 16); // create | handover | comment | reopen | close
                $t->json('payload')->nullable();
                $t->string('status', 16)->default('pending')->index(); // pending | done | failed | cancelled
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->text('last_error')->nullable();
                $t->timestamp('done_at')->nullable();
                $t->timestamps();
                $t->index(['signal_id', 'status']);
            });
        }

        if (! Schema::hasTable('signal_delivery_events')) {
            Schema::create('signal_delivery_events', function (Blueprint $t) {
                $t->id();
                $t->string('event_id', 191)->unique();
                $t->unsignedBigInteger('signal_id')->nullable()->index();
                $t->string('kind', 16);
                $t->string('thread', 191)->nullable();
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('result', 32)->nullable(); // applied | ignored_agent | ignored_closed | …
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_delivery_events');
        Schema::dropIfExists('signal_delivery_ops');
        foreach (['delivery_thread', 'delivery_room_entity_id', 'delivery_state'] as $name) {
            if (Schema::hasTable('signals') && Schema::hasColumn('signals', $name)) {
                Schema::table('signals', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};
