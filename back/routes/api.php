<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Api\Admin\MetricsController as AdminMetricsController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyEmissionPointController;
use App\Http\Controllers\Api\CompanyEstablishmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceIssueController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PosCategoryController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login')->name('auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
Route::get('auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:login'])
    ->name('verification.verify');
Route::post('auth/email/resend', [AuthController::class, 'resendVerification'])
    ->middleware('throttle:login')
    ->name('verification.resend');
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:login')
    ->name('password.email');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:login')
    ->name('password.update');
Route::get('auth/invitations/{token}', [AuthController::class, 'showInvitation'])
    ->middleware('throttle:login')
    ->name('invitations.show');
Route::post('auth/invitations/{token}/accept', [AuthController::class, 'acceptInvitation'])
    ->middleware('throttle:login')
    ->name('invitations.accept');

Route::post('auth/admin/magic-link', [AdminAuthController::class, 'requestMagicLink'])
    ->middleware('throttle:login')
    ->name('admin.magic-link.request');
Route::get('auth/admin/magic-login/{id}/{hash}', [AdminAuthController::class, 'magicLogin'])
    ->middleware(['signed', 'throttle:login'])
    ->name('admin.magic-login');

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
    Route::get('companies/{company}/members', [CompanyController::class, 'listMembers'])
        ->name('companies.members.index');
    Route::post('companies/{company}/invitations', [CompanyController::class, 'inviteMember'])
        ->middleware('throttle:critical')
        ->name('companies.invitations.store');
    Route::delete('companies/{company}/invitations/{invitation}', [CompanyController::class, 'revokeInvitation'])
        ->middleware('throttle:critical')
        ->name('companies.invitations.destroy');
    Route::get('companies/{company}/api-keys', [CompanyController::class, 'listApiKeys'])
        ->name('companies.api-keys.index');
    Route::post('companies/{company}/api-keys', [CompanyController::class, 'createApiKey'])
        ->middleware('throttle:critical')
        ->name('companies.api-keys.store');
    Route::delete('companies/{company}/api-keys/{tokenId}', [CompanyController::class, 'revokeApiKey'])
        ->middleware('throttle:critical')
        ->name('companies.api-keys.destroy');
    Route::get('companies/{company}/establishments', [CompanyEstablishmentController::class, 'index'])
        ->name('companies.establishments.index');
    Route::post('companies/{company}/establishments/{establishment}/emission-points', [CompanyEmissionPointController::class, 'store'])
        ->middleware('throttle:critical')
        ->name('companies.establishments.emission-points.store');
    Route::patch('companies/{company}/establishments/{establishment}/emission-points/{emissionPoint}', [CompanyEmissionPointController::class, 'update'])
        ->middleware('throttle:critical')
        ->name('companies.establishments.emission-points.update');
    Route::apiResource('companies.customers', CustomerController::class);
    Route::apiResource('companies.pos-categories', PosCategoryController::class)
        ->parameters(['pos-categories' => 'posCategory']);
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
    // API v1 for service-to-service clients (FitOS): idempotent create + emit.
    Route::prefix('v1')->group(function () {
        Route::post('companies/{company}/invoices/issue', [InvoiceIssueController::class, 'issue'])
            ->middleware('throttle:critical')
            ->name('v1.companies.invoices.issue');
        Route::get('companies/{company}/invoices/by-reference/{externalReference}', [InvoiceIssueController::class, 'showByReference'])
            ->name('v1.companies.invoices.by-reference');
    });
    Route::get('companies/{company}/invoices/{invoice}/ride', [InvoiceController::class, 'ride'])
        ->name('companies.invoices.ride');

    Route::prefix('admin')->middleware('super_admin')->group(function () {
        Route::get('companies', [AdminCompanyController::class, 'index'])->name('admin.companies.index');
        Route::post('companies/{company}/activate', [AdminCompanyController::class, 'activate'])->name('admin.companies.activate');
        Route::post('companies/{company}/deactivate', [AdminCompanyController::class, 'deactivate'])->name('admin.companies.deactivate');

        Route::get('metrics', [AdminMetricsController::class, 'index'])->name('admin.metrics');

        Route::get('plans', [AdminPlanController::class, 'index'])->name('admin.plans.index');
        Route::post('plans', [AdminPlanController::class, 'store'])->name('admin.plans.store');
        Route::put('plans/{plan}', [AdminPlanController::class, 'update'])->name('admin.plans.update');
        Route::delete('plans/{plan}', [AdminPlanController::class, 'destroy'])->name('admin.plans.destroy');
    });
});
