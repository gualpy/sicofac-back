<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case XmlBuilt = 'xml_built';
    case Signed = 'signed';
    case SentReception = 'sent_reception';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
