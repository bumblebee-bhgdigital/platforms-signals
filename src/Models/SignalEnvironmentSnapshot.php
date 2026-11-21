<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\Team;
use Symfony\Component\Uid\UuidV7;

class SignalEnvironmentSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'signal_environment_snapshots';

    protected $fillable = [
        'uuid',
        'team_id',
        'source_id',
        'snapshot_date',
        'metrics',
        'summary',
        'raw_items',
        'created_at',
        'legacy_organization_snapshot_id',
    ];

    protected $casts = [
        'metrics' => 'array',
        'raw_items' => 'array',
        'snapshot_date' => 'date',
        'created_at' => 'datetime',
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

            if (! $model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SignalEnvironmentSource::class, 'source_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
