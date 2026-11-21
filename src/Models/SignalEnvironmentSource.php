<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Symfony\Component\Uid\UuidV7;

class SignalEnvironmentSource extends Model
{
    use SoftDeletes;

    protected $table = 'signal_environment_sources';

    protected $fillable = [
        'uuid',
        'team_id',
        'user_id',
        'name',
        'source_type',
        'category',
        'cluster',
        'config',
        'pull_interval_hours',
        'is_active',
        'last_pulled_at',
        'legacy_organization_source_id',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'pull_interval_hours' => 'integer',
        'last_pulled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());

                $model->uuid = $uuid;
            }

            if (! $model->user_id) {
                $model->user_id = Auth::id();
            }

            if (! $model->team_id) {
                $model->team_id = Auth::user()?->currentTeamRelation?->id;
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SignalEnvironmentSnapshot::class, 'source_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeInCluster($query, string $cluster)
    {
        return $query->where('cluster', $cluster);
    }

    /** Fällig = nie abgerufen oder Intervall abgelaufen (datenbankneutral, wenige Quellen je Team). */
    public function scopeDue($query)
    {
        $dueIds = static::query()->where('is_active', true)->get(['id', 'last_pulled_at', 'pull_interval_hours'])
            ->filter(fn ($s) => ! $s->last_pulled_at || $s->last_pulled_at->copy()->addHours(max(1, (int) $s->pull_interval_hours))->lte(now()))
            ->pluck('id')->all();

        return $query->whereIn($this->getTable() . '.id', $dueIds ?: [0]);
    }

}
