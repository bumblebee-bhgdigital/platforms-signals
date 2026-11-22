<?php

namespace Platform\Signals\Tools\Concerns;

use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Models\SignalTemplate;

/** Serialisierung der Templates und Signale für die signals.*-Tools. */
trait HandlesSignals
{
    use HandlesSignalSets;

    /** @return array<string,mixed> */
    protected function templateToArray(SignalTemplate $t, bool $withSets = false): array
    {
        $out = [
            'id' => $t->id,
            'uuid' => $t->uuid,
            'team_id' => $t->team_id,
            'kind' => $t->kind,
            'name' => $t->name,
            'description' => $t->description,
            'severity' => $t->severity,
            'is_active' => $t->is_active,
        ];
        if ($t->isRule()) {
            $out += ['pattern_type' => $t->pattern_type, 'conditions' => $t->conditions, 'frequency' => $t->frequency];
        } else {
            $out += [
                'prompt' => $t->prompt, 'vsm_system' => $t->vsm_system,
                'dimension' => $t->dimension, 'schedule_interval_hours' => $t->schedule_interval_hours,
            ];
        }
        if ($withSets) {
            $out['sets'] = $t->assignments()->with('set:id,title,status')->get()
                ->map(fn (SignalSetTemplate $a) => $this->assignmentToArray($a, false))->values()->all();
        }

        return $out;
    }

