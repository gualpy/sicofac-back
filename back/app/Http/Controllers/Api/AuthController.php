<?php

namespace App\Http\Controllers\Api;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth')]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/register',
        tags: ['Auth'],
        description: 'Creates the user account and their single company (facturador) atomically. There is no separate self-service "create company" endpoint.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'company_name', 'company_ruc', 'company_environment'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'device_name', type: 'string', nullable: true),
                    new OA\Property(property: 'company_name', type: 'string'),
                    new OA\Property(property: 'company_ruc', type: 'string', description: '13 digits'),
                    new OA\Property(property: 'company_environment', type: 'string', enum: ['test', 'production']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'User and company created, token issued'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(RegisterRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();

        [$user, $company] = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => config('legal.terms_version'),
            ])->save();

            $company = Company::query()->create([
                'name' => $data['company_name'],
                'ruc' => $data['company_ruc'],
                'environment' => $data['company_environment'],
                'plan_id' => $data['plan_id'],
            ]);

            $user->companies()->attach($company->id, [
                'role' => CompanyMembershipRole::Owner->value,
                'status' => CompanyMembershipStatus::Active->value,
            ]);

            return [$user, $company->fresh()];
        });

        $auditLogger->log(
            'user.terms_accepted',
            $user,
            null,
            ['terms_version' => $user->terms_version, 'accepted_at' => $user->terms_accepted_at->toIso8601String()],
            companyId: $company->id,
        );

        // Account exists but stays unusable (see login()) until the user
        // clicks the emailed confirmation link -- no token issued here.
        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Cuenta creada. Revisa tu correo para confirmarla.',
            'user' => $user,
            'company' => $company,
        ], 201);
    }

    #[OA\Post(
        path: '/auth/login',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'device_name', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token issued'),
            new OA\Response(response: 422, description: 'Invalid credentials'),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Debes confirmar tu correo antes de iniciar sesion.',
                'code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    #[OA\Get(
        path: '/auth/me',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Current authenticated user')]
    )]
    public function me(): JsonResponse
    {
        return response()->json(request()->user());
    }

    #[OA\Delete(
        path: '/auth/logout',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Token revoked')]
    )]
    public function logout(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        $user->currentAccessToken()?->delete();

        return response()->json(status: 204);
    }

    #[OA\Get(
        path: '/auth/email/verify/{id}/{hash}',
        tags: ['Auth'],
        description: 'Signed link from the confirmation email. Marks the account verified and redirects to the SPA -- the raw link is meant to be opened directly from the inbox, not called by the frontend as an API.',
        responses: [new OA\Response(response: 302, description: 'Redirects to the frontend, verified or already-verified either way')]
    )]
    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away(config('app.frontend_url').'/email-confirmado?ok=0');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->away(config('app.frontend_url').'/email-confirmado?ok=1');
    }

    #[OA\Post(
        path: '/auth/email/resend',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ])
        ),
        responses: [new OA\Response(response: 200, description: 'Generic success -- never reveals whether the email exists')]
    )]
    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        // Same response whether the account exists, is already verified, or
        // isn't found -- resend is a public unauthenticated endpoint and
        // must not leak which emails are registered.
        return response()->json([
            'message' => 'Si el correo esta registrado y pendiente de confirmar, te reenviamos el enlace.',
        ]);
    }

    #[OA\Get(
        path: '/auth/invitations/{token}',
        tags: ['Auth'],
        description: 'Public lookup for the accept-invitation page: tells the frontend whether to show a password field (new user) or just a confirmation (existing account).',
        responses: [
            new OA\Response(response: 200, description: 'Invitation details'),
            new OA\Response(response: 404, description: 'Invalid, expired, or already-used invitation'),
        ]
    )]
    public function showInvitation(string $token): JsonResponse
    {
        $invitation = CompanyInvitation::query()->where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['message' => 'Invitacion invalida o vencida.'], 404);
        }

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'company_name' => $invitation->company->name,
            'requires_password' => ! User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    #[OA\Post(
        path: '/auth/invitations/{token}/accept',
        tags: ['Auth'],
        description: 'Accepts a company invitation. Creates the user (and marks the email pre-verified, since the invite link itself proves ownership) when the email has no account yet, otherwise just attaches the existing user to the company.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', nullable: true, description: 'Required only when the invited email has no existing account'),
                new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true, description: 'Required only when the invited email has no existing account'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Invitation accepted'),
            new OA\Response(response: 404, description: 'Invalid, expired, or already-used invitation'),
        ]
    )]
    public function acceptInvitation(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = CompanyInvitation::query()->where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['message' => 'Invitacion invalida o vencida.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($invitation, $data) {
            $user = User::query()->where('email', $invitation->email)->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => $data['password'],
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->companies()->syncWithoutDetaching([
                $invitation->company_id => [
                    'role' => $invitation->role,
                    'status' => CompanyMembershipStatus::Active->value,
                ],
            ]);

            $invitation->update(['accepted_at' => now()]);
        });

        return response()->json(['message' => 'Invitacion aceptada. Ya podes iniciar sesion.']);
    }
}

