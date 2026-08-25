<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'SICOFAC API',
    description: 'API de facturacion electronica (SRI Ecuador) multi-tenant.'
)]
#[OA\Server(url: '/api', description: 'API base path')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum token',
    description: 'Token obtenido en /auth/login o /auth/register, enviado como "Authorization: Bearer {token}".'
)]
abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
