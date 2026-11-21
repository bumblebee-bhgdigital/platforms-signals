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

class UpsertSetTemplateTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.set_templates.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /signals/sets/{set}/templates/{template} - Legt ein Template in ein Signal-Set oder ändert die Zuordnung. Pflicht: signal_set_id, signal_template_id. '
            . 'Overrides je Set: conditions_override (Objekt, wird über die conditions des Templates gelegt, z. B. {"value": 30}), severity_override, frequency_override, schedule_interval_hours_override; '
            . 'null setzt einen Override zurück. is_active=false pausiert das Template nur in diesem Set. remove=true nimmt es aus dem Set.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_set_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'],
                'signal_template_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'],
                'conditions_override' => ['type' => ['object', 'null']],
                'severity_override' => ['type' => ['string', 'null'], 'enum' => array_merge(SignalTemplate::SEVERITIES, [null])],
                'frequency_override' => ['type' => ['string', 'null'], 'enum' => array_merge(SignalTemplate::FREQUENCIES, [null])],
                'schedule_interval_hours_override' => ['type' => ['integer', 'null']],
                'is_active' => ['type' => 'boolean'],
                'position' => ['type' => 'integer'],
                'remove' => ['type' => 'boolean', 'description' => 'true = Template aus dem Set nehmen.'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['signal_set_id', 'signal_template_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $set = SignalSet::query()->where('team_id', $team->id)->find((int) ($arguments['signal_set_id'] ?? 0));
            if (! $set) {
                return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
            }
            if ($denied = $this->authorizeSet($context, 'update', $set)) {
                return $denied;
            }
            $template = SignalTemplate::query()->where('team_id', $team->id)->find((int) ($arguments['signal_template_id'] ?? 0));
            if (! $template) {
                return ToolResult::error('NOT_FOUND', 'Template nicht gefunden.');
            }

            $service = app(SignalTemplateService::class);
            if (! empty($arguments['remove'])) {
                $service->detach($set, $template);

                return ToolResult::success(['message' => "Template '{$template->name}' aus Set '{$set->title}' entfernt."]);
            }

            $assignment = $service->attach($set, $template, array_intersect_key($arguments, array_flip([
                'conditions_override', 'severity_override', 'frequency_override', 'schedule_interval_hours_override', 'is_active', 'position',
            ])));

            return ToolResult::success([
                'assignment' => $this->assignmentToArray($assignment->load('set')),
                'message' => "Template '{$template->name}' liegt in Set '{$set->title}'" . ($set->isActive() ? '.' : ' (Set ist noch nicht aktiv – erst aktiv wird ausgewertet).'),
            ]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['templates', 'signal-sets', 'link']);
    }
}
