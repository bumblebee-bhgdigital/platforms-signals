<?php

namespace Platform\Signals\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Evaluation\RuleEvaluator;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Models\SignalTemplate;

/**
 * Wertet die Regel-Templates aktiver Signal-Sets an deren Knoten aus.
 *
 * Unterschied zur Organisation: keine Auswertung pauschal über alle Knoten mehr, sondern nur dort,
 * wo ein Set hängt (direkt, mit inherit_down auch an allen Unterknoten). Takt je Zuordnung
 * (every_snapshot | daily | weekly).
 *
 * Z1 „das nächste gewinnt“: Pro Knoten und Template wertet nur die gewinnende Zuordnung aus
 * (EffectiveAssignmentService) – egal, wie viele Sets darüber hängen. Höchstens ein offenes Signal je
 * (Team, Knoten, Template). Abwahl ersetzt den früheren Typ-Filter (entity_types / exclude_entity_types –
 * diese Schlüssel werden ignoriert, Signals kennt keine Knotentyp-Codes).
 * Z4: Kommt dieselbe Sache im Zeitfenster wieder, wird das alte Signal wieder geöffnet (recurrence_count).
 * Empfänger setzt die Zustellung (SignalDelivery); der Agent des Sets bleibt Absender und Begleiter.
 *
 * KI-Templates (kind=inference) wertet nicht der Server aus, sondern der Agent per MCP.
 */
class SignalEvaluationService
{
    /** Schlüssel des früheren Typ-Filters – werden aus Kompatibilität still ignoriert. */
    public const IGNORED_CONDITION_KEYS = ['entity_types', 'exclude_entity_types'];

    /** @var array<int, int[]> Set => Knoten im Geltungsbereich (pro Lauf) */
    private array $scopeCache = [];

    public function __construct(
        protected RuleEvaluator $rules,
        protected SignalSetService $sets,
        protected EffectiveAssignmentService $assignments,
    ) {
    }

