<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template in einem Signal-Set (eine „Zuordnung“). Die Overrides legen sich über das Template (Schwelle, Schwere, Takt),
 * damit dasselbe Template je Set unterschiedlich scharf eingestellt sein kann.
 *
 * Z1 „das nächste gewinnt“: pro Knoten und Template gilt die nächste Zuordnung im Baum (EffectiveAssignmentService).
 * is_binding = verbindlich (unten anpassbar, nicht abwählbar), opts_out = Abwahl weiter unten.
 * Die Politik (Reaktionszeit, wiederkehrend, dauerhaft, Eskalationsziel, Zustellung, Empfänger-Fähigkeit) trägt
 * die Zuordnung; null = Standard aus config signals.delivery (policy()).
 */
class SignalSetTemplate extends Model
{
    protected $table = 'signal_set_templates';

    public const ESCALATION_TARGETS = ['none', 'line', 'committee'];
    public const DELIVERIES = ['overview', 'task'];
    public const POLICY_FIELDS = ['reaction_days', 'recurrence_count', 'recurrence_window_days', 'persistence_days', 'escalation_target', 'delivery', 'recipient_capability'];

    protected $fillable = [
        'signal_set_id', 'signal_template_id', 'conditions_override', 'severity_override',
        'frequency_override', 'schedule_interval_hours_override', 'is_active', 'position',
        'last_evaluated_at', 'last_error',
        'is_binding', 'opts_out', 'reaction_days', 'recurrence_count', 'recurrence_window_days', 'persistence_days',
        'escalation_target', 'delivery', 'recipient_capability',
    ];

    protected $casts = [
        'conditions_override' => 'array',
        'is_active' => 'boolean',
        'is_binding' => 'boolean',
        'opts_out' => 'boolean',
        'reaction_days' => 'integer',
        'recurrence_count' => 'integer',
        'recurrence_window_days' => 'integer',
        'persistence_days' => 'integer',
        'position' => 'integer',
        'schedule_interval_hours_override' => 'integer',
        'last_evaluated_at' => 'datetime',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(SignalSet::class, 'signal_set_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SignalTemplate::class, 'signal_template_id');
    }

    /** Wirksame Bedingungen = Template-Bedingungen, überlagert vom Override des Sets. */
    public function effectiveConditions(): array
    {
        return array_replace((array) ($this->template?->conditions ?? []), (array) ($this->conditions_override ?? []));
    }

    public function effectiveSeverity(): string
    {
        return $this->severity_override ?: ($this->template?->severity ?: 'warning');
    }

    /**
     * Wirksame Politik der Zuordnung (Z3/Z4), Lücken aus config signals.delivery.
     * Ohne Zuordnung (z. B. manuelles Signal) gelten die Standards.
     *
     * @return array{reaction_days:int, recurrence_count:int, recurrence_window_days:int, persistence_days:int,
     *               escalation_target:string, delivery:string, recipient_capability:string}
     */
    public static function policyFor(?self $assignment, ?string $severity = null): array
    {
        $d = (array) config('signals.delivery', []);
        $severity ??= $assignment?->effectiveSeverity();
        $pick = fn (string $key, $fallback) => $assignment && $assignment->{$key} !== null && $assignment->{$key} !== '' ? $assignment->{$key} : $fallback;
        $taskByDefault = in_array($severity, (array) ($d['task_severities'] ?? ['critical', 'algedonic']), true)
            || $assignment?->template?->pattern_type === 'no_responsible';

        return [
            'reaction_days' => max(1, (int) $pick('reaction_days', $d['reaction_days'] ?? 3)),
            'recurrence_count' => max(2, (int) $pick('recurrence_count', $d['recurrence_count'] ?? 3)),
            'recurrence_window_days' => max(1, (int) $pick('recurrence_window_days', $d['recurrence_window_days'] ?? 30)),
            'persistence_days' => max(1, (int) $pick('persistence_days', $d['persistence_days'] ?? 14)),
            'escalation_target' => (string) $pick('escalation_target', $d['escalation_target'] ?? 'line'),
            'delivery' => (string) $pick('delivery', $taskByDefault ? 'task' : ($d['delivery'] ?? 'overview')),
            'recipient_capability' => (string) $pick('recipient_capability', $d['recipient_capability'] ?? 'manage'),
        ];
    }

    public function policy(): array
    {
        return self::policyFor($this);
    }

    public function effectiveFrequency(): string
    {
        return $this->frequency_override ?: ($this->template?->frequency ?: 'every_snapshot');
    }

    public function effectiveIntervalHours(): ?int
    {
        return $this->schedule_interval_hours_override ?: $this->template?->schedule_interval_hours;
    }

    /** Ist die Zuordnung laut Takt jetzt wieder dran? */
    public function isDue(?\DateTimeInterface $now = null): bool
    {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();
        $last = $this->last_evaluated_at;
        if (! $last) {
            return true;
        }
        if ($this->template?->isInference()) {
            $hours = $this->effectiveIntervalHours() ?: 168;

            return $last->copy()->addHours($hours)->lte($now);
        }

        return match ($this->effectiveFrequency()) {
            'daily' => ! $last->isSameDay($now),
            'weekly' => $last->copy()->addDays(7)->startOfDay()->lte($now),
            default => true,
        };
    }
}
