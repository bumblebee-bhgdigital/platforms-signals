<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Organization\Models\OrganizationEntity;
use Symfony\Component\Uid\UuidV7;

/**
 * @ai.description Gedächtnis der Wahrnehmung: Rückmeldungen zu Signalen (baseline = bestätigt/gelöst, suppression = verworfen mit Grund) und Beobachtungen der Agenten (entity_profile, relationship, prompt_experience …). Wird bei der Auswertung der KI-Templates als Kontext mitgegeben, damit Agenten nicht dieselben Fehlalarme wiederholen.
 */
class SignalMemoryEntry extends Model
{
    use SoftDeletes;

    public const TYPES = ['baseline', 'suppression', 'entity_profile', 'relationship', 'prompt_experience', 'inquiry_outcome', 'source_relevance', 'observation'];

    protected $table = 'signal_memory_entries';

    protected $fillable = [
        'uuid', 'team_id', 'entity_id', 'signal_template_id', 'signal_id', 'memory_type', 'content', 'structured_data',
        'confidence', 'source_type', 'created_by_user_id', 'valid_until', 'is_active', 'reinforcement_count',
        'legacy_organization_memory_id', 'created_at',
    ];

    protected $casts = [
        'structured_data' => 'array',
        'confidence' => 'float',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'reinforcement_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->uuid)) {
                $m->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganizationEntity::class, 'entity_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SignalTemplate::class, 'signal_template_id')->withTrashed();
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'signal_id')->withTrashed();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeValid(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }

    public function scopeForTeam(Builder $q, int $teamId): Builder
    {
        return $q->where('team_id', $teamId);
    }

    public function scopeForEntity(Builder $q, int $entityId): Builder
    {
        return $q->where('entity_id', $entityId);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('memory_type', $type);
    }

    /** Bestätigen: Vertrauen erhöhen, Gültigkeit verlängern. */
    public function reinforce(float $confidenceBoost = 0.1, ?int $extendDays = null): void
    {
        $this->reinforcement_count++;
        $this->confidence = min(1.0, $this->confidence + $confidenceBoost);
        if ($extendDays && $this->valid_until) {
            $this->valid_until = $this->valid_until->addDays($extendDays);
        }
        $this->save();
    }
}
