<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Services\SignalDeliveryQueue;
use Platform\Signals\Tools\Concerns\HandlesDeliveries;

/** Z4: Warteschlange des Set-Agenten – was er im Parlan-Vorgang eines Signals tun soll. */
class GetDeliveriesTool implements ToolContract, ToolMetadataContract
{
    use HandlesDeliveries;

    public function getName(): string
    {
        return 'signals.deliveries.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signals/deliveries - Deine Zustell-Aufträge als Agent eines Signal-Sets: Signale, die als Parlan-Vorgang zugestellt werden '
            . '(ein Signal = ein Vorgang über die ganze Lebensdauer). Signals kann in Parlan nicht selbst handeln – DU führst jeden Auftrag mit deinem '
            . 'Parlan-Zugang aus und meldest danach mit signals.deliveries.POST {op_id, ok, thread?, error?} zurück. Älteste zuerst; je Signal der Reihe nach: '
            . 'nur Aufträge mit ready=true jetzt ausführen (die anderen warten auf waits_for_op_id). '
            . 'op=create: Kontext-Raum der Einheit öffnen (Organisation: POST /api/org/agent/context-room {object:"entity", id: room.id, members: payload.members}), '
            . 'darin create_thread type=handoff mit payload.title und payload.markdown, zuweisen an payload.assignee (null = offene Aufgabe, wer zuerst übernimmt) – '
            . 'thread-ID zurückmelden. op=handover: payload.members_add in den Raum aufnehmen, act_on_thread handover an payload.to mit payload.note. '
            . 'op=comment: act_on_thread comment mit payload.text. op=reopen: Vorgang wieder öffnen (geht das nicht: neuen Vorgang im selben Raum anlegen '
            . 'und die neue thread-ID melden), payload.note als Kommentar, an payload.to. op=close: act_on_thread close mit payload.note. '
            . 'Ton: Hilfe statt Macht – kurz, freundlich, ohne Schuld. signal_id filtert auf ein Signal; agent_user_id nur für Admins.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_id' => ['type' => 'integer', 'description' => 'Optional: nur Aufträge zu diesem Signal.'],
                'agent_user_id' => ['type' => 'integer', 'description' => 'Nur für Admins: Aufträge eines anderen Agenten ansehen.'],
                'limit' => ['type' => 'integer', 'description' => 'Standard 100, max. 500.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            if (! $context->user) {
                return ToolResult::error('AUTH_ERROR', 'Kein User im Kontext gefunden.');
            }
            $agentId = (int) $context->user->id;
            if (! empty($arguments['agent_user_id']) && (int) $arguments['agent_user_id'] !== $agentId) {
                if (! $this->isTeamAdmin($context)) {
                    return ToolResult::error('ACCESS_DENIED', 'agent_user_id dürfen nur Admins angeben – Agenten sehen ihre eigenen Aufträge.');
                }
                $agentId = (int) $arguments['agent_user_id'];
            }
            $limit = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
            $ops = app(SignalDeliveryQueue::class)->pendingFor($agentId, ! empty($arguments['signal_id']) ? (int) $arguments['signal_id'] : null, $limit);

            return ToolResult::success([
                'agent_user_id' => $agentId,
                'deliveries' => $ops,
                'count' => count($ops),
                'ready' => count(array_filter($ops, fn ($o) => $o['ready'])),
                'hint' => $ops === [] ? 'Nichts zu tun.' : 'Nur ready=true ausführen, danach je Auftrag signals.deliveries.POST melden.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['signals', 'deliveries', 'parlan', 'agent'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
