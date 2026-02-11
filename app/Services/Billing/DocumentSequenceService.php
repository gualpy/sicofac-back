<?php

namespace App\Services\Billing;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentSequenceService
{
    public function next(
        int $companyId,
        string $documentCode = '01',
        string $establishmentCode = '001',
        string $emissionPoint = '001',
    ): int
    {
        return DB::transaction(function () use ($companyId, $documentCode, $establishmentCode, $emissionPoint) {
            $sequence = DocumentSequence::query()
                ->where('company_id', $companyId)
                ->where('document_code', $documentCode)
                ->where('establishment_code', $establishmentCode)
                ->where('emission_point', $emissionPoint)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = DocumentSequence::query()->create([
                    'company_id' => $companyId,
                    'document_code' => $documentCode,
                    'establishment_code' => $establishmentCode,
                    'emission_point' => $emissionPoint,
                    'next_number' => 2,
                ]);

                return 1;
            }

            $current = (int) $sequence->next_number;
            $sequence->update(['next_number' => $current + 1]);

            return $current;
        });
    }
}
