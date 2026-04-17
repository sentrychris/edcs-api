<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GalaxyController extends Controller
{
    /**
     * Return the galaxy-tile manifest.
     *
     * The manifest is a static JSON file written by `php artisan galaxy:bake-tiles`.
     * The frontend galaxy-map renderer fetches it once on mount, then uses the
     * tile keys to drive frustum-based binary tile downloads.
     */
    #[OA\Get(
        path: '/galaxy/manifest',
        summary: 'Get the galaxy-tile manifest',
        description: 'Returns the galaxy-map tile manifest (LOD 0/1/2 tile index). Tile URLs in the manifest point at /api/galaxy/tiles/v{N}/...',
        tags: ['Galaxy'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Galaxy tile manifest',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'version', type: 'integer'),
                        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'sector_size', type: 'integer'),
                        new OA\Property(property: 'lod1_size', type: 'integer'),
                        new OA\Property(
                            property: 'lod0',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'url', type: 'string'),
                                new OA\Property(property: 'count', type: 'integer'),
                            ],
                        ),
                        new OA\Property(property: 'lod1_url_template', type: 'string'),
                        new OA\Property(property: 'lod2_url_template', type: 'string'),
                        new OA\Property(property: 'lod1_tiles', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'lod2_tiles', type: 'array', items: new OA\Items(type: 'string')),
                    ],
                )
            ),
            new OA\Response(
                response: 503,
                description: 'Tiles have not been baked yet',
            ),
        ]
    )]
    public function manifest(): JsonResponse|Response
    {
        $path = public_path('galaxy-tiles/manifest.json');
        if (! is_file($path)) {
            return response('Galaxy tiles have not been baked yet.', 503);
        }

        return response()->json(
            json_decode(file_get_contents($path), true),
            200,
            ['Cache-Control' => 'public, max-age=300'],
        );
    }

    /**
     * Serve a baked galaxy tile through Laravel so it inherits the CORS
     * middleware. Nginx serves `public/` directly and bypasses the Laravel
     * pipeline, which is why the raw `/galaxy-tiles/...` URLs were blocked
     * by the browser despite CORS being configured.
     *
     * The path is validated against a strict allow-list of tile shapes so
     * this route cannot be used to read arbitrary files from disk.
     */
    #[OA\Get(
        path: '/galaxy/tiles/{path}',
        summary: 'Serve a baked galaxy tile',
        description: 'Streams a binary tile file. Path must match v{N}/lod0.bin or v{N}/lod{1,2}/{key}.bin.',
        tags: ['Galaxy'],
        parameters: [
            new OA\Parameter(name: 'path', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Binary tile'),
            new OA\Response(response: 404, description: 'Tile not found'),
        ]
    )]
    public function tile(string $path): BinaryFileResponse|Response
    {
        if (! preg_match('#^v\d+/(lod0\.bin|lod[12]/[A-Za-z0-9_-]+\.bin)$#', $path)) {
            return response('Not found.', 404);
        }

        $full = public_path('galaxy-tiles/'.$path);
        if (! is_file($full)) {
            return response('Not found.', 404);
        }

        return response()->file($full, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
