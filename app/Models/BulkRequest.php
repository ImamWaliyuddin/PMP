<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkRequest extends Model
{
    protected $guarded = [];

    protected $casts = ['mitra' => 'array'];

    public function items(): HasMany
    {
        return $this->hasMany(BulkItem::class);
    }
}
