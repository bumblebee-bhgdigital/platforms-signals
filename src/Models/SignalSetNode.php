<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zuordnung Signal-Set <-> Knoten (Einstellungen). Die Verknüpfung selbst ist ein DimensionLink;
 * diese Zeile trägt nur die Option "nach unten vererben" je Zuordnung.
 *
 * @property int $signal_set_id
 * @property int $entity_id
 * @property bool $inherit_down
 */
class SignalSetNode extends Model
{
    protected $table = 'signal_set_nodes';

    protected $fillable = ['signal_set_id', 'entity_id', 'inherit_down'];

    protected $casts = [
        'signal_set_id' => 'integer',
        'entity_id' => 'integer',
        'inherit_down' => 'boolean',
    ];

    public function signalSet(): BelongsTo
    {
        return $this->belongsTo(SignalSet::class, 'signal_set_id');
    }
}
