<?php

namespace App\Enums;

enum PaymentTermUnit: string
{
    case Days = 'dias';
    case Months = 'meses';
    case Years = 'anios';
}
