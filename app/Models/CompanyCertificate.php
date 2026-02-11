<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'version',
        'scope_type',
        'scope_id',
        'path',
        'status',
        'is_active',
        'password_encrypted',
        'uploaded_at',
        'archived_at',
        'expires_at',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'uploaded_at' => 'datetime',
            'archived_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
