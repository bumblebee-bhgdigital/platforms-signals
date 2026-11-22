<?php

namespace Platform\Signals\Services;

use Illuminate\Support\Collection;
use Platform\Signals\Models\Signal;

/**
 * Z9 „Meine Signale“: drei Bereiche für einen Nutzer.
 *  - with_me:  offen, ich bin Empfänger, nicht eskaliert (ich bin der nächste Verantwortliche)
 *  - came_up:  offen, ich bin Empfänger, eskaliert (zu mir hochgekommen – mit Grund und wie lange)
 *  - done:     in den letzten 30 Tagen erledigt oder verworfen (ich war Empfänger oder habe es erledigt)
 */
class MySignalsService
{
    public const DONE_DAYS = 30;

    /** @return array{with_me: Collection<int,Signal>, came_up: Collection<int,Signal>, done: Collection<int,Signal>} */
    public function groups(int $userId, array $teamIds, int $limit = 100): array
    {
        $with = ['entity:id,name', 'set:id,title', 'template:id,name,kind,pattern_type', 'recipientNode:id,name', 'responsible:id,name'];
        $base = fn () => Signal::query()->whereIn('team_id', $teamIds)->with($with);
        $order = fn ($q) => $q->orderByRaw("CASE severity WHEN 'algedonic' THEN 0 WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->limit($limit);

        $open = fn () => $base()->forRecipient($userId)->actionable();
        $cutoff = now()->subDays(self::DONE_DAYS);

        return [
            'with_me' => $order($open()->where(fn ($q) => $q->whereNull('escalation_level')->orWhere('escalation_level', 0)))->get(),
            'came_up' => $order($open()->where('escalation_level', '>', 0))->get(),
            'done' => $base()->whereIn('status', ['resolved', 'dismissed'])
                ->where(fn ($q) => $q->whereJsonContains('recipient_user_ids', $userId)->orWhere('resolved_by_user_id', $userId))
                ->where(fn ($q) => $q->where('resolved_at', '>=', $cutoff)->orWhere(fn ($w) => $w->whereNull('resolved_at')->where('updated_at', '>=', $cutoff)))
                ->orderByDesc('updated_at')->limit($limit)->get(),
        ];
    }
}
