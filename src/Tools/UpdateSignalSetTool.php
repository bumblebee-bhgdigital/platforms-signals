<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Tools\Concerns\HandlesSignalSets;

class UpdateSignalSetTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignalSets;

    public function getName(): string
    {
        return 'signal_sets.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /signal-sets/{id} - Ändert ein Signal-Set. Pflicht: id. '
            . 'Felder ändern (title, description, owner_user_id, responsible_agent_user_id). '
            . 'status=active aktiviert das Set, status=archived archiviert es.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->setFieldSchema(), [
                'id' => ['type' => 'integer', 'description' => 'ID des Sets (ERFORDERLICH).'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'status' => ['type' => 'string', 'enum' => ['active', 'archived'], 'description' => 'Optional: active = aktivieren, archived = archivieren.'],
            ]),
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
            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            $set = SignalSet::query()->where('team_id', $team->id)->find((int) $arguments['id']);
            if (! $set) {
                return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
            }
            if ($denied = $this->authorizeSet($context, 'update', $set)) {
                return $denied;
            }

            $status = null;
            if (! empty($arguments['status'])) {
                $status = SignalSetStatus::tryFrom((string) $arguments['status']);
                if (! in_array($status, [SignalSetStatus::ACTIVE, SignalSetStatus::ARCHIVED], true)) {
                    return ToolResult::error('VALIDATION_ERROR', "status darf nur 'active' oder 'archived' sein.");
                }
            }

            $service = app(SignalSetService::class);

            $fields = array_intersect_key($arguments, array_flip(['title', 'description', 'owner_user_id', 'responsible_agent_user_id']));
            if ($fields !== []) {
                $set = $service->update($set, $fields);
            }
            if ($status === SignalSetStatus::ACTIVE) {
                $service->activate($set);
            } elseif ($status === SignalSetStatus::ARCHIVED) {
                $service->archive($set);
            }

            $set->refresh();

            return ToolResult::success([
                'signal_set' => $this->setToArray($set),
                'message' => "Signal-Set #{$set->id} aktualisiert (Status {$set->status->value}).",
            ]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['signals', 'signal-sets', 'update', 'activate', 'archive'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
