<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DocumentSequence;
use Illuminate\Http\JsonResponse;

class CompanyEstablishmentController extends Controller
{
    /**
     * Document codes shown in the "Puntos de emision" sequential table.
     */
    private const DOCUMENT_CODES = [
        '01' => 'factura',
        '04' => 'nota_credito',
        '05' => 'nota_debito',
        '07' => 'comprobante_retencion',
        '03' => 'liquidacion_compra',
        '06' => 'guia_remision',
    ];

    public function index(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $establishments = $company->establishments()
            ->with(['emissionPoints' => fn ($query) => $query->where('is_active', true)])
            ->where('is_active', true)
            ->get();

        $sequences = DocumentSequence::query()
            ->where('company_id', $company->id)
            ->get()
            ->groupBy(fn ($row) => "{$row->establishment_code}|{$row->emission_point}");

        $establishments->each(function ($establishment) use ($sequences) {
            $establishment->emissionPoints->each(function ($point) use ($establishment, $sequences) {
                $rows = $sequences->get("{$establishment->code}|{$point->code}", collect());
                $point->sequences = collect(self::DOCUMENT_CODES)
                    ->mapWithKeys(function ($label, $code) use ($rows) {
                        $row = $rows->firstWhere('document_code', $code);
                        return [$code => $row ? (int) $row->next_number : 1];
                    });
            });
        });

        return response()->json($establishments);
    }
}
