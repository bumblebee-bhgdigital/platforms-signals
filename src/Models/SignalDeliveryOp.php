<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auftrag an den Agenten des Sets, einen Schritt im Parlan-Vorgang eines Signals auszuführen (Z4).
 * Signals kann in Parlan nicht selbst handeln (dort zählt nur, was die handelnde Identität signiert) –
 * der Agent holt die Aufträge per signals.deliveries.GET, führt sie mit seinem Parlan-Zugang aus und meldet
 * per signals.deliveries.POST zurück. Je Signal der Reihe nach (id aufsteigend).
 */
class SignalDeliveryOp extends Model
{
    protected $table = 'signal_delivery_ops';

    public const OPS = ['create', 'handover', 'comment', 'reopen', 'close'];
    public const STATUSES = ['pending', 'done', 'failed', 'cancelled'];
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['signal_id', 'op', 'payload', 'status', 'attempts', 'last_error', 'done_at'];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'done_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'signal_id');
    }
}
