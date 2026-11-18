<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property CreditEntryKind $kind
 * @property numeric-string $amount
 * @property numeric-string $available_delta
 * @property numeric-string $reserved_delta
 * @property numeric-string $outstanding_delta
 */
class CreditEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => CreditEntryKind::class, 'amount' => 'decimal:18', 'available_delta' => 'decimal:18', 'reserved_delta' => 'decimal:18', 'outstanding_delta' => 'decimal:18', 'created_at' => 'immutable_datetime'];
    }
}
