<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Company extends Model
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'trade_name',
        'address',
        'phone',
        'email',
        'logo_path',
        'is_rimpe',
        'is_special_taxpayer',
        'is_popular_business',
        'requires_accounting',
        'ruc',
        'establishment_code',
        'emission_point',
        'certificate_path',
        'certificate_name',
        'certificate_uploaded_at',
        'environment',
        'sri_signing_enabled',
        'sri_submission_enabled',
        'plan_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sri_signing_enabled' => 'bool',
            'sri_submission_enabled' => 'bool',
            'certificate_uploaded_at' => 'datetime',
            'is_rimpe' => 'bool',
            'is_special_taxpayer' => 'bool',
            'is_popular_business' => 'bool',
            'requires_accounting' => 'bool',
            'is_active' => 'bool',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(CompanyCertificate::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ProductImport::class);
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(CompanyEstablishment::class);
    }

    public function posCategories(): HasMany
    {
        return $this->hasMany(PosCategory::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
