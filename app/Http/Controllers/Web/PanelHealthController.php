<?php

namespace App\Http\Controllers\Web;

use App\Enums\CompanyMembershipRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PanelHealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $company = Company::query()->find($companyId);
        if (! $company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $user = $request->user();
        if (! $user || ! $user->hasCompanyRole($company->id, [CompanyMembershipRole::Owner->value, CompanyMembershipRole::Admin->value])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $dbOk = false;
        $cacheOk = false;
        $queueOk = false;
        $storageOk = false;

        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (\Throwable) {
        }

        try {
            $key = 'health:'.uniqid();
            Cache::put($key, 'ok', 10);
            $cacheOk = Cache::get($key) === 'ok';
            Cache::forget($key);
        } catch (\Throwable) {
        }

        try {
            $queueConnection = config('queue.default');
            if ($queueConnection === 'database') {
                $queueOk = Schema::hasTable('jobs');
            } else {
                $queueOk = true;
            }
        } catch (\Throwable) {
        }

        try {
            $disk = Storage::disk(config('filesystems.default'));
            $probe = 'health/'.uniqid().'.txt';
            $disk->put($probe, 'ok');
            $storageOk = $disk->exists($probe);
            $disk->delete($probe);
        } catch (\Throwable) {
            $storageOk = is_writable(storage_path('app'));
        }

        return response()->json([
            'ok' => $dbOk && $cacheOk && $queueOk && $storageOk,
            'checks' => [
                'db' => $dbOk,
                'cache' => $cacheOk,
                'queue' => $queueOk,
                'storage' => $storageOk,
            ],
        ]);
    }
}
