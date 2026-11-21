<?php

namespace Platform\Signals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalComment extends Model
{
    protected $table = 'signal_comments';

    protected $fillable = ['signal_id', 'parent_id', 'user_id', 'author_context', 'content', 'created_at'];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'signal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }
}
