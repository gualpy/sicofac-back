<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImport extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'valid_count',
        'invalid_count',
        'errors_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_count' => 'int',
            'invalid_count' => 'int',
            'errors_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
