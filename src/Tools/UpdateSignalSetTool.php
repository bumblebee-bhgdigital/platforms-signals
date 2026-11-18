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
            . 'status=active aktiviert das Set, status=archived archiviert es. '
            . 'Knoten (ein Set kann an mehrere Knoten hängen): entity_id hängt zusätzlich an diesen Knoten (inherit_down = Vererbung nach unten; ist das Set dort schon verknüpft und inherit_down gesetzt, wird nur die Vererbung geändert). '
            . 'from_entity_id + entity_id hängt von einem Knoten auf einen anderen um (Vererbungs-Option wandert mit). '
            . 'unlink_entity_id löst die Zuordnung zu einem Knoten, unlink=true löst alle Knoten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->setFieldSchema(), [
                'id' => ['type' => 'integer', 'description' => 'ID des Sets (ERFORDERLICH).'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'status' => ['type' => 'string', 'enum' => ['active', 'archived'], 'description' => 'Optional: active = aktivieren, archived = archivieren.'],
                'entity_id' => ['type' => 'integer', 'description' => 'Optional: Knoten, an den das Set zusätzlich gehängt wird (bzw. Ziel beim Umhängen mit from_entity_id).'],
                'inherit_down' => ['type' => 'boolean', 'description' => 'Optional (mit entity_id): Set wirkt auch an allen Unterknoten.'],
                'from_entity_id' => ['type' => 'integer', 'description' => 'Optional: Umhängen von diesem Knoten auf entity_id.'],
                'unlink_entity_id' => ['type' => 'integer', 'description' => 'Optional: Zuordnung zu diesem Knoten lösen.'],
                'unlink' => ['type' => 'boolean', 'description' => 'Optional: alle Knoten-Zuordnungen lösen (Set wird unverknüpft).'],
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

            $entityId = ! empty($arguments['entity_id']) ? (int) $arguments['entity_id'] : null;
            $fromEntityId = ! empty($arguments['from_entity_id']) ? (int) $arguments['from_entity_id'] : null;
            $unlinkEntityId = ! empty($arguments['unlink_entity_id']) ? (int) $arguments['unlink_entity_id'] : null;
            if ($fromEntityId && ! $entityId) {
                return ToolResult::error('VALIDATION_ERROR', 'from_entity_id erfordert entity_id (Ziel-Knoten).');
            }
            foreach (array_filter([$entityId, $fromEntityId, $unlinkEntityId]) as $checkId) {
                if (! $this->canWriteEntity($context, $checkId)) {
                    return ToolResult::error('ACCESS_DENIED', "Kein Schreibrecht auf Knoten {$checkId} (oder Knoten existiert nicht).");
                }
            }

            $service = app(SignalSetService::class);

            $fields = array_intersect_key($arguments, array_flip(['title', 'description', 'owner_user_id', 'responsible_agent_user_id']));
            if ($fields !== []) {
                $set = $service->update($set, $fields);
            }
            if (! empty($arguments['unlink'])) {
                $service->unlink($set);
            }
            if ($unlinkEntityId) {
                $service->unlink($set, $unlinkEntityId);
            }
            if ($entityId && $fromEntityId) {
                $service->relink($set, $fromEntityId, $entityId);
            } elseif ($entityId) {
                if (in_array($entityId, $service->nodeIds($set), true)) {
                    if (array_key_exists('inherit_down', $arguments)) {
                        $service->setInherit($set, $entityId, (bool) $arguments['inherit_down']);
                    }
                } else {
                    $service->link($set, $entityId, ! empty($arguments['inherit_down']));
                }
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
            'tags' => ['signals', 'signal-sets', 'update', 'activate', 'archive', 'link'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
