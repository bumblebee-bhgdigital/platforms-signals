<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Umzug aus der Organisation idempotent machen: alte Kommentar- und Maßnahmen-IDs merken. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signal_comments') && ! Schema::hasColumn('signal_comments', 'legacy_organization_comment_id')) {
            Schema::table('signal_comments', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_organization_comment_id')->nullable()->unique();
            });
        }
        if (Schema::hasTable('signal_actions') && ! Schema::hasColumn('signal_actions', 'legacy_organization_action_id')) {
            Schema::table('signal_actions', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_organization_action_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (['signal_comments' => 'legacy_organization_comment_id', 'signal_actions' => 'legacy_organization_action_id'] as $t => $c) {
            if (Schema::hasColumn($t, $c)) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn($c));
            }
        }
    }
};
