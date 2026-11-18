<?php

namespace Platform\Signals\Livewire\Concerns;

/**
 * Team-Scope der UI = Familie (Root-Team + alle Kind-Teams) — wie die Planner-Sidebar.
 * Der Org-Baum ist am Root-Team verankert; ein User in einem Kind-Team muss die im Baum
 * hängenden Sets der Familie sehen. Sichtbarkeit pro Set regelt weiterhin SignalSet::visibleTo().
 */
trait ResolvesTeamFamily
{
    /** @return int[] */
    protected function relevantTeamIds($user): array
    {
        $current = $user?->currentTeam;
        if (! $current) {
            return [];
        }

        $root = $current->getRootTeam();
        $ids = [$root->id];
        $walker = function ($team) use (&$walker, &$ids) {
            foreach ($team->childTeams()->get() as $child) {
                $ids[] = $child->id;
                $walker($child);
            }
        };
        $walker($root);

        return array_values(array_unique($ids));
    }
}
