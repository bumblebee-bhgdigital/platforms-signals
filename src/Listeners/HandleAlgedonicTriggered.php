<?php

namespace Platform\Signals\Listeners;

use Platform\Core\Events\AlgedonicTriggered;
use Platform\Organization\Services\PerspectiveService;
use Platform\Signals\Models\Signal;

/**
 * Algedonic-Kanal (Beer): der Core dispatcht AlgedonicTriggered, Signals erzeugt daraus ein Signal,
 * das alle Routing-Stufen überspringt und direkt auf S5 zielt (übernommen aus der Organisation).
 *
 *  - vsm_level = s5, source_type = human_algedonic, severity = critical
 *  - deadline_at = now() + signals.deadlines.algedonic (Default 1h)
 *  - current_owner = menschlicher S5-Owner der Perspektive, sonst der Carrier selbst
 *
 * Übergang: Solange die Organisation ihren eigenen Listener noch hat, erzeugt sie das Signal –
 * Signals springt erst ein, wenn der Listener dort zurückgebaut ist (kein doppeltes Signal).
 */
class HandleAlgedonicTriggered
{
    public function handle(AlgedonicTriggered $event): void
    {
        if (class_exists(\Platform\Organization\Listeners\HandleAlgedonicTriggered::class)) {
            return;
        }

        $isAnonymous = $event->userId === 0;

        $perspectiveEntityId = $event->perspectiveEntityId
            ?? (! $isAnonymous ? PerspectiveService::getActiveEntity($event->teamId, $event->userId)?->id : null)
            ?? PerspectiveService::getDefaultEntity($event->teamId)?->id;

        if (! $perspectiveEntityId) {
            return; // kein Carrier-Setup im Team
        }

        $entityId = $event->entityId ?: $perspectiveEntityId;
        $ownerId = PerspectiveService::resolveHumanOwner($perspectiveEntityId, 's5') ?? $perspectiveEntityId;
        $deadlineHours = (int) config('signals.deadlines.algedonic', 1);

        Signal::create([
            'team_id' => $event->teamId,
            'source' => 'human',
            'source_type' => Signal::SOURCE_TYPE_HUMAN_ALGEDONIC,
            'entity_id' => $entityId,
            'perspective_entity_id' => $perspectiveEntityId,
            'current_owner_entity_id' => $ownerId,
            'created_by_user_id' => $isAnonymous ? null : $event->userId,
            'vsm_level' => 's5',
            'status' => 'open',
            'severity' => 'critical',
            'message' => $event->message,
            'trigger_metrics' => array_filter([
                'algedonic' => true,
                'anonymous' => $isAnonymous,
                'triggered_by_user_id' => $isAnonymous ? null : $event->userId,
                'triggered_at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null),
            'deadline_at' => now()->copy()->addHours(max(1, $deadlineHours)),
        ]);
    }
}
