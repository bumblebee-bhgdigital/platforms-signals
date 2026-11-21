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
        return 'GET /signals/committee-items - Lage der Regelkreise für ihre Stewards (Mensch oder Agent). Ohne committee_entity_id: alle Gremien, deren Regelkreis der Aufrufer betreut '
            . '(Beziehung „betreut (Regelkreis)“ in der Organisation). Je Gremium: Ebene (unit = operativ, z. B. Venture-LA; group = Gruppe), Stewards, nächste Sitzung, '
            . 'Signale im LA, an diesen LA eskalierte Signale (von einem LA darunter) und Kandidaten – nur Signale, für die dieser LA der nächste ist (bei fehlendem eigenem LA '
            . 'stellvertretend, siehe units.*.proxy_for) – mit Verantwortlichem und Entscheidungsrahmen je Einheit. '
            . 'Danach: lokal lösen (signals.signals.PUT action=solve_locally) oder nach der Faustregel in den LA heben (signals.committee_items.POST).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'committee_entity_id' => ['type' => 'integer', 'description' => 'Optional: ein Gremium (Knoten). Ohne: alle, die ich betreue.'],
            'team_id' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $service = app(CircleService::class);
            $ids = ! empty($arguments['committee_entity_id'])
                ? [(int) $arguments['committee_entity_id']]
                : array_map(fn ($c) => (int) $c['entity_id'], $service->committeesFor((int) $context->user->id));
            if ($ids === []) {
                return ToolResult::success(['circles' => [], 'message' => 'Du betreust keinen Regelkreis. Zuordnung über die Beziehung „betreut (Regelkreis)“ zum Gremium-Knoten.']);
            }

            $circles = [];
            foreach ($ids as $id) {
                $x = $service->committeeSituation($id, $team->id);
                $circles[] = [
                    'committee' => $x['committee'],
                    'stewards' => $x['stewards'],
                    'next_meeting' => $x['next_meeting'],
                    'in_committee' => $x['in_committee']->map(fn ($s) => $this->signalToArray($s))->values()->all(),
                    'escalated_here' => $x['escalated_here']->map(fn ($s) => $this->signalToArray($s) + ['origin_committee_entity_id' => $s->origin_committee_entity_id])->values()->all(),
                    'candidates' => $x['candidates']->map(fn ($s) => $this->signalToArray($s))->values()->all(),
                    'units' => $x['units'],
                ];
            }

            return ToolResult::success([
                'circles' => $circles,
                'rule_of_thumb' => 'In den LA nur, wenn andere Einheiten seiner Ebene, gemeinsame Ressourcen oder strategische Prioritäten betroffen sind. Sonst lokal lösen. Eine Ampel ist keine Managementfrage. Eskaliertes von unten ist immer ein Kandidat.',
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
