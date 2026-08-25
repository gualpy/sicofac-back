<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth')]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/register',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'device_name', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'User created, token issued'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
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
}

