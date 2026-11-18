<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signal_sets')) {
            return;
        }

        Schema::create('signal_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // draft | active | archived (Entwurf / aktiv / archiviert)
            $table->string('status', 20)->default('draft');

            // Verantwortung: Owner (User) + verantwortlicher Agent (User vom Typ ai_user, vorbereitet)
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Ersteller-Spalte, die der Core-Authz-Resolver erkennt (AuthzResolver::ownerColumn).
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_sets');
    }
};
