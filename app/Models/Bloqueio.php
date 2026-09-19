<?php

namespace App\Models;

use Database\Factories\BloqueioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bloqueador_id', 'bloqueado_id'])]
class Bloqueio extends Model
{
    /** @use HasFactory<BloqueioFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function bloqueador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bloqueador_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bloqueado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bloqueado_id');
    }
}
