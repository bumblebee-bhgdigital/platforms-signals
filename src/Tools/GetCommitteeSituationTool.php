<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Services\CircleService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class GetCommitteeSituationTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.committee_items.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signals/committee-items - Lage eines Lenkungsausschusses für die Vorbereitung (Steward): nächste Sitzung, Signale, die schon im LA liegen, '
            . 'und Kandidaten – offene Signale der Einheiten unter diesem LA – mit Verantwortlichem und Entscheidungsrahmen je Einheit. '
            . 'Pflicht: committee_entity_id (Knoten des Gremiums, z. B. 39). Danach: lokal lösen (signals.signals.PUT action=solve_locally) oder nach der Faustregel in den LA heben (signals.committee_items.POST).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['committee_entity_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'], 'team_id' => ['type' => 'integer']], 'required' => ['committee_entity_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $s = app(CircleService::class)->committeeSituation((int) ($arguments['committee_entity_id'] ?? 0), $team->id);

            return ToolResult::success([
                'next_meeting' => $s['next_meeting'],
                'in_committee' => $s['in_committee']->map(fn ($x) => $this->signalToArray($x))->values()->all(),
                'candidates' => $s['candidates']->map(fn ($x) => $this->signalToArray($x))->values()->all(),
                'units' => $s['units'],
                'rule_of_thumb' => 'In den LA nur, wenn andere Einheiten, gemeinsame Ressourcen oder strategische Prioritäten betroffen sind. Sonst lokal lösen. Eine Ampel ist keine Managementfrage.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['committee', 'lenkungsausschuss', 'lagebild', 'regelkreis'], true);
    }
}
