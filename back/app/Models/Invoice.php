<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'document_code',
        'establishment_code',
        'emission_point',
        'guide_number',
        'is_negotiable',
        'sequential',
        'issue_date',
        'status',
        'currency',
        'subtotal',
        'discount',
        'subtotal_15',
        'subtotal_5',
        'subtotal_special',
        'subtotal_zero',
        'subtotal_not_subject',
        'subtotal_exempt',
        'tax',
        'tax_15',
        'tax_5',
        'tax_special',
        'ice_total',
        'has_tip',
        'tip_amount',
        'total',
        'access_key',
        'sri_authorization_number',
        'sri_response_payload',
        'xml_generated_at',
        'signed_at',
        'sent_at',
        'authorized_at',
        'issue_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'status' => InvoiceStatus::class,
            'is_negotiable' => 'bool',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal_15' => 'decimal:2',
            'subtotal_5' => 'decimal:2',
            'subtotal_special' => 'decimal:2',
            'subtotal_zero' => 'decimal:2',
            'subtotal_not_subject' => 'decimal:2',
            'subtotal_exempt' => 'decimal:2',
            'tax' => 'decimal:2',
            'tax_15' => 'decimal:2',
            'tax_5' => 'decimal:2',
            'tax_special' => 'decimal:2',
            'ice_total' => 'decimal:2',
            'has_tip' => 'bool',
            'tip_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'sri_response_payload' => 'array',
            'xml_generated_at' => 'datetime',
            'signed_at' => 'datetime',
            'sent_at' => 'datetime',
            'authorized_at' => 'datetime',
            'issue_requested_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(InvoiceDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(InvoicePaymentMethod::class);
    }

    public function additionalFields(): HasMany
    {
        return $this->hasMany(InvoiceAdditionalField::class);
    }
}
