<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\CircleService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class CreateCommitteeItemTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.committee_items.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signals/committee-items - Hebt ein Signal in den Lenkungsausschuss über seiner Einheit: legt einen LA-Punkt nach den vier Fragen als ENTWURF in die nächste Sitzung (der Vorsitz kann ihn übernehmen oder herausnehmen). '
            . 'Nur wenn die Faustregel greift: andere Einheiten, gemeinsame Ressourcen oder strategische Prioritäten sind betroffen – sonst lokal lösen (signals.signals.PUT action=solve_locally). '
            . 'Pflicht: signal_id, goal (1 Was wollten wir erreichen?), observation (2 Was beobachten wir?), obstacle (3 Was verhindert es?), local_solution (4a Was kann der Bereich selbst lösen?), system_support (4b Wobei braucht er das Gesamtsystem?). '
            . 'Ohne 4b kein LA-Punkt. local_solution_confirmed=true, wenn der Bereich 4a bestätigt hat. Optional title. Hilfelogik statt Schuldfrage.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'],
                'title' => ['type' => 'string'],
                'goal' => ['type' => 'string', 'description' => 'ERFORDERLICH: 1 Was wollten wir erreichen?'],
                'observation' => ['type' => 'string', 'description' => 'ERFORDERLICH: 2 Was beobachten wir tatsächlich?'],
                'obstacle' => ['type' => 'string', 'description' => 'ERFORDERLICH: 3 Was verhindert die Zielerreichung?'],
                'local_solution' => ['type' => 'string', 'description' => 'ERFORDERLICH: 4a Was kann der Bereich selbst lösen?'],
                'system_support' => ['type' => 'string', 'description' => 'ERFORDERLICH: 4b Wobei braucht er das Gesamtsystem?'],
                'local_solution_confirmed' => ['type' => 'boolean', 'description' => 'Hat der Bereich 4a bestätigt?'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['signal_id', 'goal', 'observation', 'obstacle', 'local_solution', 'system_support'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $signal = Signal::query()->where('team_id', $team->id)->find((int) ($arguments['signal_id'] ?? 0));
            if (! $signal) {
                return ToolResult::error('NOT_FOUND', 'Signal nicht gefunden.');
            }
            $r = app(CircleService::class)->liftToCommittee($signal, $arguments, $context->user);

            return ToolResult::success([
                'signal' => $this->signalToArray($r['signal']),
                'committee' => $r['committee'],
                'meeting' => $r['meeting'],
                'agenda_item' => $r['agenda_item'],
                'message' => "LA-Punkt als Entwurf in „{$r['meeting']['title']}“ ({$r['committee']['name']}) angelegt. Der Vorsitz entscheidet, ob er auf die Agenda kommt.",
            ]);
        } catch (SignalSetException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['committee', 'lenkungsausschuss', 'agenda', 'regelkreis']);
    }
}
