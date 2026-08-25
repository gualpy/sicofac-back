<?php

namespace App\Providers;

use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Integrations\Sri\Contracts\XadesSignerInterface;
use App\Integrations\Sri\Contracts\XmlBuilderInterface;
use App\Integrations\Sri\Dummy\DummySriClient;
use App\Integrations\Sri\Dummy\DummyXadesSigner;
use App\Integrations\Sri\Dummy\DummyXmlBuilder;
use App\Integrations\Sri\Real\RealSriClient;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Policies\CompanyPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ProductPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(XmlBuilderInterface::class, DummyXmlBuilder::class);
        $this->app->bind(XadesSignerInterface::class, DummyXadesSigner::class);

        $this->app->bind(SriClientInterface::class, function () {
            return config('sri.driver', 'dummy') === 'real'
                ? app(RealSriClient::class)
                : app(DummySriClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
        ]);

        RateLimiter::for('critical', fn (Request $request) => [
            Limit::perMinute(30)->by((string) ($request->user()?->id ?: $request->ip())),
        ]);
    }
}
