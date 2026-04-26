<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Services\Frontier\FrontierAuthService;
use App\Services\Frontier\FrontierCApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class FrontierAuthController extends Controller
{
    /**
     * Frontier Auth service.
     *
     * @var FrontierAuthService
     */
    private $frontierAuthService;

    /**
     * Frontier CAPI service.
     *
     * @var FrontierCApiService
     */
    private $frontierCApiService;

    /**
     * Create a new controller instance.
     *
     * @param  FrontierAuthService  $frontierAuthService  - the frontier auth service
     * @param  FrontierCApiService  $frontierCApiService  - the frontier CAPI service
     */
    public function __construct(
        FrontierAuthService $frontierAuthService,
        FrontierCApiService $frontierCApiService,
    ) {
        $this->frontierAuthService = $frontierAuthService;
        $this->frontierCApiService = $frontierCApiService;

        $this->middleware('frontier.auth')->only('me');
    }

    /**
     * Return the login URL.
     *
     * @return mixed - the response
     */
    #[OA\Get(
        path: '/auth/frontier/login',
        summary: 'Get Frontier OAuth authorization server metadata',
        description: 'Returns authorization server info (auth URL, token endpoint, PKCE parameters) for the frontend to construct a login redirect.',
        tags: ['Frontier Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authorization server information',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            description: 'Authorization server metadata'
                        ),
                    ]
                )
            ),
        ]
    )]
    public function login()
    {
        return response()->json([
            'data' => $this->frontierAuthService
                ->getAuthorizationServerInformation(),
        ]);
    }

    /**
     * Frontier SSO callback endpoint.
     *
     * This method receives the callback from the Frontier oauth server, containing the
     * authorization grant code. This code is then exchanged for an access token which
     * is then used to retrieve the user profile.
     *
     * @param  Request  $request  - the request object
     * @return mixed - the response
     */
    #[OA\Get(
        path: '/auth/frontier/callback',
        summary: 'Handle Frontier SSO OAuth callback',
        description: 'Exchanges the authorization code for a Frontier access token, creates/confirms the user and commander record, then redirects to the frontend with a cmdr_token HttpOnly cookie.',
        tags: ['Frontier Auth'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: true, description: 'Authorization grant code from Frontier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_verifier', in: 'query', required: true, description: 'PKCE code verifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirects to frontend with cmdr_token cookie set'),
            new OA\Response(response: 400, description: 'Invalid authorization code or verifier'),
        ]
    )]
    public function callback(Request $request)
    {
        // Authorize the user and decode the token to get their Frontier profile
        $auth = $this->frontierAuthService->authorize($request);
        $frontier = $this->frontierAuthService->decode($auth->access_token);

        // Confirm the user, creates a new user record in our db if they do not exist
        $user = $this->frontierAuthService->confirmUser($frontier, $auth);

        // Confirm the CMDR, creates a new CMDR record in our db if they do not exist
        $this->frontierCApiService->confirmCommander($user);

        // Revoke any existing Sanctum tokens then issue a fresh one.
        $user->tokens()->delete();
        $token = $user->createToken('frontier')->plainTextToken;

        Log::info($token);

        return redirect()->to(config('app.frontend_url').'/api/auth/callback')->cookie(
            'cmdr_token', $token, 60, '/', null, $request->isSecure(), true
        );
    }

    /**
     * Access the user based on cookie.
     *
     * @return void
     */
    #[OA\Post(
        path: '/auth/frontier/me',
        summary: 'Get the Frontier-authenticated user via cookie',
        description: 'Returns the authenticated user and their commander data using the cmdr_token HttpOnly cookie set during the SSO callback.',
        tags: ['Frontier Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated user with commander data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                                new OA\Property(property: 'token', type: 'string'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated — missing or invalid cmdr_token cookie'),
        ]
    )]
    public function me(Request $request)
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($request->user()->load('commander.lastSystem')),
                'token' => $request->cookie('cmdr_token'),
            ],
        ]);
    }
}
