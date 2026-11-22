<?php

namespace Platform\Signals\Tools\Concerns;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;

/** Gemeinsame Helfer der signals.deliveries.*-Tools (Zustellung als Parlan-Vorgang, Z4). */
trait HandlesDeliveries
{
    /** Admin = Rolle owner/admin im Team des Kontexts (darf für einen anderen Agenten lesen und melden). */
    protected function isTeamAdmin(ToolContext $context): bool
    {
        if (! $context->user || ! $context->team) {
            return false;
        }

        return DB::table('team_user')->where('team_id', $context->team->id)->where('user_id', $context->user->id)
            ->whereIn('role', ['owner', 'admin'])->exists();
    }
}
