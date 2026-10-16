<?php

namespace App\Exceptions\Billing;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hard cap on a plan (documentos/mes, usuarios) was reached. Carries its own
 * render() so every call site (controllers and services) gets the same JSON
 * shape for free, no try/catch needed at each throw point.
 */
class PlanLimitExceededException extends DomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => (object) $this->details,
            ],
        ], 403);
    }
}
