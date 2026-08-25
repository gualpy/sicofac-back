<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $action,
        ?Model $model = null,
        ?array $before = null,
        ?array $after = null,
        array $meta = [],
        ?int $companyId = null,
    ): void {
        $request = request();
        $user = auth()->user();

        $resolvedCompanyId = $companyId
            ?? ($model?->getAttribute('company_id'))
            ?? $request?->route('company')?->id;

        AuditLog::query()->create([
            'company_id' => $resolvedCompanyId,
            'user_id' => $user?->id,
            'action' => $action,
            'model_type' => $model ? $model::class : null,
            'model_id' => $model?->getKey(),
            'before_json' => $this->sanitize($before),
            'after_json' => $this->sanitize($after),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'meta_json' => $this->sanitize($meta),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sensitiveKeys = [
                'password',
                'certificate_password',
                'p12_password',
                'token',
                'secret',
            ];

            foreach ($sensitiveKeys as $key) {
                if (Arr::has($value, $key)) {
                    Arr::set($value, $key, '***');
                }
            }

            foreach ($value as $k => $v) {
                $value[$k] = $this->sanitize($v);
            }
        }

        return $value;
    }
}

