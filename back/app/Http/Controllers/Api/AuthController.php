<?php

namespace App\Http\Controllers\Api;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\Company;
use App\Models\User;
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
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $company] = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $company = Company::query()->create([
                'name' => $data['company_name'],
                'ruc' => $data['company_ruc'],
                'environment' => $data['company_environment'],
            ]);

            $user->companies()->attach($company->id, [
                'role' => CompanyMembershipRole::Owner->value,
                'status' => CompanyMembershipStatus::Active->value,
            ]);

            return [$user, $company->fresh()];
        });

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
}

