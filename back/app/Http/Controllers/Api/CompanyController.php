<?php

namespace App\Http\Controllers\Api;

use App\Enums\{CompanyMembershipRole, CompanyMembershipStatus};
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\{ActivateCertificateRequest, AddCompanyMemberRequest, ChangeEnvironmentRequest, CreateApiKeyRequest, InviteCompanyMemberRequest, StoreCompanyRequest,UpdateCompanyRequest,UpdateCompanyMemberRequest,UpdateIssuerConfigRequest, UploadCertificateRequest, UploadLogoRequest };
use App\Models\{Company,CompanyInvitation,User};
use Illuminate\Http\Request;
use App\Notifications\CompanyInvitationNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CompanyCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function showCertificate(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $certificate = $this->certificateService->getActiveCertificate($company);

        if (! $certificate) {
            return response()->json(null);
        }

        return response()->json([
            'version' => $certificate->version,
            'status' => $certificate->status,
            'is_active' => $certificate->is_active,
            'subject' => $certificate->subject,
            'uploaded_at' => $certificate->uploaded_at,
            'expires_at' => $certificate->expires_at,
        ]);
    }

    public function logo(Company $company)
    {
        $this->authorize('view', $company);

        if (! $company->logo_path || ! Storage::disk('local')->exists($company->logo_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($company->logo_path);
    }

    public function uploadLogo(UploadLogoRequest $request, Company $company): JsonResponse
    {
        $file = $request->file('logo');
        $extension = $file->getClientOriginalExtension();
        $path = "logos/{$company->id}/logo.{$extension}";

        if ($company->logo_path) {
            Storage::disk('local')->delete($company->logo_path);
        }

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        $company->update(['logo_path' => $path]);

        $this->auditLogger->log('company.logo.uploaded', $company, null, ['path' => $path]);

        return response()->json(['logo_path' => $path], 201);
    }

    public function deleteLogo(Company $company): JsonResponse
    {
        $this->authorize('manageIssuerConfig', $company);

        if ($company->logo_path) {
            Storage::disk('local')->delete($company->logo_path);
        }
        $company->update(['logo_path' => null]);

        $this->auditLogger->log('company.logo.deleted', $company, null, null);

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

    public function listMembers(Company $company): JsonResponse
    {
        $this->authorize('manageUsers', $company);

        $members = $company->users()->get(['users.id', 'users.name', 'users.email'])->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
            'status' => $user->pivot->status,
        ])->values();

        $pendingInvitations = CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'role', 'expires_at']);

        return response()->json([
            'members' => $members,
            'pending_invitations' => $pendingInvitations,
        ]);
    }

    public function inviteMember(InviteCompanyMemberRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        $alreadyMember = $company->users()
            ->where('email', $data['email'])
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => ['Ya es miembro de esta empresa.']]);
        }

        CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->delete();

        $invitation = CompanyInvitation::query()->create([
            'company_id' => $company->id,
            'invited_by' => $request->user()->id,
            'email' => $data['email'],
            'role' => $data['role'],
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new CompanyInvitationNotification($invitation));

        $this->auditLogger->log(
            'company.invitation.created',
            $company,
            null,
            ['email' => $data['email'], 'role' => $data['role']],
        );

        return response()->json(['message' => 'Invitacion enviada.'], 201);
    }

    public function revokeInvitation(Company $company, CompanyInvitation $invitation): JsonResponse
    {
        $this->authorize('manageUsers', $company);
        abort_unless($invitation->company_id === $company->id, 404);

        $invitation->update(['revoked_at' => now()]);

        $this->auditLogger->log(
            'company.invitation.revoked',
            $company,
            null,
            ['email' => $invitation->email],
        );

        return response()->json(status: 204);
    }

    public function listApiKeys(Request $request, Company $company): JsonResponse
    {
        $this->authorizeHumanOwner($request, $company);

        $tokens = $company->tokens()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return response()->json(['api_keys' => $tokens]);
    }

    public function createApiKey(CreateApiKeyRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        $token = $company->createToken($data['name']);

        $this->auditLogger->log(
            'company.api_key.created',
            $company,
            null,
            ['name' => $data['name'], 'token_id' => $token->accessToken->id],
        );

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            // Shown once -- only the hash is stored, this is the only chance to see it.
            'token' => $token->plainTextToken,
        ], 201);
    }

    public function revokeApiKey(Request $request, Company $company, int $tokenId): JsonResponse
    {
        $this->authorizeHumanOwner($request, $company);

        $deleted = $company->tokens()->whereKey($tokenId)->delete();
        abort_if($deleted === 0, 404);

        $this->auditLogger->log('company.api_key.revoked', $company, null, ['token_id' => $tokenId]);

        return response()->json(status: 204);
    }

    /**
     * API keys are managed by a logged-in human owner only -- a key can
     * never be used to manage keys (including itself).
     */
    private function authorizeHumanOwner(Request $request, Company $company): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        abort_unless($actor->can('manageApiKeys', $company), 403);
    }
}
