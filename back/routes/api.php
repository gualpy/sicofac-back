<?php

use App\Http\Controllers\Api\{AuthController, CompanyController, CompanyEmissionPointController, CompanyEstablishmentController, CustomerController, InvoiceController, ProductController};
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
    Route::get('companies/{company}/certificate', [CompanyController::class, 'showCertificate'])
        ->name('companies.certificate.show');
    Route::get('companies/{company}/logo', [CompanyController::class, 'logo'])
        ->name('companies.logo.show');
    Route::post('companies/{company}/logo', [CompanyController::class, 'uploadLogo'])
        ->middleware('throttle:critical')
        ->name('companies.logo.upload');
    Route::delete('companies/{company}/logo', [CompanyController::class, 'deleteLogo'])
        ->middleware('throttle:critical')
        ->name('companies.logo.delete');
    Route::post('companies/{company}/members', [CompanyController::class, 'addMember'])
        ->middleware('throttle:critical')
        ->name('companies.members.add');
    Route::patch('companies/{company}/members/{user}', [CompanyController::class, 'updateMember'])
        ->middleware('throttle:critical')
        ->name('companies.members.update');
    Route::get('companies/{company}/establishments', [CompanyEstablishmentController::class, 'index'])
        ->name('companies.establishments.index');
    Route::post('companies/{company}/establishments/{establishment}/emission-points', [CompanyEmissionPointController::class, 'store'])
        ->middleware('throttle:critical')
        ->name('companies.establishments.emission-points.store');
    Route::patch('companies/{company}/establishments/{establishment}/emission-points/{emissionPoint}', [CompanyEmissionPointController::class, 'update'])
        ->middleware('throttle:critical')
        ->name('companies.establishments.emission-points.update');
    Route::apiResource('companies.customers', CustomerController::class);
    Route::post('companies/{company}/products/import', [ProductController::class, 'import'])
        ->middleware('throttle:critical')
        ->name('companies.products.import');
    Route::get('companies/{company}/products/imports', [ProductController::class, 'imports'])
        ->middleware('throttle:critical')
        ->name('companies.products.imports.index');
    Route::get('companies/{company}/products/imports/{import}', [ProductController::class, 'showImport'])
        ->middleware('throttle:critical')
        ->name('companies.products.imports.show');
    Route::get('companies/{company}/products/import-template', [ProductController::class, 'importTemplate'])
        ->middleware('throttle:critical')
        ->name('companies.products.import-template');
    Route::get('companies/{company}/products/export', [ProductController::class, 'export'])
        ->middleware('throttle:critical')
        ->name('companies.products.export');
    Route::delete('companies/{company}/products', [ProductController::class, 'destroyAll'])
        ->middleware('throttle:critical')
        ->name('companies.products.destroyAll');
    Route::apiResource('companies.products', ProductController::class);
    Route::apiResource('companies.invoices', InvoiceController::class);
    Route::post('companies/{company}/invoices/{invoice}/emit', [InvoiceController::class, 'emit'])
        ->middleware('throttle:critical')
        ->name('companies.invoices.emit');
    Route::get('companies/{company}/invoices/{invoice}/ride', [InvoiceController::class, 'ride'])
        ->name('companies.invoices.ride');
});
