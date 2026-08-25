<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentTermUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'method',
        'value',
        'term_value',
        'term_unit',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'value' => 'decimal:2',
            'term_value' => 'int',
            'term_unit' => PaymentTermUnit::class,
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
