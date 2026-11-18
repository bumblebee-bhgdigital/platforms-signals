<?php

namespace Platform\Signals\Tools;

use Illuminate\Support\Facades\Gate;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Tools\Concerns\HandlesSignalSets;

class CreateSignalSetTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignalSets;

    public function getName(): string
    {
        return 'signal_sets.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signal-sets - Legt ein Signal-Set an (Status standardmäßig draft). Pflicht: title. '
            . 'status=active aktiviert es sofort. Ein Set kann danach an mehrere Knoten gehängt werden (signal_sets.PUT).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->setFieldSchema(), [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active'], 'description' => 'Optional: draft (Default) oder active.'],
            ]),
            'required' => ['title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            if (! Gate::forUser($context->user)->allows('create', SignalSet::class)) {
                return ToolResult::error('ACCESS_DENIED', 'Du darfst keine Signal-Sets anlegen.');
            }

            $set = app(SignalSetService::class)->create($context->user, $team, $arguments);

            return ToolResult::success([
                'signal_set' => $this->setToArray($set),
                'message' => "Signal-Set '{$set->title}' (#{$set->id}) angelegt (Status {$set->status->value}).",
            ]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['signals', 'signal-sets', 'create'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
