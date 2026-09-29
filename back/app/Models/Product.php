<?php

namespace App\Models;

use App\Enums\TaxCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'auxiliary_code',
        'name',
        'unit_price',
        'tax_rate',
        'tax_code',
        'ice_rate',
        'ice_code',
        'is_active',
        'pos_enabled',
        'pos_category_id',
        'pos_label',
        'barcode',
        'pos_sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_code' => TaxCode::class,
            'ice_rate' => 'decimal:2',
            'is_active' => 'bool',
            'pos_enabled' => 'bool',
            'pos_sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function posCategory(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'pos_category_id');
    }
}
