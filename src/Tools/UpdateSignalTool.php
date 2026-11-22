<?php

namespace Platform\Signals\Tools;

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\SignalService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class UpdateSignalTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.signals.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /signals/signals/{id} - Bearbeitet ein Signal. Pflicht: id. '
            . 'action: acknowledge (gesehen/übernommen, stoppt die Reaktionsuhr) | resolve | dismiss | reopen | reject („gehört nicht zu mir“, reason Pflicht – eskaliert sofort eine Stufe: Linie, Gremium oder nur markiert, je Zuordnung) '
            . '| solve_locally (die Einheit hat es im Entscheidungsrahmen selbst gelöst; resolution_note Pflicht, frame_id optional). acknowledge/resolve/solve_locally/reject setzen reacted_at. Beim Erledigen Rückkopplung festhalten: resolution_note (wodurch erledigt) '
            . 'und Verweis link_type + link_id (Morph-Alias eines registrierten Objekt-Typs, z. B. eines Beschlusses oder einer Aufgabe; bei falschem Typ nennt der Fehler die erlaubten); reason bei dismiss. '
            . 'Außerdem: assignee_entity_id (zuweisen, 0 = entfernen), snooze_until (ISO, zurückstellen) / unsnooze=true, '
            . 'action_id + action_status (Maßnahme applied|dismissed|pending entscheiden; Signal-Status folgt), focus=true|false (eigener Fokus), comment.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'],
                'action' => ['type' => 'string', 'enum' => ['acknowledge', 'resolve', 'dismiss', 'reopen', 'reject', 'solve_locally']],
                'frame_id' => ['type' => 'integer', 'description' => 'Mit solve_locally: Beschluss (Entscheidungsrahmen), auf den sich die lokale Lösung stützt.'],
                'resolution_note' => ['type' => 'string'],
                'link_type' => ['type' => 'string'],
                'link_id' => ['type' => 'integer'],
                'reason' => ['type' => 'string', 'description' => 'Pflicht bei reject (warum gehört es nicht zu mir?), optional bei dismiss.'],
                'assignee_entity_id' => ['type' => 'integer'],
                'snooze_until' => ['type' => 'string'],
                'unsnooze' => ['type' => 'boolean'],
                'action_id' => ['type' => 'integer'],
                'action_status' => ['type' => 'string', 'enum' => ['pending', 'applied', 'dismissed']],
                'action_reason' => ['type' => 'string'],
                'focus' => ['type' => 'boolean'],
                'comment' => ['type' => 'string'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $signal = Signal::query()->where('team_id', $team->id)->find((int) ($arguments['id'] ?? 0));
            if (! $signal) {
                return ToolResult::error('NOT_FOUND', 'Signal nicht gefunden.');
            }
            $service = app(SignalService::class);
            $done = [];

            if (array_key_exists('assignee_entity_id', $arguments)) {
                $signal = $service->assign($signal, (int) $arguments['assignee_entity_id'] ?: null);
                $done[] = 'zugewiesen';
            }
            if (! empty($arguments['unsnooze'])) {
                $signal = $service->snooze($signal, null);
                $done[] = 'Zurückstellung aufgehoben';
            } elseif (! empty($arguments['snooze_until'])) {
                $until = Carbon::parse((string) $arguments['snooze_until']);
                if ($until->isPast()) {
                    return ToolResult::error('VALIDATION_ERROR', 'snooze_until muss in der Zukunft liegen.');
                }
                $signal = $service->snooze($signal, $until);
                $done[] = 'zurückgestellt bis ' . $until->toDateTimeString();
            }
            if (! empty($arguments['action_id'])) {
                $signal = $service->decideAction($signal, (int) $arguments['action_id'], (string) ($arguments['action_status'] ?? 'applied'), $context->user, $arguments['action_reason'] ?? null);
                $done[] = 'Maßnahme entschieden';
            }
            if (array_key_exists('focus', $arguments) && $context->user) {
                $isFocused = $signal->isFocusedBy($context->user);
                if ((bool) $arguments['focus'] !== $isFocused) {
                    $service->toggleFocus($signal, $context->user);
                }
                $done[] = $arguments['focus'] ? 'im Fokus' : 'aus dem Fokus';
            }
            if (($arguments['action'] ?? null) === 'solve_locally') {
                $signal = app(\Platform\Signals\Services\CircleService::class)->solveLocally($signal, (string) ($arguments['resolution_note'] ?? ''), $context->user, ! empty($arguments['frame_id']) ? (int) $arguments['frame_id'] : null);
                $done[] = 'lokal gelöst';
            } elseif (! empty($arguments['action'])) {
                $signal = $service->transition($signal, (string) $arguments['action'], $context->user, [
                    'reason' => $arguments['reason'] ?? null,
                    'resolution_note' => $arguments['resolution_note'] ?? null,
                    'link_type' => $arguments['link_type'] ?? null,
                    'link_id' => $arguments['link_id'] ?? null,
                    'comment' => $arguments['comment'] ?? null,
                ]);
                $done[] = 'Status ' . $signal->status;
            } elseif (! empty($arguments['comment'])) {
                $service->comment($signal, (string) $arguments['comment'], $context->user);
                $done[] = 'kommentiert';
            }

            if ($done === []) {
                return ToolResult::error('VALIDATION_ERROR', 'Nichts zu tun: action, assignee_entity_id, snooze_until, action_id, focus oder comment angeben.');
            }

            return ToolResult::success(['signal' => $this->signalToArray($signal->fresh(['actions']), true), 'message' => "Signal #{$signal->id}: " . implode(', ', $done) . '.']);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Bearbeiten: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['update', 'acknowledge', 'resolve', 'feedback']);
    }
}
