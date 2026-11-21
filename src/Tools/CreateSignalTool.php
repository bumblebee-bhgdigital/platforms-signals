<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalTemplate;
use Platform\Signals\Services\SignalService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class CreateSignalTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.signals.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signals/signals - Löst ein Signal an einem Knoten aus – z. B. als Ergebnis eines KI-Templates (Agent wertet aus) oder von Hand. '
            . 'Pflicht: entity_id, message. Optional signal_set_id + signal_template_id (Herkunft; Schwere und Zuständigkeit kommen dann aus dem Set), severity, vsm_level, '
            . 'actions (1–3 Maßnahmen {title, description}), evidence, trigger_metrics, affected_entity_ids, assignee_entity_id, perspective_entity_id, deadline_at. '
            . 'Pro Template, Set und Knoten gibt es höchstens EIN offenes Signal: ein weiterer Aufruf aktualisiert das bestehende (Schwere steigt nur, sinkt nie). '
            . 'Für den Umzug aus der Organisation: legacy_organization_signal_id, status, created_at.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH: betroffener Knoten.'],
                'message' => ['type' => 'string', 'description' => 'ERFORDERLICH: Erkenntnis / diagnostische Aussage.'],
                'signal_set_id' => ['type' => 'integer'],
                'signal_template_id' => ['type' => 'integer'],
                'severity' => ['type' => 'string', 'enum' => Signal::SEVERITIES],
                'vsm_level' => ['type' => 'string', 'enum' => Signal::VSM_LEVELS],
                'actions' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['pending', 'applied', 'dismissed']]], 'required' => ['title']]],
                'evidence' => ['type' => 'object'],
                'trigger_metrics' => ['type' => 'object'],
                'affected_entity_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'assignee_entity_id' => ['type' => 'integer'],
                'perspective_entity_id' => ['type' => 'integer'],
                'deadline_at' => ['type' => 'string', 'description' => 'ISO-Datum/Zeit.'],
                'status' => ['type' => 'string', 'enum' => Signal::STATUSES, 'description' => 'Nur für den Umzug, Default open.'],
                'created_at' => ['type' => 'string', 'description' => 'Nur für den Umzug: ursprünglicher Zeitpunkt.'],
                'legacy_organization_signal_id' => ['type' => 'integer', 'description' => 'Nur für den Umzug: ID des Signals in der Organisation (idempotent).'],
                'resolution_note' => ['type' => 'string', 'description' => 'Nur für den Umzug geschlossener Signale.'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['entity_id', 'message'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }

            $data = $arguments;
            $data['team_id'] = $team->id;
            $data['source'] = 'inference';
            $data['source_type'] = Signal::SOURCE_TYPE_MANUAL;

            $set = null;
            $template = null;
            if (! empty($arguments['signal_set_id'])) {
                $set = SignalSet::query()->where('team_id', $team->id)->find((int) $arguments['signal_set_id']);
                if (! $set) {
                    return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
                }
                $data['responsible_user_id'] = $set->responsible_agent_user_id;
            }
            if (! empty($arguments['signal_template_id'])) {
                $template = SignalTemplate::query()->where('team_id', $team->id)->find((int) $arguments['signal_template_id']);
                if (! $template) {
                    return ToolResult::error('NOT_FOUND', 'Template nicht gefunden.');
                }
                $data['source_type'] = $template->isInference() ? Signal::SOURCE_TYPE_INFERENCE : Signal::SOURCE_TYPE_MANUAL;
                $data['severity'] ??= ($set ? ($set->templateAssignments()->where('signal_template_id', $template->id)->first()?->effectiveSeverity()) : null) ?? $template->severity;
                $data['vsm_level'] ??= $template->vsm_system;
            }
            if (! empty($arguments['legacy_organization_signal_id'])) {
                $data['source'] = 'migration';
                $existing = Signal::withTrashed()->where('legacy_organization_signal_id', (int) $arguments['legacy_organization_signal_id'])->first();
                if ($existing) {
                    return ToolResult::success(['signal' => $this->signalToArray($existing), 'message' => "Bereits übernommen als Signal #{$existing->id}.", 'created' => false]);
                }
            }
            // Agent als Urheber (Organisations-Entity des aufrufenden Agenten), wenn vorhanden
            if (empty($data['created_by_agent_entity_id'])) {
                $data['created_by_agent_entity_id'] = \Platform\Signals\Support\Agents::entityId($context->user);
            }

            // Ein offenes Signal je Template/Set/Knoten: aktualisieren statt duplizieren
            if ($template && empty($arguments['legacy_organization_signal_id'])) {
                $open = Signal::query()->where('team_id', $team->id)
                    ->where('signal_template_id', $template->id)->where('signal_set_id', $set?->id)
                    ->where('entity_id', (int) $arguments['entity_id'])->whereIn('status', ['open', 'acknowledged'])->first();
                if ($open) {
                    $rank = array_flip(Signal::SEVERITIES);
                    $severity = ($rank[$data['severity'] ?? $open->severity] ?? 0) > ($rank[$open->severity] ?? 0) ? $data['severity'] : $open->severity;
                    $open->update(array_filter([
                        'message' => $arguments['message'],
                        'severity' => $severity,
                        'evidence' => $arguments['evidence'] ?? null,
                        'trigger_metrics' => $arguments['trigger_metrics'] ?? null,
                    ], fn ($v) => $v !== null));

                    return ToolResult::success(['signal' => $this->signalToArray($open->fresh(), true), 'message' => "Bestehendes Signal #{$open->id} aktualisiert.", 'created' => false]);
                }
            }

            $signal = app(SignalService::class)->create($data, $context->user);

            return ToolResult::success(['signal' => $this->signalToArray($signal, true), 'message' => "Signal #{$signal->id} ausgelöst.", 'created' => true]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Auslösen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['create', 'inference', 'algedonic']);
    }
}
