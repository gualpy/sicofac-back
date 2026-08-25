<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEmissionPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_establishment_id',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(CompanyEstablishment::class, 'company_establishment_id');
    }
}
