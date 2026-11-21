<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalTemplate;
use Platform\Signals\Services\SignalTemplateService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class UpdateSignalTemplateTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.templates.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /signals/templates/{id} - Ändert ein Signal-Template (wirkt in allen Sets, die es nutzen; Overrides je Set bleiben). '
            . 'Felder wie bei signals.templates.POST, kind ist nicht änderbar. is_active=false schaltet das Template überall ab.';
    }

    public function getSchema(): array
    {
        $create = (new CreateSignalTemplateTool())->getSchema()['properties'];
        unset($create['kind'], $create['signal_set_id']);

        return [
            'type' => 'object',
            'properties' => array_merge(['id' => ['type' => 'integer', 'description' => 'ERFORDERLICH: Template-ID.']], $create),
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
            $template = SignalTemplate::query()->where('team_id', $team->id)->find((int) ($arguments['id'] ?? 0));
            if (! $template) {
                return ToolResult::error('NOT_FOUND', 'Template nicht gefunden.');
            }
            $data = $arguments;
            unset($data['id'], $data['team_id']);
            $template = app(SignalTemplateService::class)->update($template, $data);

            return ToolResult::success(['template' => $this->templateToArray($template, true), 'message' => "Template #{$template->id} aktualisiert."]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['templates', 'update']);
    }
}
