<?php

namespace App\Integrations\Sri\Contracts;

use App\Models\CompanyCertificate;

interface XadesSignerInterface
{
    public function sign(string $xml, CompanyCertificate $certificate): string;
}
