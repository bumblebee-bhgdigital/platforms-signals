<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Vorgeschlagene Maßnahme an einem Signal (pending → applied | dismissed). */
class SignalAction extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'applied', 'dismissed'];

    protected $table = 'signal_actions';

    protected $fillable = ['signal_id', 'position', 'title', 'description', 'status', 'decision_reason', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime', 'position' => 'integer'];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'signal_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'decided_by');
    }
}
