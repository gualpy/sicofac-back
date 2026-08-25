<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'xml_generated_path',
        'xml_signed_path',
        'xml_authorized_path',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
