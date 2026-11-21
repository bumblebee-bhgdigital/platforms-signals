<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ein User hat ein Signal in seinen Fokus genommen. */
class SignalFocus extends Model
{
    protected $table = 'signal_focuses';

    protected $fillable = ['signal_id', 'user_id', 'focused_at'];

    protected $casts = ['focused_at' => 'datetime'];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'signal_id');
    }
}
