<?php

namespace App\Integrations\Sri\Contracts;

use App\Models\Invoice;

interface XmlBuilderInterface
{
    public function build(Invoice $invoice): string;
}
