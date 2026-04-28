<?php

namespace App\Http\Controllers;

use App\Console\Commands\BuildDataDumpCommand;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataDownloadController extends Controller
{
    /**
     * Dump manifest.
     */
    #[OA\Get(
        path: '/downloads/manifest',
        summary: 'Get availability metadata for all data dumps',
        description: 'Returns an object keyed by dump type. Each entry reports whether the file has been generated, its compressed size in bytes, and the ISO 8601 timestamp of the last build. Use this to display freshness information in a UI before triggering a download.',
        tags: ['Data Downloads'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dump manifest',
                content: new OA\JsonContent(
                    type: 'object',
                    example: [
                        'systems' => ['available' => true, 'size' => 524288000, 'built_at' => '2024-01-15T02:00:00+00:00'],
                        'populated-systems' => ['available' => true, 'size' => 104857600, 'built_at' => '2024-01-15T02:00:00+00:00'],
                    ],
                    additionalProperties: new OA\AdditionalProperties(
                        properties: [
                            new OA\Property(property: 'available', type: 'boolean', description: 'Whether the dump file has been generated'),
                            new OA\Property(property: 'size', type: 'integer', nullable: true, description: 'Compressed file size in bytes, or null if not yet generated'),
                            new OA\Property(property: 'built_at', type: 'string', format: 'date-time', nullable: true, description: 'ISO 8601 timestamp of the last successful build, or null if not yet generated'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function manifest(): JsonResponse
    {
        $manifest = [];

        foreach (BuildDataDumpCommand::TYPES as $type) {
            $path = BuildDataDumpCommand::dumpPath($type);
            $exists = file_exists($path);

            $manifest[$type] = [
                'available' => $exists,
                'size' => $exists ? filesize($path) : null,
                'built_at' => $exists ? Carbon::createFromTimestamp(filemtime($path))->toIso8601String() : null,
            ];
        }

        return response()->json($manifest);
    }

    /**
     * Download a dump file.
     */
    #[OA\Get(
        path: '/downloads/{type}',
        summary: 'Download a bulk data dump',
        description: 'Streams a pre-generated gzip-compressed JSON array for the requested dump type. Files are rebuilt on a nightly schedule (large dumps) or every six hours (recent-window dumps). Returns 503 if the dump has not yet been generated.',
        tags: ['Data Downloads'],
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'path',
                required: true,
                description: 'The dump type to download',
                schema: new OA\Schema(
                    type: 'string',
                    enum: [
                        'systems',
                        'populated-systems',
                        'systems-recent',
                        'bodies',
                        'bodies-recent',
                        'stations',
                        'stations-recent',
                        'carriers',
                        'carriers-recent',
                    ]
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gzip-compressed JSON array download',
                content: new OA\MediaType(mediaType: 'application/gzip', schema: new OA\Schema(type: 'string', format: 'binary'))
            ),
            new OA\Response(response: 404, description: 'Unknown dump type'),
            new OA\Response(response: 503, description: 'Dump not yet generated — check the manifest endpoint for availability'),
        ]
    )]
    public function download(string $type): BinaryFileResponse|Response
    {
        if (! in_array($type, BuildDataDumpCommand::TYPES, true)) {
            return response([], 404);
        }

        $path = BuildDataDumpCommand::dumpPath($type);

        if (! file_exists($path)) {
            return response(['message' => 'This dump has not been generated yet.'], 503);
        }

        return response()->download($path, "{$type}.json.gz", [
            'Content-Type' => 'application/gzip',
        ]);
    }
}
