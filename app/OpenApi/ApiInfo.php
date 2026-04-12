<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'EDCS API',
    description: 'The Elite Dangerous Companion Suite API provides galaxy data from Elite Dangerous to various consumers, including the EDCS frontend at https://edcs.app. Data is sourced from EDSM (Elite Dangerous Star Map), EDDN (Elite Dangerous Data Network), and the Frontier Companion API (CAPI).',
    contact: new OA\Contact(name: 'EDCS', url: 'https://edcs.app')
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'EDCS API Server')]
#[OA\Tag(name: 'Sanctum Auth', description: 'Sanctum authentication')]
#[OA\Tag(name: 'Frontier Auth', description: 'Frontier SSO OAuth 2.0 PKCE authentication')]
#[OA\Tag(name: 'Frontier CAPI', description: 'Frontier Companion API — authenticated commander data')]
#[OA\Tag(name: 'GalNet', description: 'In-game GalNet news articles')]
#[OA\Tag(name: 'Commander', description: 'Authenticated commander profile management')]
#[OA\Tag(name: 'Statistics', description: 'Aggregate database statistics')]
#[OA\Tag(name: 'Systems', description: 'Star systems across the galaxy')]
#[OA\Tag(name: 'System Search', description: 'Search and utility endpoints for systems')]
#[OA\Tag(name: 'Bodies', description: 'Celestial bodies — stars, planets, moons')]
#[OA\Tag(name: 'Stations', description: 'Stations, outposts, and megaships')]
#[OA\Tag(name: 'Market', description: 'Live commodity market data from EDDN')]
class ApiInfo {}
