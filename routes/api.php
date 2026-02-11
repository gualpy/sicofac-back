<?php

use App\Http\Controllers\Api\{AuthController, CompanyController, CustomerController, InvoiceController, ProductController};
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login')->name('auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::middleware('auth:sanctum')->scopeBindings()->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::delete('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::apiResource('companies', CompanyController::class)->middleware('throttle:critical');
    Route::patch('companies/{company}/issuer-config', [CompanyController::class, 'updateIssuerConfig'])
        ->middleware('throttle:critical')
        ->name('companies.issuer-config.update');
    Route::patch('companies/{company}/environment', [CompanyController::class, 'changeEnvironment'])
        ->middleware('throttle:critical')
        ->name('companies.environment.change');
    Route::post('companies/{company}/certificate', [CompanyController::class, 'uploadCertificate'])
        ->middleware('throttle:critical')
        ->name('companies.certificate.upload');
    Route::post('companies/{company}/certificate/{version}/activate', [CompanyController::class, 'activateCertificate'])
        ->middleware('throttle:critical')
        ->name('companies.certificate.activate');
    Route::delete('companies/{company}/certificate', [CompanyController::class, 'deleteCertificate'])
        ->middleware('throttle:critical')
        ->name('companies.certificate.delete');
    Route::post('companies/{company}/members', [CompanyController::class, 'addMember'])
        ->middleware('throttle:critical')
        ->name('companies.members.add');
    Route::patch('companies/{company}/members/{user}', [CompanyController::class, 'updateMember'])
        ->middleware('throttle:critical')
        ->name('companies.members.update');
    Route::apiResource('companies.customers', CustomerController::class);
    Route::apiResource('companies.products', ProductController::class);
    Route::apiResource('companies.invoices', InvoiceController::class);
    Route::post('companies/{company}/invoices/{invoice}/emit', [InvoiceController::class, 'emit'])
        ->middleware('throttle:critical')
        ->name('companies.invoices.emit');
});
