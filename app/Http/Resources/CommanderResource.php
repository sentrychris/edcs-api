<?php

namespace App\Http\Resources;

use App\Models\System;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommanderResource extends JsonResource
{
    private bool $includeAuth = false;

    /**
     * Constructor
     */
    public function __construct(mixed $resource, bool $includeAuth = false)
    {
        if ($includeAuth) {
            $this->includeAuth = true;
        }

        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = ['name' => $this->cmdr_name];

        $lastSystem = $this->whenLoaded('lastSystem');
        if ($lastSystem instanceof System) {
            $resource['last_system'] = [
                'name' => $lastSystem->name,
                'slug' => $lastSystem->slug,
            ];
        }

        if ($this->includeAuth) {
            $resource['api'] = [
                'edsm' => $this->edsm_api_key,
                'inara' => $this->inara_api_key,
            ];
        }

        return $resource;
    }
}
