<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\User;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\MySignalsService;
use Platform\Signals\Support\Agents;
use Platform\Signals\Support\SignalPresenter;
use Platform\Signals\Tools\Concerns\HandlesSignals;

/** Z9 „Meine Signale“ per MCP – dieselben drei Bereiche wie die Seite /signals/mine. */
class GetMySignalsTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.mine.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signals/mine - „Meine Signale“: was beim aufrufenden Nutzer liegt. Drei Bereiche: with_me (offen, ich bin der nächste Verantwortliche), '
            . 'came_up (offen, zu mir hochgekommen – mit escalation_reason, recipient_reason und wie lange), done (letzte 30 Tage erledigt/verworfen). '
            . 'Reagieren mit signals.signals.PUT: action=acknowledge (gesehen), resolve + resolution_note (gelöst), reject + reason (gehört nicht zu mir). '
            . 'user_id nur für Agenten (für die Person, die sie unterstützen); Menschen sehen nur ihre eigenen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'Nur für Agenten: Signale dieser Person statt der eigenen.'],
                'limit' => ['type' => 'integer', 'description' => 'Je Bereich (Standard 50, max. 200).'],
                'team_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $userId = (int) $context->user->id;
            if (! empty($arguments['user_id']) && (int) $arguments['user_id'] !== $userId) {
                if (! Agents::isAgent($context->user)) {
                    return ToolResult::error('ACCESS_DENIED', 'user_id dürfen nur Agenten angeben – Menschen sehen ihre eigenen Signale.');
                }
                $userId = (int) $arguments['user_id'];
            }
            $limit = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
            $groups = app(MySignalsService::class)->groups($userId, [(int) $team->id], $limit);

            $row = fn (Signal $s) => $this->signalToArray($s) + [
                'waiting' => SignalPresenter::waiting($s->delivered_at),
                'waiting_workdays' => $s->delivered_at ? (int) $s->delivered_at->diffInWeekdays(now()) : null,
            ];

            return ToolResult::success([
                'user' => ['id' => $userId, 'name' => User::whereKey($userId)->value('name')],
                'with_me' => $groups['with_me']->map($row)->values()->all(),
                'came_up' => $groups['came_up']->map($row)->values()->all(),
                'done' => $groups['done']->map($row)->values()->all(),
                'counts' => array_map(fn ($g) => $g->count(), $groups),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['mine', 'inbox', 'list'], true);
    }
}