    /**
     * @param  int|null  $setId  nur Zuordnungen dieses Sets (dort, wo sie gewinnen)
     * @param  bool  $force  Takt ignorieren
     * @param  bool  $dryRun  nichts schreiben, nur berichten
     * @return array{sets:int, evaluated:int, created:int, resolved:int, reopened:int, skipped_not_due:int, results:array}
     */
    public function run(?int $setId = null, bool $force = false, bool $dryRun = false, ?int $teamId = null): array
    {
        $query = SignalSet::query()->where('status', SignalSetStatus::ACTIVE->value)
            ->with(['templateAssignments.template']);
        if ($setId) {
            $query->whereKey($setId);
        }
        if ($teamId) {
            $query->where('team_id', $teamId);
        }
        $sets = $query->get();
        $this->scopeCache = [];

        $report = ['sets' => $sets->count(), 'evaluated' => 0, 'created' => 0, 'resolved' => 0, 'reopened' => 0, 'skipped_not_due' => 0, 'results' => []];

        // Regel-Templates, die in diesen Sets aktiv zugeordnet sind
        $templates = $sets->flatMap(fn (SignalSet $set) => $set->templateAssignments)
            ->filter(fn (SignalSetTemplate $a) => $a->is_active && ! $a->opts_out && $a->template && $a->template->is_active && $a->template->isRule())
            ->map(fn (SignalSetTemplate $a) => $a->template)->unique('id')->values();
        $setIds = $sets->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($templates as $template) {
            // Geltungsbereich des Templates über alle aktiven Sets (auch fremde: dort könnte ein näheres gewinnen)
            $scope = $this->templateScope($template);
            $map = $this->assignments->effectiveMap((int) $template->id, $scope);

            $groups = [];
            foreach ($map as $entityId => $assignment) {
                if ($assignment) {
                    $groups[$assignment->id]['assignment'] = $assignment;
                    $groups[$assignment->id]['entities'][] = (int) $entityId;
                }
            }

            foreach ($groups as $group) {
                /** @var SignalSetTemplate $assignment */
                $assignment = $group['assignment'];
                if (! in_array((int) $assignment->signal_set_id, $setIds, true)) {
                    continue; // gewinnt hier, gehört aber nicht zu diesem Lauf (Set-/Team-Filter)
                }
                if (! $force && ! $assignment->isDue()) {
                    $report['skipped_not_due']++;
                    continue;
                }

                try {
                    $result = $this->evaluateAssignment($assignment->set, $assignment, $group['entities'], $dryRun);
                    $report['evaluated']++;
                    $report['created'] += $result['created'];
                    $report['resolved'] += $result['resolved'];
                    $report['reopened'] += $result['reopened'];
                    $report['results'][] = $result;
                    if (! $dryRun) {
                        $assignment->forceFill(['last_evaluated_at' => now(), 'last_error' => null])->save();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Signals: Auswertung fehlgeschlagen', ['set' => $assignment->signal_set_id, 'template' => $template->id, 'error' => $e->getMessage()]);
                    $report['results'][] = ['set_id' => $assignment->signal_set_id, 'template_id' => $template->id, 'error' => $e->getMessage()];
                    if (! $dryRun) {
                        $assignment->forceFill(['last_evaluated_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                    }
                }
            }

            // Wo keine Zuordnung mehr gilt (abgewählt, Set abgehängt), schließen sich offene Regel-Signale selbst
            if (! $setId) {
                $covered = array_keys(array_filter($map));
                $report['resolved'] += $this->resolveUncovered($template, $covered, $dryRun);
            }
        }

        return $report;
    }

    /**
     * Eine gewinnende Zuordnung an ihren Knoten auswerten.
     *
     * @param  int[]  $entityIds  Knoten, an denen diese Zuordnung gewinnt
     * @return array{set_id:int, assignment_id:int, template_id:int, template:string, nodes:int, triggered:array, created:int, resolved:int, reopened:int}
     */
    public function evaluateAssignment(SignalSet $set, SignalSetTemplate $assignment, array $entityIds, bool $dryRun = false): array
    {
        $template = $assignment->template;
        $policy = $assignment->policy();
        $conditions = array_diff_key($assignment->effectiveConditions(), array_flip(self::IGNORED_CONDITION_KEYS));
        if ($template->pattern_type === 'no_responsible') {
            $conditions['capability'] ??= $policy['recipient_capability'];
        }
        $triggered = $this->rules->evaluate((string) $template->pattern_type, $conditions, $entityIds);
        $triggeredIds = array_map(fn ($t) => (int) $t['entity_id'], $triggered);

        $resolved = 0;
        $created = 0;
        $reopened = 0;
        $same = fn () => Signal::query()->where('team_id', $set->team_id)->where('signal_template_id', $template->id);

        // Signale, deren Bedingung nicht mehr erfüllt ist, schließen sich selbst (wie bisher).
        $stale = $same()->whereIn('entity_id', $entityIds)->whereIn('status', ['open', 'acknowledged'])
            ->when($triggeredIds !== [], fn ($q) => $q->whereNotIn('entity_id', $triggeredIds))
            ->get();
        foreach ($stale as $signal) {
            $resolved++;
            if (! $dryRun) {
                $this->autoResolve($signal, 'Automatisch aufgelöst: Bedingung nicht mehr erfüllt.');
            }
        }

        foreach ($triggered as $hit) {
            // ein offenes Signal je (Team, Knoten, Template) – egal aus welchem Set
            $open = $same()->where('entity_id', $hit['entity_id'])->whereIn('status', ['open', 'acknowledged'])->first();
            if ($open) {
                if (! $dryRun && (int) $open->signal_set_template_id !== (int) $assignment->id) {
                    $open->forceFill(['signal_set_template_id' => $assignment->id, 'signal_set_id' => $set->id])->save();
                }
                // nachholen: offen, aber nie zugestellt (z. B. vor Einführung der Zustellung entstanden)
                if (! $dryRun && ! $open->delivered_at && $open->recipient_reason === null) {
                    app(SignalDelivery::class)->deliver($open, $hit['recipient_from_entity_id'] ?? null);
                }
                continue;
            }

            // Z4: kam dieselbe Sache im Zeitfenster schon einmal und wurde gelöst → wieder öffnen statt neu anlegen
            $recent = $same()->where('entity_id', $hit['entity_id'])->where('status', 'resolved')
                ->where('resolved_at', '>=', now()->subDays($policy['recurrence_window_days']))
                ->orderByDesc('resolved_at')->first();
            if ($recent) {
                $reopened++;
                if (! $dryRun) {
                    $this->reopen($recent, $set, $assignment, $hit);
                }
                continue;
            }

            $created++;
            if (! $dryRun) {
                $signal = new Signal([
                    'team_id' => $set->team_id,
                    'signal_set_id' => $set->id,
                    'signal_set_template_id' => $assignment->id,
                    'signal_template_id' => $template->id,
                    'source' => 'rule',
                    'source_type' => Signal::SOURCE_TYPE_RULE_CRON,
                    'entity_id' => $hit['entity_id'],
                    'status' => 'open',
                    'severity' => $assignment->effectiveSeverity(),
                    'message' => $hit['message'],
                    'trigger_metrics' => $hit['metrics'],
                    'responsible_user_id' => $set->responsible_agent_user_id,
                ]);
                $signal->deliverFromEntityId = $hit['recipient_from_entity_id'] ?? null;
                $signal->save();
            }
        }

        return [
            'set_id' => $set->id,
            'assignment_id' => $assignment->id,
            'template_id' => $template->id,
            'template' => $template->name,
            'nodes' => count($entityIds),
            'triggered' => $triggered,
            'created' => $created,
            'resolved' => $resolved,
            'reopened' => $reopened,
        ];
    }

    /** Wieder öffnen (Z4): Zähler hoch, Reaktionsuhr neu, Systemkommentar „zum N. Mal in X Tagen“. */
    protected function reopen(Signal $signal, SignalSet $set, SignalSetTemplate $assignment, array $hit): void
    {
        $count = (int) $signal->recurrence_count + 1;
        $days = max(1, (int) round(abs($signal->created_at?->diffInDays(now()) ?? 0)));
        $lastNote = $signal->resolution_note;

        $signal->forceFill([
            'status' => 'open',
            'resolved_at' => null, 'resolved_by_user_id' => null, 'dismissed_reason' => null, 'resolution_note' => null,
            'resolution_link_type' => null, 'resolution_link_id' => null,
            'acknowledged_at' => null, 'reacted_at' => null,
            'delivered_at' => $signal->recipient_reason !== null && $signal->recipient_entity_ids ? now() : $signal->delivered_at,
            'reopened_at' => now(),
            'recurrence_count' => $count,
            'stage' => 'unit', 'outcome' => null,
            'signal_set_id' => $set->id, 'signal_set_template_id' => $assignment->id,
            'severity' => $assignment->effectiveSeverity(),
            'message' => $hit['message'],
            'trigger_metrics' => $hit['metrics'],
        ])->save();

        if (! $signal->recipient_entity_ids) {
            app(SignalDelivery::class)->deliver($signal, $hit['recipient_from_entity_id'] ?? null, true);
        }

        $text = "Wieder aufgetreten – zum {$count}. Mal in " . ($days === 1 ? '1 Tag' : "{$days} Tagen") . '.'
            . ($lastNote ? " Zuletzt gelöst: " . mb_strimwidth($lastNote, 0, 300, '…') : '');
        app(SignalService::class)->comment($signal, $text, null, null, 'system');
        // Z4: derselbe Vorgang wird wieder geöffnet (an den aktuellen Empfänger)
        app(SignalDeliveryQueue::class)->reopened($signal->fresh(), $text);
    }

    protected function autoResolve(Signal $signal, string $note): void
    {
        $signal->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'acknowledged_at' => $signal->acknowledged_at ?? now(),
            'resolution_note' => $signal->resolution_note ?: $note,
        ]);
        app(SignalDeliveryQueue::class)->closed($signal, $note);
    }

    /** Offene Regel-Signale eines Templates an Knoten, an denen keine Zuordnung (mehr) gilt. */
    protected function resolveUncovered(SignalTemplate $template, array $coveredEntityIds, bool $dryRun): int
    {
        $signals = Signal::query()->where('signal_template_id', $template->id)->where('source', 'rule')
            ->whereIn('status', ['open', 'acknowledged'])
            ->when($coveredEntityIds !== [], fn ($q) => $q->whereNotIn('entity_id', $coveredEntityIds))
            ->get();
        if (! $dryRun) {
            foreach ($signals as $signal) {
                $this->autoResolve($signal, 'Automatisch aufgelöst: Die Regel gilt an diesem Knoten nicht mehr (abgewählt oder Set abgehängt).');
            }
        }

        return $signals->count();
    }

    /** Knoten, an denen irgendeine aktive Zuordnung des Templates wirkt (Vereinigung der Set-Geltungsbereiche). @return int[] */
    protected function templateScope(SignalTemplate $template): array
    {
        $sets = SignalSet::query()->where('status', SignalSetStatus::ACTIVE->value)
            ->whereHas('templateAssignments', fn ($q) => $q->where('signal_template_id', $template->id)->where('is_active', true))
            ->get();
        $ids = [];
        foreach ($sets as $set) {
            $ids = array_merge($ids, $this->scopeCache[$set->id] ??= $this->scopeEntityIds($set));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Knoten, an denen ein Set wirkt: die verknüpften Knoten, bei inherit_down plus alle aktiven Unterknoten.
     *
     * @return int[]
     */
    public function scopeEntityIds(SignalSet $set): array
    {
        $ids = [];
        foreach ($this->sets->nodeAssignments($set) as $entityId => $inherit) {
            $ids[] = (int) $entityId;
            if ($inherit) {
                $ids = array_merge($ids, $this->descendantIds((int) $entityId));
            }
        }
        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : OrganizationEntity::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return int[] */
    public function descendantIds(int $rootId): array
    {
        $ids = [];
        $queue = [$rootId];
        for ($depth = 0; $queue !== [] && $depth < 50; $depth++) {
            $children = OrganizationEntity::query()->whereIn('parent_entity_id', $queue)->where('is_active', true)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $children = array_values(array_diff($children, $ids, [$rootId]));
            $ids = array_merge($ids, $children);
            $queue = $children;
        }

        return $ids;
    }

    /**
     * Templates, die an einem Knoten wirken (über direkte und vererbte Sets) – für Brief/Agenten.
     * Z1: je Template nur die gewinnende Zuordnung (Abwahlen und überholte Zuordnungen fallen heraus).
     *
     * @return Collection<int, array{set: SignalSet, assignment: SignalSetTemplate, template: SignalTemplate, source: string}>
     */
    public function templatesForNode(int $entityId, ?string $kind = null): Collection
    {
        $out = collect();
        foreach ($this->sets->setsForNode($entityId) as $row) {
            $set = $row['set'];
            if ($set->status !== SignalSetStatus::ACTIVE) {
                continue;
            }
            foreach ($set->templateAssignments()->with('template')->get() as $a) {
                if (! $a->is_active || ! $a->template || ! $a->template->is_active) {
                    continue;
                }
                if ($kind && $a->template->kind !== $kind) {
                    continue;
                }
                if ($this->assignments->effectiveFor($entityId, (int) $a->signal_template_id)?->id !== $a->id) {
                    continue;
                }
                $out->push(['set' => $set, 'assignment' => $a, 'template' => $a->template, 'source' => $row['source']]);
            }
        }

        return $out;
    }
}
