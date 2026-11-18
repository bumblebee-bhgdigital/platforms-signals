<?php

namespace Platform\Signals\Policies;

use Platform\Core\Authz\AuthzResolver;
use Platform\Core\Models\User;
use Platform\Core\Policies\RolePolicy;
use Platform\Signals\Models\SignalSet;

/**
 * Kein eigenes Rollensystem — gleiche Graph-Autorisierung wie PlannerProjectPolicy:
 * Ersteller (owns) ODER strukturell im Org-Graphen erreichbar (may). read < write < manage.
 * Zusätzlich darf der im Set eingetragene Owner (owner_user_id) lesen/ändern.
 * Teilen = das Set im Graphen (an Knoten) aufhängen.
 */
class SignalSetPolicy extends RolePolicy
{
    public function view(User $user, $set): bool
    {
        return $this->graphAllows($user, $set, 'read');
    }

    public function update(User $user, $set): bool
    {
        return $this->graphAllows($user, $set, 'write');
    }

    public function delete(User $user, $set): bool
    {
        return $this->graphAllows($user, $set, 'manage');
    }

    public function forceDelete(User $user, $set): bool
    {
        return $this->graphAllows($user, $set, 'manage');
    }

    public function create(User $user): bool
    {
        // Jedes Team-Mitglied kann Sets anlegen (wird sein Ersteller).
        return $user->currentTeam !== null;
    }

    protected function graphAllows(User $user, $set, string $cap): bool
    {
        if (! $set || ! $set->id) {
            return false;
        }

        if ($set->owner_user_id && (int) $set->owner_user_id === (int) $user->id) {
            return true;
        }

        $resolver = app(AuthzResolver::class);
        $type = SignalSet::class;

        return $resolver->may($user, $cap, $type, (int) $set->id)
            || $resolver->owns($user, $type, (int) $set->id);
    }

    protected function getUserRole(User $user, $model): ?string
    {
        return null;
    }
}
