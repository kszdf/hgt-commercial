<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'tenant_id', 'plan', 'amount', 'currency', 'gateway',
        'gateway_order_no', 'status', 'paid_at', 'raw',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'raw' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function markPaid(array $raw = []): void
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
            'raw' => $raw,
        ]);
    }
}
