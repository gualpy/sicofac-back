<?php

namespace App\Integrations\Sri\Enums;

/**
 * The SRI's autorizacion webservice can report three meaningfully different
 * outcomes for a comprobante (Ficha Tecnica de Comprobantes Electronicos):
 * AUTORIZADO (final acceptance), NO AUTORIZADO (final rejection), and "still
 * being processed" -- surfaced either as an empty <autorizaciones/> list
 * (nothing to report yet) or an explicit "EN PROCESAMIENTO"/"PPR" estado.
 * Collapsing that last case into a rejection (the previous behaviour) would
 * permanently mark a comprobante the SRI simply hasn't finished validating
 * as if the SRI had explicitly refused it.
 *
 * `Unknown` covers any estado string not recognised as belonging to one of
 * the families above. It is handled exactly like `Pending` (bounded retry,
 * never silently treated as a confirmed rejection) -- SICOFAC has no basis
 * to assume the SRI rejected something it doesn't recognise the response
 * for.
 */
enum SriAuthorizationStatus: string
{
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Pending = 'pending';
    case Unknown = 'unknown';
}
