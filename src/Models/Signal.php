<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Organization\Models\OrganizationEntity;
use Symfony\Component\Uid\UuidV7;

/**
 * @ai.description Ausgelöstes Signal (algedonischer Alarm) an einem Knoten. Entsteht aus einem Template in einem Signal-Set (Regel-Auswertung oder Agent), aus dem Algedonic-Knopf oder per Eskalation/Aggregation. Lebenszyklus: open → acknowledged → resolved | dismissed; Auflösung mit Notiz und Verweis (z. B. Beschluss, Aufgabe, Agendapunkt).
 */
class Signal extends Model
{
    use SoftDeletes;

    public const MORPH_ALIAS = 'signals_signal';

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'dismissed'];
    public const SEVERITIES = ['info', 'warning', 'critical', 'algedonic'];

    public const SOURCE_TYPE_RULE_CRON = 'rule_cron';
    public const SOURCE_TYPE_INFERENCE = 'inference';
    public const SOURCE_TYPE_HUMAN_ALGEDONIC = 'human_algedonic';
    public const SOURCE_TYPE_AGGREGATION = 'aggregation';
    public const SOURCE_TYPE_MANUAL = 'manual';

    public const VSM_LEVELS = ['s1', 's2', 's3', 's3_star', 's4', 's5'];

    protected $table = 'signals';

    protected $fillable = [
        'uuid', 'team_id', 'signal_set_id', 'signal_template_id', 'source', 'source_type',
        'entity_id', 'affected_entity_ids', 'status', 'severity', 'message', 'trigger_metrics', 'evidence',
        'responsible_user_id', 'assignee_entity_id', 'perspective_entity_id', 'current_owner_entity_id',
        'created_by_agent_entity_id', 'created_by_user_id', 'vsm_level',
        'deadline_at', 'escalated_at', 'acknowledged_at', 'snooze_until', 'aggregated_at', 'aggregated_to_signal_id',
        'resolved_at', 'resolved_by_user_id', 'dismissed_reason', 'resolution_note', 'resolution_link_type', 'resolution_link_id',
        'legacy_organization_signal_id', 'created_at',
        'unit_entity_id', 'stage', 'committee_entity_id', 'meeting_ref_id', 'due_at', 'agenda_ref_type', 'agenda_ref_id',
        'frame_ref_id', 'outcome',
    ];

    protected $casts = [
        'affected_entity_ids' => 'array',
        'trigger_metrics' => 'array',
        'evidence' => 'array',
        'deadline_at' => 'datetime',
        'escalated_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'snooze_until' => 'datetime',
        'aggregated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
        // Lokal vor übergreifend: jedes neue Signal gehört zuerst seiner verantwortlichen Einheit
        static::created(function (self $model) {
            try {
                app(\Platform\Signals\Services\CircleService::class)->deliver($model);
            } catch (\Throwable) {
                // Zustellung darf das Anlegen nie verhindern
            }
        });
    }

    // ── Beziehungen ──────────────────────────────────────────────

    public function set(): BelongsTo
    {
        return $this->belongsTo(SignalSet::class, 'signal_set_id')->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SignalTemplate::class, 'signal_template_id')->withTrashed();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'entity_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'unit_entity_id');
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'committee_entity_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'assignee_entity_id');
    }

    public function perspectiveEntity(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'perspective_entity_id');
    }

    public function currentOwner(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'current_owner_entity_id');
    }

    public function createdByAgent(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'created_by_agent_entity_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'responsible_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'resolved_by_user_id');
    }

    public function aggregatedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'aggregated_to_signal_id');
    }

    /** Verweis, durch den das Signal aufgelöst wurde (Beschluss, Aufgabe, Agendapunkt …). */
    public function resolutionLink(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'resolution_link_type', 'resolution_link_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SignalComment::class, 'signal_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SignalAction::class, 'signal_id')->orderBy('position');
    }

    public function focuses(): HasMany
    {
        return $this->hasMany(SignalFocus::class, 'signal_id');
    }

    public function focusedBy(): BelongsToMany
    {
        return $this->belongsToMany(\Platform\Core\Models\User::class, 'signal_focuses', 'signal_id', 'user_id')
            ->withTimestamps()->withPivot('focused_at');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeForTeam(Builder $q, int|array $teamIds): Builder
    {
        return $q->whereIn('team_id', (array) $teamIds);
    }

    public function scopeAlgedonic(Builder $q): Builder
    {
        return $q->where('severity', 'algedonic');
    }

    public function scopeActionable(Builder $q): Builder
    {
        return $q->whereIn('status', ['open', 'acknowledged'])
            ->where(fn ($w) => $w->whereNull('snooze_until')->orWhere('snooze_until', '<=', now()));
    }

    public function scopeSnoozed(Builder $q): Builder
    {
        return $q->whereNotNull('snooze_until')->where('snooze_until', '>', now());
    }

    public function scopeDueForEscalation(Builder $q): Builder
    {
        return $q->whereIn('status', ['open', 'acknowledged'])
            ->whereNull('acknowledged_at')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now());
    }

    public function scopeFocusedBy(Builder $q, int $userId): Builder
    {
        return $q->whereHas('focuses', fn ($f) => $f->where('user_id', $userId));
    }

    public function isFocusedBy(?\Platform\Core\Models\User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->relationLoaded('focuses')
            ? $this->focuses->contains('user_id', $user->id)
            : $this->focuses()->where('user_id', $user->id)->exists();
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['resolved', 'dismissed'], true);
    }

    /**
     * Status aus den Entscheidungen zu den Maßnahmen ableiten (wie bisher in der Organisation).
     * null, wenn keine Maßnahmen oder noch welche offen sind.
     */
    public function deriveStatusFromActions(): ?string
    {
        $actions = $this->actions;
        if ($actions->isEmpty() || $actions->contains(fn ($a) => $a->status === 'pending')) {
            return null;
        }
        $applied = $actions->where('status', 'applied')->count();
        $dismissed = $actions->where('status', 'dismissed')->count();

        return match (true) {
            $applied > 0 && $dismissed === 0 => 'resolved',
            $dismissed > 0 && $applied === 0 => 'dismissed',
            default => 'acknowledged',
        };
    }
}
