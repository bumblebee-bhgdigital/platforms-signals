<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalTemplate;
use Platform\Signals\Services\SignalTemplateService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class CreateSignalTemplateTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.templates.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signals/templates - Legt ein Signal-Template an. Pflicht: name, kind (rule | inference, Default rule). '
            . 'rule: pattern_type + conditions. threshold {metric, operator, value}; trend {metric, direction increasing|decreasing, periods, min_change_percent}; '
            . 'ratio {numerator, denominator, operator, value, min_denominator?, grace_days?}; cross_dimension {metric_a, metric_b, relationship diverging|converging}. '
            . 'no_responsible {capability?: manage} = „Einheit ohne Verantwortlichen“: jede Einheit (VSM-Klasse carrier) im Geltungsbereich, an der niemand die Fähigkeit hält (Standard-Schweregrad critical; Empfänger ist der Verantwortliche darüber; erledigt sich, sobald jemand eingetragen ist). '
            . 'Wo eine Regel nicht gelten soll: im Set weiter unten abwählen (signals.set_templates.PUT opts_out=true) – Knotentyp-Filter gibt es nicht mehr. '
            . 'Kennzahlen sind die Metriken der Knoten-Snapshots (z. B. items_open, items_done, items_total, time_total_minutes, planner_items_overdue, mb_*). frequency: every_snapshot | daily | weekly. '
            . 'inference: prompt, vsm_system (s1–s5, s3_star), dimension, schedule_interval_hours. '
            . 'Optional signal_set_id: Template direkt in dieses Set legen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'ERFORDERLICH: Name.'],
                'kind' => ['type' => 'string', 'enum' => SignalTemplate::KINDS],
                'description' => ['type' => 'string'],
                'severity' => ['type' => 'string', 'enum' => SignalTemplate::SEVERITIES, 'description' => 'Default warning.'],
                'is_active' => ['type' => 'boolean'],
                'pattern_type' => ['type' => 'string', 'enum' => SignalTemplate::PATTERN_TYPES],
                'conditions' => ['type' => 'object', 'description' => 'Bedingungen je Muster (siehe Beschreibung).'],
                'frequency' => ['type' => 'string', 'enum' => SignalTemplate::FREQUENCIES],
                'prompt' => ['type' => 'string'],
                'vsm_system' => ['type' => 'string', 'enum' => SignalTemplate::VSM_SYSTEMS],
                'dimension' => ['type' => 'string'],
                'schedule_interval_hours' => ['type' => 'integer'],
                'signal_set_id' => ['type' => 'integer', 'description' => 'Optional: direkt in dieses Set legen.'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $set = null;
            if (! empty($arguments['signal_set_id'])) {
                $set = SignalSet::query()->where('team_id', $team->id)->find((int) $arguments['signal_set_id']);
                if (! $set) {
                    return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
                }
                if ($denied = $this->authorizeSet($context, 'update', $set)) {
                    return $denied;
                }
            }

            $service = app(SignalTemplateService::class);
            $template = $service->create($context->user, $team->id, $arguments);
            if ($set) {
                $service->attach($set, $template);
            }

            return ToolResult::success([
                'template' => $this->templateToArray($template->fresh(), true),
                'message' => "Template #{$template->id} angelegt" . ($set ? " und in Set #{$set->id} gelegt." : '. Mit signals.set_templates.PUT in ein Set legen, damit es wirkt.'),
            ]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['templates', 'create']);
    }
}
