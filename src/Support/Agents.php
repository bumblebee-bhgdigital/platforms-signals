<?php

namespace Platform\Signals\Support;

use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;

/**
 * Agent erkennen: entweder ein Plattform-Agent (type ai_user) oder ein Konto, das in der Organisation
 * an einem Agent-Knoten hängt (Entity-Typ „agent“ mit linked_user_id) – so laufen z. B. Perceptor und Ratchet.
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
            $id = OrganizationEntity::query()->where('linked_user_id', $user->id)
                ->whereHas('type', fn ($q) => $q->where('code', 'agent'))->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
