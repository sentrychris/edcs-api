<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchSystemByDistanceRequest;
use App\Http\Requests\SearchSystemByInformationRequest;
use App\Http\Requests\SearchSystemRequest;
use App\Http\Requests\SearchSystemRouteRequest;
use App\Http\Resources\SystemDistanceResource;
use App\Http\Resources\SystemResource;
use App\Http\Resources\SystemRouteResource;
use App\Models\System;
use App\Services\EdsmApiService;
use App\Services\NavRouteFinderService;
use App\Traits\HasQueryRelations;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemController extends Controller
{
    use HasQueryRelations;

    /**
     * EDSM API Service
     */
    private EdsmApiService $edsmApiService;

    /**
     * Route Finder Service
     */
    private NavRouteFinderService $navRouteFinderService;

    /**
     * Constructor
     *
     * @param  EdsmApiService  $edsmApiService  - injected EDSM API service
     * @param  NavRouteFinderService  $NavRouteFinderService  - injected route finder service
     */
    public function __construct(EdsmApiService $edsmApiService, NavRouteFinderService $navRouteFinderService)
    {
        $this->edsmApiService = $edsmApiService;
        $this->navRouteFinderService = $navRouteFinderService;

        // Map the allowed query parameters to the relations that can be loaded
        // for the system model e.g. withBodies will load bodies for the system
        $this->setQueryRelations([
            'withInformation' => 'information',
            'withBodies' => 'bodies',
            'withStations' => 'stations',
        ]);
    }

    /**
     * List systems.
     */
    #[OA\Get(
        path: '/systems',
        summary: 'List or search star systems',
        description: 'Returns a paginated list of systems. When no name is given the results are served from cache. Pass withInformation, withBodies, or withStations to embed related data.',
        tags: ['Systems'],
        parameters: [
            new OA\Parameter(name: 'name', in: 'query', required: false, description: 'Filter by system name (partial match by default)', schema: new OA\Schema(type: 'string', example: 'Sol')),
            new OA\Parameter(name: 'exactSearch', in: 'query', required: false, description: 'Require an exact name match', schema: new OA\Schema(type: 'integer', enum: [0, 1], example: 1)),
            new OA\Parameter(name: 'withInformation', in: 'query', required: false, description: 'Embed political/demographic information', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withBodies', in: 'query', required: false, description: 'Embed celestial bodies', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withStations', in: 'query', required: false, description: 'Embed stations and outposts', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Results per page', schema: new OA\Schema(type: 'integer', example: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of systems',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/System')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function index(SearchSystemRequest $request): AnonymousResourceCollection
    {
        // Get the request parameters
        $page = $request->input('page', 1);
        $limit = $request->input('limit', config('app.pagination.limit'));
        $validated = $request->validated();

        // Handle the request
        if ($request->input('name') !== null) {
            // Handle queries for systems if searching for systems by name, with
            // or without exact search
            $systems = System::filter($validated, (int) $request->exactSearch)
                ->simplePaginate($limit)
                ->appends($request->all());
        } else {
            // Retrieve from cache or query the database
            $systems = Cache::remember("systems_page_{$page}", 3600, fn () => System::filter($validated, 0)
                ->simplePaginate($limit)
                ->appends($request->all())
            );
        }

        // Load the query relations for the collection e.g withInformation, withBodies, etc.
        $systems = $this->loadQueryRelations($validated, $systems);

        // Return a collection of system resources
        return SystemResource::collection($systems);
    }

    /**
     * Show system.
     *
     * User can provide the following request parameters.
     *
     * withInformation: 0 or 1 - Return system with associated information.
     * withBodies: 0 or 1 - Return system with associated celestial bodies.
     * withStations: 0 or 1 - Return system with associated stations and outposts.
     *
     * @return SystemResource
     */
    #[OA\Get(
        path: '/systems/{slug}',
        summary: 'Get a single system by slug',
        description: 'Retrieves a system by its slug ({id64}-{name}). If not in the local database the API transparently queries EDSM and stores the result. Pass withInformation, withBodies, or withStations to embed related data.',
        tags: ['Systems'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'System slug in format {id64}-{name}', schema: new OA\Schema(type: 'string', example: '10477373803-sol')),
            new OA\Parameter(name: 'withInformation', in: 'query', required: false, description: 'Embed political/demographic information', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withBodies', in: 'query', required: false, description: 'Embed celestial bodies', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withStations', in: 'query', required: false, description: 'Embed stations and outposts', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'System',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/System'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'System not found'),
        ]
    )]
    public function show(string $slug, SearchSystemRequest $request): SystemResource|Response
    {
        // Get the request parameters
        $validated = $request->validated();

        // Build a cache key that includes the requested relations
        $relations = collect($this->getQueryRelations())
            ->keys()
            ->filter(fn ($query) => array_key_exists($query, $validated) && (int) $validated[$query] === 1)
            ->sort()
            ->implode('_');
        $cacheKey = "system_detail_{$slug}".($relations ? "_{$relations}" : '');

        // Attempt to retrieve the system from the cache
        $system = Cache::get($cacheKey);

        // If it exists in the cache, then return it
        if ($system) {
            return new SystemResource($system);
        }

        // Otherwise it's a cache MISS
        Log::channel('pages:cache')
            ->info("{$cacheKey} cache MISS - refreshing cache for this page");

        // Attempt to retrieve the system from our database
        $system = System::whereSlug($slug)->first();

        if (! $system) {
            // If the system doesn't exist in our database, query EDSM for it
            // and then update our records
            $system = $this->edsmApiService->updateSystem($slug);
        }

        // If no system if found, then return a 404 not found response
        if (! $system) {
            return response([], 404);
        }

        // Update the system with the requested relations e.g. withBodies, withInformation, etc.
        foreach ($this->getQueryRelations() as $query => $relation) {
            if (array_key_exists($query, $validated) && (int) $validated[$query] === 1) {
                // Check for existing system bodies and update if necessary
                if ($relation === 'bodies' && ! $system->bodies()->exists() && $system->body_count === null) {
                    $this->edsmApiService->updateSystemBodies($system);
                }

                // Check for existing system information and update if necessary
                if ($relation === 'information' && ! $system->information()->exists()) {
                    $this->edsmApiService->updateSystemInformation($system);
                }

                // Check for existing system stations and update if necessary
                if ($relation === 'stations' && ! $system->stations()->exists()) {
                    $this->edsmApiService->updateSystemStations($system);
                }

                // Load the relation
                $system->load($relation);
            }
        }

        // Cache the system details for 1 hour
        Cache::set($cacheKey, $system, 3600);

        // Return the system resource
        return new SystemResource($system);
    }

    /**
     * Get the last updated system
     *
     * @return SystemResource
     */
    #[OA\Get(
        path: '/systems/last-updated',
        summary: 'Get the most recently updated system',
        description: 'Returns the system with the latest updated_at timestamp, including its bodies and information. Useful for monitoring data freshness.',
        tags: ['Systems'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Most recently updated system',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/System'),
                    ]
                )
            ),
        ]
    )]
    public function getLastUpdated()
    {
        $system = Cache::remember('latest_system', 3600, fn () => System::latest('updated_at')->first());

        if ($system->body_count === null && ! $system->bodies()->exists()) {
            $this->edsmApiService->updateSystemBodies($system);
        }

        if (! $system->information()->exists()) {
            $this->edsmApiService->updateSystemInformation($system);
        }

        $this->loadQueryRelations(
            ['withBodies' => 1, 'withInformation' => 1],
            $system
        );

        return new SystemResource($system);
    }

    /**
     * Find systems by distance in light years.
     */
    #[OA\Get(
        path: '/systems/search/distance',
        summary: 'Find systems within a given distance of a position',
        description: 'Returns a paginated list of systems within the specified number of light years from a position, sorted by distance. The position can be specified either by a system slug or by raw galactic (x, y, z) coordinates — slug takes precedence if both are provided.',
        tags: ['System Search'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'query', required: false, description: 'System slug ({id64}-{name}) to use as the search origin', schema: new OA\Schema(type: 'string', example: '10477373803-sol')),
            new OA\Parameter(name: 'x', in: 'query', required: false, description: 'Galactic X coordinate (required when slug is not provided)', schema: new OA\Schema(type: 'number', format: 'float', example: 0.0)),
            new OA\Parameter(name: 'y', in: 'query', required: false, description: 'Galactic Y coordinate (required when slug is not provided)', schema: new OA\Schema(type: 'number', format: 'float', example: 0.0)),
            new OA\Parameter(name: 'z', in: 'query', required: false, description: 'Galactic Z coordinate (required when slug is not provided)', schema: new OA\Schema(type: 'number', format: 'float', example: 0.0)),
            new OA\Parameter(name: 'ly', in: 'query', required: false, description: 'Search radius in light years (default: 100, max: 5000)', schema: new OA\Schema(type: 'number', format: 'float', example: 100.0)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Maximum results per page (max: 1000)', schema: new OA\Schema(type: 'integer', example: 20)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Systems within the given distance, each with a calculated distance field',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SystemDistance')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function searchByDistance(SearchSystemByDistanceRequest $request)
    {
        if ($request->has('slug')) {
            $origin = System::whereSlug($request->input('slug'))->firstOrFail();
            $coords = [
                'x' => $origin->coords_x,
                'y' => $origin->coords_y,
                'z' => $origin->coords_z,
            ];
        } else {
            $coords = $request->only(['x', 'y', 'z']);
        }

        $limit = $request->input('limit', config('app.pagination.limit'));

        $systems = System::findNearest($coords, $request->input('ly', 100))
            ->with('information')
            ->simplePaginate($limit)
            ->appends($request->all());

        return SystemDistanceResource::collection($systems);
    }

    /**
     * Find the shortest route between two systems.
     *
     * User can provide the following request parameters.
     *
     * from: - Slug of the origin system.
     * to:   - Slug of the destination system.
     * ly:   - Maximum jump range in light years.
     */
    #[OA\Get(
        path: '/systems/search/route',
        summary: 'Find the shortest jump route between two systems',
        description: 'Computes the shortest route between two systems within the given jump range. Returns an ordered list of waypoints with per-hop and cumulative distances. Results are cached for 24 hours.',
        tags: ['System Search'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, description: 'Origin system slug ({id64}-{name})', schema: new OA\Schema(type: 'string', example: '8216113749-maia')),
            new OA\Parameter(name: 'to', in: 'query', required: true, description: 'Destination system slug ({id64}-{name})', schema: new OA\Schema(type: 'string', example: '670685668665-pleiades-sector-ag-n-b7-0')),
            new OA\Parameter(name: 'ly', in: 'query', required: true, description: 'Maximum jump range in light years (min: 1, max: 500)', schema: new OA\Schema(type: 'number', format: 'float', example: 40.0)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ordered list of route waypoints',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SystemRouteWaypoint')),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'No route found within the given jump range'),
        ]
    )]
    public function searchRoute(SearchSystemRouteRequest $request): AnonymousResourceCollection|Response
    {
        $from = System::whereSlug($request->input('from'))->firstOrFail();
        $to = System::whereSlug($request->input('to'))->firstOrFail();
        $ly = (float) $request->input('ly');

        $cacheKey = "system_route_{$from->slug}_{$to->slug}_{$ly}";
        $waypoints = Cache::get($cacheKey);

        if (! $waypoints) {
            $route = $this->navRouteFinderService->findRoute($from, $to, $ly);

            if ($route === null) {
                return response(['message' => 'No route found within the given jump range.'], 404);
            }

            $totalDistance = 0.0;
            $waypoints = [];

            foreach ($route as $jump => $system) {
                $hopDistance = $jump === 0
                    ? 0.0
                    : $this->navRouteFinderService->distance(
                        [
                            'x' => (float) $route[$jump - 1]->coords_x,
                            'y' => (float) $route[$jump - 1]->coords_y,
                            'z' => (float) $route[$jump - 1]->coords_z,
                        ],
                        [
                            'x' => (float) $system->coords_x,
                            'y' => (float) $system->coords_y,
                            'z' => (float) $system->coords_z,
                        ],
                    );

                $totalDistance += $hopDistance;

                $waypoints[] = [
                    'jump' => $jump,
                    'system' => $system,
                    'distance' => $hopDistance,
                    'total_distance' => $totalDistance,
                ];
            }

            Cache::set($cacheKey, $waypoints, 86400);
        }

        return SystemRouteResource::collection(collect($waypoints));
    }

    /**
     * Search for systems by information.
     *
     * User can provide the following request parameters.
     *
     * population: - Filter systems by population.
     * allegiance: - Filter systems by allegiance.
     * government: - Filter systems by government.
     * economy: - Filter systems by economy.
     * security: - Filter systems by security.
     * withInformation: 0 or 1 - Return system with associated information.
     * withBodies: 0 or 1 - Return system with associated celestial bodies.
     * withStations: 0 or 1 - Return system with associated stations and outposts.
     *
     * @return AnonymousResourceCollection
     */
    #[OA\Get(
        path: '/systems/search/information',
        summary: 'Search systems by political and demographic attributes',
        description: 'Filters systems by population (minimum), security, government, allegiance, and economy. All text filters are partial-match.',
        tags: ['System Search'],
        parameters: [
            new OA\Parameter(name: 'population', in: 'query', required: false, description: 'Minimum population', schema: new OA\Schema(type: 'integer', example: 5000000000)),
            new OA\Parameter(name: 'security', in: 'query', required: false, description: 'Security level (partial match)', schema: new OA\Schema(type: 'string', example: 'high')),
            new OA\Parameter(name: 'government', in: 'query', required: false, description: 'Government type (partial match)', schema: new OA\Schema(type: 'string', example: 'Democracy')),
            new OA\Parameter(name: 'allegiance', in: 'query', required: false, description: 'Allegiance (partial match)', schema: new OA\Schema(type: 'string', example: 'Federation')),
            new OA\Parameter(name: 'economy', in: 'query', required: false, description: 'Economy type (partial match)', schema: new OA\Schema(type: 'string', example: 'Industrial')),
            new OA\Parameter(name: 'withInformation', in: 'query', required: false, description: 'Embed political/demographic information', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withBodies', in: 'query', required: false, description: 'Embed celestial bodies', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withStations', in: 'query', required: false, description: 'Embed stations and outposts', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of matching systems',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/System')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function searchByInformation(SearchSystemByInformationRequest $request)
    {
        $validated = $request->validated();
        $infoFilters = array_intersect_key($validated, array_flip(['population', 'allegiance', 'government', 'economy', 'security']));

        $systems = System::query()
            ->when(! empty($infoFilters), fn ($query) => $query
                ->whereHas('information', function ($q) use ($infoFilters) {
                    $q
                        ->when(isset($infoFilters['population']),
                            fn ($q) => $q->where('population', '>=', $infoFilters['population'])
                        )
                        ->when(isset($infoFilters['allegiance']),
                            fn ($q) => $q->where('allegiance', 'LIKE', $infoFilters['allegiance'].'%')
                        )
                        ->when(isset($infoFilters['government']),
                            fn ($q) => $q->where('government', 'LIKE', $infoFilters['government'].'%')
                        )
                        ->when(isset($infoFilters['economy']),
                            fn ($q) => $q->where('economy', 'LIKE', $infoFilters['economy'].'%')
                        )
                        ->when(isset($infoFilters['security']),
                            fn ($q) => $q->where('security', 'LIKE', $infoFilters['security'].'%')
                        );
                })
            )
            ->simplePaginate();

        $this->loadQueryRelations(
            $request->only(['withInformation', 'withBodies', 'withStations']),
            $systems
        );

        return SystemResource::collection($systems);
    }

    /**
     * Return a full list of system ID64s as a streamed JSON response.
     *
     * Streams the response in batches to avoid loading all systems into memory at once.
     */
    #[OA\Get(
        path: '/systems/id64s',
        summary: 'Stream the full id64 list for all systems',
        description: 'Returns a streaming JSON array of every system id64. Delivered in chunks to avoid memory limits. Intended for consumers that need the full system index.',
        tags: ['Systems'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Streamed JSON array: [id64, ...]',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'integer'),
                    example: [10477373803, 8216113749]
                )
            ),
        ]
    )]
    public function getId64s(): StreamedResponse
    {
        return response()->stream(function () {
            $buffer = '[';
            $count = 0;

            System::select(['id64'])->cursor()->each(function ($system) use (&$buffer, &$count) {
                if ($count > 0) {
                    $buffer .= ',';
                }
                $buffer .= json_encode($system->id64);
                $count++;

                if ($count % 500 === 0) {
                    echo $buffer;
                    $buffer = '';
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            });

            echo $buffer.']';

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'application/json',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
