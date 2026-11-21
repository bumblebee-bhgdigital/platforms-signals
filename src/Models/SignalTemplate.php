<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * @ai.description Signal-Template = wiederverwendbare Signaldefinition. kind=rule: Muster (threshold, trend, ratio, cross_dimension) mit conditions auf Kennzahlen der Knoten (Snapshots der Organisation). kind=inference: Prompt + VSM-System + Kontextumfang, ausgewertet vom verantwortlichen Agenten des Sets per MCP. Ein Template wirkt erst, wenn es in einem aktiven Signal-Set liegt, das an einem Knoten hängt.
 */
class SignalTemplate extends Model
{
    use SoftDeletes;

    public const KIND_RULE = 'rule';
    public const KIND_INFERENCE = 'inference';
    public const KINDS = [self::KIND_RULE, self::KIND_INFERENCE];

    public const PATTERN_TYPES = ['threshold', 'trend', 'ratio', 'cross_dimension'];
    public const SEVERITIES = ['info', 'warning', 'critical', 'algedonic'];
    public const FREQUENCIES = ['every_snapshot', 'daily', 'weekly'];
    public const VSM_SYSTEMS = ['s1', 's2', 's3', 's3_star', 's4', 's5'];

    protected $table = 'signal_templates';

    protected $fillable = [
        'uuid', 'team_id', 'kind', 'name', 'description', 'severity', 'is_active',
        'pattern_type', 'conditions', 'frequency',
        'prompt', 'vsm_system', 'dimension', 'schedule_interval_hours',
        'created_by_user_id',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_active' => 'boolean',
        'schedule_interval_hours' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function sets(): BelongsToMany
    {
        return $this->belongsToMany(SignalSet::class, 'signal_set_templates', 'signal_template_id', 'signal_set_id')
            ->withPivot(['id', 'conditions_override', 'severity_override', 'frequency_override', 'schedule_interval_hours_override', 'is_active', 'position', 'last_evaluated_at'])
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SignalSetTemplate::class, 'signal_template_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'signal_template_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable() . '.is_active', true);
    }

    public function isRule(): bool
    {
        return $this->kind === self::KIND_RULE;
    }

    public function isInference(): bool
    {
        return $this->kind === self::KIND_INFERENCE;
    }
}
