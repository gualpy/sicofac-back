<?php

namespace App\Http\Controllers\Api;

use App\Enums\{CompanyMembershipRole, CompanyMembershipStatus};
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\{ActivateCertificateRequest, AddCompanyMemberRequest, ChangeEnvironmentRequest, StoreCompanyRequest,UpdateCompanyRequest,UpdateCompanyMemberRequest,UpdateIssuerConfigRequest, UploadCertificateRequest };
use App\Models\{Company,User};
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CompanyCertificateService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CompanyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CompanyCertificateService $certificateService,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        /** @var \App\Models\User $user */
        $user = request()->user();

        return response()->json(
            $user->companies()
                ->wherePivot('status', CompanyMembershipStatus::Active->value)
                ->latest()
                ->paginate(15)
        );
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        $company = Company::query()->create($request->validated());
        request()->user()->companies()->syncWithoutDetaching([
            $company->id => [
                'role' => CompanyMembershipRole::Owner->value,
                'status' => CompanyMembershipStatus::Active->value,
            ],
        ]);

        $this->auditLogger->log('company.created', $company, null, $company->toArray());

        return response()->json($company, 201);
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return response()->json($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $before = $company->toArray();
        $company->update($request->validated());
        $after = $company->fresh()->toArray();
        $this->auditLogger->log('company.updated', $company, $before, $after);

        return response()->json($company->fresh());
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $before = $company->toArray();
        $company->delete();
        $this->auditLogger->log('company.deleted', $company, $before, null);

        return response()->json(status: 204);
    }

    public function updateIssuerConfig(UpdateIssuerConfigRequest $request, Company $company): JsonResponse
    {
        $before = $company->toArray();
        $company->update($request->validated());
        $after = $company->fresh()->toArray();
        $this->auditLogger->log('company.issuer_config.updated', $company, $before, $after);

        return response()->json($company->fresh());
    }

    public function changeEnvironment(ChangeEnvironmentRequest $request, Company $company): JsonResponse
    {
        $before = $company->toArray();
        $company->update(['environment' => $request->validated('environment')]);
        $after = $company->fresh()->toArray();
        $this->auditLogger->log('company.environment.changed', $company, $before, $after);

        return response()->json($company->fresh());
    }

    public function uploadCertificate(UploadCertificateRequest $request, Company $company): JsonResponse
    {
        try {
            $certificate = $this->certificateService->uploadCertificate(
                company: $company,
                file: $request->file('certificate'),
                password: $request->validated('certificate_password'),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->auditLogger->log('certificate_uploaded', $company, null, [
            'version' => $certificate->version,
            'status' => $certificate->status,
            'path' => $certificate->path,
            'expires_at' => $certificate->expires_at?->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Certificate uploaded in pending state.',
            'company_id' => $company->id,
            'version' => $certificate->version,
            'status' => $certificate->status,
            'is_active' => $certificate->is_active,
            'path' => $certificate->path,
            'uploaded_at' => $certificate->uploaded_at,
            'expires_at' => $certificate->expires_at,
        ], 201);
    }

    public function activateCertificate(
        ActivateCertificateRequest $request,
        Company $company,
        int $version
    ): JsonResponse {
        $activated = $this->certificateService->activateCertificate($company, $version);

        $this->auditLogger->log('certificate_activated', $company, null, [
            'version' => $activated->version,
            'path' => $activated->path,
        ]);

        return response()->json([
            'message' => 'Certificate activated.',
            'company_id' => $company->id,
            'version' => $activated->version,
            'status' => $activated->status,
            'is_active' => $activated->is_active,
            'path' => $activated->path,
        ]);
    }

    public function deleteCertificate(Company $company): JsonResponse
    {
        $this->authorize('manageCertificate', $company);

        $before = $company->toArray();
        $active = $company->certificates()->where('is_active', true)->first();
        if ($active) {
            $active->update([
                'is_active' => false,
                'status' => 'archived',
                'archived_at' => now(),
            ]);
        }
        $company->update([
            'certificate_path' => null,
            'certificate_name' => null,
            'certificate_uploaded_at' => null,
        ]);

        $this->auditLogger->log('company.certificate.deleted', $company, $before, $company->fresh()->toArray(), [
            'active_version_archived' => $active?->version,
        ]);

        return response()->json(status: 204);
    }

    public function addMember(AddCompanyMemberRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $member = User::query()->findOrFail($data['user_id']);

        $company->users()->syncWithoutDetaching([
            $member->id => [
                'role' => $data['role'],
                'status' => $data['status'] ?? CompanyMembershipStatus::Active->value,
            ],
        ]);

        $this->auditLogger->log(
            'company.member.added',
            $company,
            null,
            [
                'member_user_id' => $member->id,
                'role' => $data['role'],
                'status' => $data['status'] ?? CompanyMembershipStatus::Active->value,
            ],
        );

        return response()->json([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'role' => $data['role'],
            'status' => $data['status'] ?? CompanyMembershipStatus::Active->value,
        ], 201);
    }

    public function updateMember(UpdateCompanyMemberRequest $request, Company $company, User $user): JsonResponse
    {
        if (! $company->users()->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'User is not a member of this company.'], 404);
        }

        $pivotBefore = $company->users()->whereKey($user->id)->first()?->pivot?->toArray();
        $company->users()->updateExistingPivot($user->id, $request->validated());
        $pivotAfter = $company->users()->whereKey($user->id)->first()?->pivot?->toArray();

        $this->auditLogger->log(
            'company.member.updated',
            $company,
            $pivotBefore,
            $pivotAfter,
            meta: ['member_user_id' => $user->id],
        );

        return response()->json([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'pivot' => $pivotAfter,
        ]);
    }
}
