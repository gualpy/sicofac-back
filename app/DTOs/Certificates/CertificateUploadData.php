<?php

namespace App\DTOs\Certificates;

use Illuminate\Http\UploadedFile;

final readonly class CertificateUploadData
{
    public function __construct(
        public int $companyId,
        public UploadedFile $file,
        public string $password,
    ) {
    }
}

