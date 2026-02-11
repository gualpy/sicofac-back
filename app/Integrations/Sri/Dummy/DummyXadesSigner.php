<?php

namespace App\Integrations\Sri\Dummy;

use App\Integrations\Sri\Contracts\XadesSignerInterface;
use App\Models\CompanyCertificate;

class DummyXadesSigner implements XadesSignerInterface
{
    public function sign(string $xml, CompanyCertificate $certificate): string
    {
        return $xml;
    }
}
