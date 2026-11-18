<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Core\Contracts\HasDisplayName;
use Platform\Core\Models\Concerns\HasEntityLinks;
use Platform\Signals\Enums\SignalSetStatus;
use Symfony\Component\Uid\UuidV7;

/**
 * @ai.description Signal-Set = Bündel aus Signaldefinitionen und Kennzahlen (Quellen, Schwellwerte, Frequenz, Eskalation). Wird an Knoten (Organisationseinheiten) gehängt; ein Set ist an mehreren Knoten wiederverwendbar, die Vererbung nach unten ist pro Zuordnung wählbar.
 *
 * Morph-Alias: `signal_set` (Relation::morphMap im ServiceProvider). Der Knoten hängt NICHT als
 * Fremdschlüssel am Set, sondern lose per DimensionLink (Entity-Dimension der Organization) —
 * wie PlannerProject, aber mit mehreren Knoten pro Set. Die Vererbungs-Option je Zuordnung liegt
 * in `signal_set_nodes` (SignalSetNode). Verknüpfen/Lösen/Umhängen laufen über SignalSetService.
 *
 * Messlogik (Definitionen, Kennzahlen, ausgelöste Signale) folgt in eigenen Boards.
 *
 * @property-read string $uuid
 */
class SignalSet extends Model implements HasDisplayName
{
    use SoftDeletes, HasEntityLinks;

    /** Morph-Alias für DimensionLinks / EntityLinkProvider. */
    public const MORPH_ALIAS = 'signal_set';

    protected $table = 'signal_sets';

    protected $fillable = [
        'uuid',
        'team_id',
        'title',
        'description',
        'status',
        'owner_user_id',
        'responsible_agent_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'uuid' => 'string',
        'status' => SignalSetStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::withTrashed()->where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
            if (empty($model->status)) {
                $model->status = SignalSetStatus::DRAFT;
            }
        });
    }

    // ── Beziehungen ──────────────────────────────────────────────

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'owner_user_id');
    }

    public function responsibleAgent(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'responsible_agent_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    /** Zuordnungs-Einstellungen je Knoten (Vererbung nach unten). */
    public function nodeSettings(): HasMany
    {
        return $this->hasMany(SignalSetNode::class, 'signal_set_id');
    }

    // ── Anzeige ──────────────────────────────────────────────────

    public function getDisplayName(): ?string
    {
        return $this->title;
    }

    // ── Scopes / Sichtbarkeit ────────────────────────────────────

    public function scopeStatus(Builder $query, SignalSetStatus|string $status): Builder
    {
        return $query->where($query->getModel()->getTable() . '.status', $status instanceof SignalSetStatus ? $status->value : $status);
    }

    /**
     * Sichtbar = Core-Graph (Ersteller ODER über den Org-Baum erreichbar, read) ODER Owner des Sets.
     * Spiegelt SignalSetPolicy::view().
     */
    public function scopeVisibleTo(Builder $query, \Platform\Core\Models\User $user): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($user, $table) {
            $q->authzVisibleTo($user, 'read')
                ->orWhere($table . '.owner_user_id', $user->id);
        });
    }

    /** Alias wie im Planner (Sidebar). */
    public function scopeViewableBy(Builder $query, \Platform\Core\Models\User $user): Builder
    {
        return $this->scopeVisibleTo($query, $user);
    }

    public function isActive(): bool
    {
        return $this->status === SignalSetStatus::ACTIVE;
    }
}
