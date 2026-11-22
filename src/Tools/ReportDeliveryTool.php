<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalDeliveryOp;
use Platform\Signals\Services\SignalDeliveryQueue;
use Platform\Signals\Tools\Concerns\HandlesDeliveries;

/** Z4: Rückmeldung des Set-Agenten zu einem Zustell-Auftrag. */
class ReportDeliveryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDeliveries;

    public function getName(): string
    {
        return 'signals.deliveries.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signals/deliveries - Meldet zurück, ob du einen Zustell-Auftrag aus signals.deliveries.GET in Parlan ausgeführt hast. Pflicht: op_id, ok. '
            . 'ok=true: Auftrag erledigt. Bei op=create ist thread Pflicht (ID des neuen Parlan-Vorgangs) – darüber ordnet Signals die Reaktionen '
            . '(annehmen, erledigt, ablehnen) dem Signal zu. Hast du bei reopen/handover einen neuen Vorgang anlegen müssen, die neue thread-ID mitgeben. '
            . 'ok=false: error (was schiefging) angeben – der Auftrag bleibt offen und wird erneut angeboten; nach 5 Fehlversuchen ist er fehlgeschlagen '
            . 'und am Signal steht ein Systemkommentar (das Signal bleibt über die Seite erreichbar). Je Signal der Reihe nach melden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'op_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH: op_id aus signals.deliveries.GET.'],
                'ok' => ['type' => 'boolean', 'description' => 'ERFORDERLICH: true = in Parlan ausgeführt, false = fehlgeschlagen.'],
                'thread' => ['type' => 'string', 'description' => 'Parlan-Vorgang (Thread-ID). Pflicht bei create; bei reopen/handover, falls ein neuer Vorgang nötig war.'],
                'error' => ['type' => 'string', 'description' => 'Bei ok=false: was schiefging.'],
            ],
            'required' => ['op_id', 'ok'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            if (! $context->user) {
                return ToolResult::error('AUTH_ERROR', 'Kein User im Kontext gefunden.');
            }
            if (empty($arguments['op_id']) || ! array_key_exists('ok', $arguments)) {
                return ToolResult::error('VALIDATION_ERROR', 'op_id und ok sind erforderlich.');
            }
            $op = SignalDeliveryOp::with('signal')->find((int) $arguments['op_id']);
            if (! $op || ! $op->signal) {
                return ToolResult::error('NOT_FOUND', 'Auftrag nicht gefunden.');
            }
            $queue = app(SignalDeliveryQueue::class);
            if ($queue->agentUserId($op->signal) !== (int) $context->user->id && ! $this->isTeamAdmin($context)) {
                return ToolResult::error('ACCESS_DENIED', 'Dieser Auftrag gehört dem Agenten eines anderen Sets.');
            }
            $ok = filter_var($arguments['ok'], FILTER_VALIDATE_BOOLEAN);
            ['op' => $op, 'signal' => $signal] = $queue->report($op, $ok, isset($arguments['thread']) ? (string) $arguments['thread'] : null, isset($arguments['error']) ? (string) $arguments['error'] : null);
            $next = $queue->pendingFor((int) ($queue->agentUserId($signal) ?? $context->user->id), (int) $signal->id, 1)[0] ?? null;

            return ToolResult::success([
                'op' => ['id' => $op->id, 'op' => $op->op, 'status' => $op->status, 'attempts' => $op->attempts, 'last_error' => $op->last_error],
                'signal' => ['id' => $signal->id, 'delivery_state' => $signal->delivery_state, 'delivery_thread' => $signal->delivery_thread],
                'next_for_signal' => $next,
            ]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['signals', 'deliveries', 'parlan', 'agent'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
