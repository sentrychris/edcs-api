<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RegistrationRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class SanctumAuthController extends Controller
{
    /**
     * Register users
     */
    #[OA\Post(
        path: '/auth/register',
        summary: 'Register a new user account',
        tags: ['Sanctum Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Chris Rowles'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'me@rowles.ch'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret123'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User registered — returns a Sanctum bearer token',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthToken')
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(RegistrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('adminLogin')->plainTextToken;

        return response()->json([
            'name' => $user->name,
            'token' => $token,
            'expiry' => (config('sanctum.expiration') * 60),
        ]);
    }

    /**
     * Login users
     */
    #[OA\Post(
        path: '/auth/login',
        summary: 'Log in with email and password',
        tags: ['Sanctum Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'me@rowles.ch'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated — returns a Sanctum bearer token',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthToken')
            ),
            new OA\Response(response: 401, description: 'Invalid credentials'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        if ($request->user()) {
            $request->user()->tokens()->delete();
        }

        $auth = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($auth)) {
            return response()->json([
                'message' => 'Invalid login credentials.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $user = Auth::user();
        $token = $user->createToken('adminLogin')->plainTextToken;

        return response()->json([
            'name' => $user->name,
            'token' => $token,
            'expiry' => (config('sanctum.expiration') * 60),
        ]);
    }

    /**
     * Logout users
     */
    #[OA\Post(
        path: '/auth/logout',
        summary: 'Revoke all tokens for the authenticated user',
        security: [['sanctum' => []]],
        tags: ['Sanctum Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged out',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Logged out')]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    /**
     * Fetch authenticated user
     */
    #[OA\Get(
        path: '/auth/me',
        summary: 'Get the authenticated user with commander details',
        security: [['sanctum' => []]],
        tags: ['Sanctum Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated user',
                content: new OA\JsonContent(ref: '#/components/schemas/User')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        if ($request->user()) {
            return response()->json(
                new UserResource($request->user()->load('commander.lastSystem'))
            );
        }

        return response()->json([
            'message' => 'Unauthorized.',
        ], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
