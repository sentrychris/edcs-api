<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchSystemRequest;
use App\Http\Resources\SystemResource;
use App\Models\System;
use App\Services\EdsmApiService;
use App\Traits\HasQueryRelations;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class SystemController extends Controller
{
    use HasQueryRelations;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Map the allowed query parameters to the relations that can be loaded
        // for the system model e.g. withBodies will load bodies for the system
        $this->setQueryRelations([
            'withInformation' => 'information',
            'withBodies' => 'bodies',
            'withStations' => 'stations',
            'withFleetCarriers' => 'fleetCarriers',
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
            new OA\Parameter(name: 'withFleetCarriers', in: 'query', required: false, description: 'Embed fleet carriers currently docked in the system', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
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
            new OA\Parameter(name: 'withFleetCarriers', in: 'query', required: false, description: 'Embed fleet carriers currently docked in the system', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
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
    public function show(string $slug, SearchSystemRequest $request, EdsmApiService $service): SystemResource|Response
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
            $system = $service->updateSystem($slug);
        }

        // If no system if found, then return a 404 not found response
        if (! $system) {
            return response([], 404);
        }

        // The EDSM stations endpoint returns both regular stations and fleet
        // carriers, so we refresh them together at most once per request even
        // if the client asked for both relations.
        $stationsRefreshed = false;

        // Update the system with the requested relations e.g. withBodies, withInformation, etc.
        foreach ($this->getQueryRelations() as $query => $relation) {
            if (array_key_exists($query, $validated) && (int) $validated[$query] === 1) {
                // Check for existing system bodies and update if necessary
                if ($relation === 'bodies' && ! $system->bodies()->exists() && $system->body_count === null) {
                    $service->updateSystemBodies($system);
                }

                // Check for existing system information and update if necessary
                if ($relation === 'information' && ! $system->information()->exists()) {
                    $service->updateSystemInformation($system);
                }

                // Always refresh stations + fleet carriers from EDSM on cache
                // miss — carriers are mobile so we can't trust stored state.
                if (($relation === 'stations' || $relation === 'fleetCarriers') && ! $stationsRefreshed) {
                    $service->updateSystemStations($system);
                    $stationsRefreshed = true;
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
}