    /** @return array<string,mixed> */
    protected function assignmentToArray(SignalSetTemplate $a, bool $withTemplate = true): array
    {
        $out = [
            'id' => $a->id,
            'signal_set_id' => $a->signal_set_id,
            'signal_set' => $a->set?->title,
            'signal_template_id' => $a->signal_template_id,
            'is_active' => $a->is_active,
            'position' => $a->position,
            'conditions_override' => $a->conditions_override,
            'severity_override' => $a->severity_override,
            'frequency_override' => $a->frequency_override,
            'schedule_interval_hours_override' => $a->schedule_interval_hours_override,
            'is_binding' => (bool) $a->is_binding,
            'opts_out' => (bool) $a->opts_out,
            'reaction_days' => $a->reaction_days,
            'recurrence_count' => $a->recurrence_count,
            'recurrence_window_days' => $a->recurrence_window_days,
            'persistence_days' => $a->persistence_days,
            'escalation_target' => $a->escalation_target,
            'delivery' => $a->delivery,
            'recipient_capability' => $a->recipient_capability,
            'effective_policy' => $a->policy(),
            'effective' => [
                'severity' => $a->effectiveSeverity(),
                'conditions' => $a->template?->isRule() ? $a->effectiveConditions() : null,
                'frequency' => $a->template?->isRule() ? $a->effectiveFrequency() : null,
                'schedule_interval_hours' => $a->template?->isInference() ? ($a->effectiveIntervalHours() ?: 168) : null,
            ],
            'last_evaluated_at' => $a->last_evaluated_at?->toIso8601String(),
            'last_error' => $a->last_error,
        ];
        if ($withTemplate && $a->template) {
            $out['template'] = ['id' => $a->template->id, 'name' => $a->template->name, 'kind' => $a->template->kind, 'pattern_type' => $a->template->pattern_type, 'vsm_system' => $a->template->vsm_system];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    protected function signalToArray(Signal $s, bool $detail = false): array
    {
        $recipientIds = array_map('intval', (array) $s->recipient_entity_ids);
        $names = $this->entityNames(array_filter(array_merge([$s->entity_id, $s->assignee_entity_id, $s->perspective_entity_id, $s->current_owner_entity_id, $s->created_by_agent_entity_id, $s->unit_entity_id, $s->committee_entity_id, $s->recipient_node_entity_id], $recipientIds)));

        $out = [
            'id' => $s->id,
            'uuid' => $s->uuid,
            'team_id' => $s->team_id,
            'status' => $s->status,
            'severity' => $s->severity,
            'message' => $s->message,
            'entity_id' => $s->entity_id,
            'entity_name' => $names[$s->entity_id] ?? null,
            'source' => $s->source,
            'source_type' => $s->source_type,
            'signal_set_id' => $s->signal_set_id,
            'signal_set' => $s->set?->title,
            'signal_template_id' => $s->signal_template_id,
            'template' => $s->template?->name,
            'template_kind' => $s->template?->kind,
            'unit_entity_id' => $s->unit_entity_id,
            'unit_name' => $names[$s->unit_entity_id] ?? null,
            'stage' => $s->stage,
            'committee_entity_id' => $s->committee_entity_id,
            'committee_name' => $names[$s->committee_entity_id] ?? null,
            'meeting_ref_id' => $s->meeting_ref_id,
            'due_at' => $s->due_at?->toIso8601String(),
            'agenda_ref' => $s->agenda_ref_id ? ['type' => $s->agenda_ref_type, 'id' => $s->agenda_ref_id] : null,
            'frame_ref_id' => $s->frame_ref_id,
            'outcome' => $s->outcome,
            'signal_set_template_id' => $s->signal_set_template_id,
            // Z2: Empfänger = nächster Verantwortlicher (nicht der Agent des Sets – der ist Absender/Begleiter: responsible_*)
            'recipient_node_entity_id' => $s->recipient_node_entity_id,
            'recipient_node_name' => $names[$s->recipient_node_entity_id] ?? null,
            'recipients' => array_map(fn ($id) => ['entity_id' => $id, 'name' => $names[$id] ?? null], $recipientIds),
            'recipient_user_ids' => array_map('intval', (array) $s->recipient_user_ids),
            'recipient_reason' => $s->recipient_reason,
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'reacted_at' => $s->reacted_at?->toIso8601String(),
            'escalation_level' => (int) $s->escalation_level,
            'escalation_reason' => $s->escalation_reason,
            'recurrence_count' => (int) ($s->recurrence_count ?? 1),
            'reopened_at' => $s->reopened_at?->toIso8601String(),
            'responsible_user_id' => $s->responsible_user_id,
            'responsible' => $s->responsible?->name,
            'assignee_entity_id' => $s->assignee_entity_id,
            'assignee_name' => $names[$s->assignee_entity_id] ?? null,
            'vsm_level' => $s->vsm_level,
            'perspective_entity_id' => $s->perspective_entity_id,
            'perspective_entity_name' => $names[$s->perspective_entity_id] ?? null,
            'current_owner_entity_id' => $s->current_owner_entity_id,
            'current_owner_name' => $names[$s->current_owner_entity_id] ?? null,
            'created_by_agent_entity_id' => $s->created_by_agent_entity_id,
            'created_by_agent_name' => $names[$s->created_by_agent_entity_id] ?? null,
            'trigger_metrics' => $s->trigger_metrics,
            'snooze_until' => $s->snooze_until?->toIso8601String(),
            'deadline_at' => $s->deadline_at?->toIso8601String(),
            'escalated_at' => $s->escalated_at?->toIso8601String(),
            'acknowledged_at' => $s->acknowledged_at?->toIso8601String(),
            'aggregated_to_signal_id' => $s->aggregated_to_signal_id,
            'resolved_at' => $s->resolved_at?->toIso8601String(),
            'resolved_by_user_id' => $s->resolved_by_user_id,
            'resolution_note' => $s->resolution_note,
            'resolution_link' => $s->resolution_link_type ? ['type' => $s->resolution_link_type, 'id' => $s->resolution_link_id] : null,
            'dismissed_reason' => $s->dismissed_reason,
            'legacy_organization_signal_id' => $s->legacy_organization_signal_id,
            'created_at' => $s->created_at?->toIso8601String(),
        ];

        if ($detail) {
            $out['affected_entity_ids'] = $s->affected_entity_ids;
            $out['evidence'] = $s->evidence;
            $out['actions'] = $s->actions->map(fn ($a) => [
                'id' => $a->id, 'position' => $a->position, 'title' => $a->title, 'description' => $a->description,
                'status' => $a->status, 'decision_reason' => $a->decision_reason, 'decided_at' => $a->decided_at?->toIso8601String(),
            ])->values()->all();
            $out['comments'] = $s->comments()->with('user:id,name')->orderBy('created_at')->get()->map(fn ($c) => [
                'id' => $c->id, 'parent_id' => $c->parent_id, 'author' => $c->user?->name, 'author_context' => $c->author_context,
                'content' => $c->content, 'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $out;
    }

    /** @return array<int,string> */
    protected function entityNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids === [] ? [] : OrganizationEntity::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    protected function writeMeta(array $tags, bool $readOnly = false): array
    {
        return [
            'category' => $readOnly ? 'query' : 'action',
            'tags' => array_merge(['signals'], $tags),
            'read_only' => $readOnly,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => $readOnly ? 'read' : 'write',
            'idempotent' => $readOnly,
        ];
    }
}
