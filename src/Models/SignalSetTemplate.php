<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template in einem Signal-Set. Die Overrides legen sich über das Template (Schwelle, Schwere, Takt),
 * damit dasselbe Template je Set unterschiedlich scharf eingestellt sein kann.
 */
class SignalSetTemplate extends Model
{
    protected $table = 'signal_set_templates';

    protected $fillable = [
        'signal_set_id', 'signal_template_id', 'conditions_override', 'severity_override',
        'frequency_override', 'schedule_interval_hours_override', 'is_active', 'position',
        'last_evaluated_at', 'last_error',
    ];

    protected $casts = [
        'conditions_override' => 'array',
        'is_active' => 'boolean',
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
