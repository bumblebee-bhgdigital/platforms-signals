<?php

namespace Platform\Signals\Support;

use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;

/**
 * Agent erkennen: Plattform-Agent (type ai_user) oder ein Konto an einem Agent-Knoten der Organisation
 * (was ein Agent-Knoten ist, entscheidet die Organisation: Scope agents()).
 */
class Agents
{
    public static function isAgent(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->type === 'ai_user' || self::entityId($user) !== null;
    }

    /** Agent-Knoten in der Organisation zu einem Konto (oder null). */
    public static function entityId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }
        try {
            $id = OrganizationEntity::query()->agents()->where('linked_user_id', $user->id)->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
