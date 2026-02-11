<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsToMany, HasMany };

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'trade_name',
        'ruc',
        'establishment_code',
        'emission_point',
        'certificate_path',
        'certificate_name',
        'certificate_uploaded_at',
        'environment',
        'sri_signing_enabled',
        'sri_submission_enabled',
    ];

    protected function casts(): array
    {
        return [
            'sri_signing_enabled' => 'bool',
            'sri_submission_enabled' => 'bool',
            'certificate_uploaded_at' => 'datetime',
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
}
