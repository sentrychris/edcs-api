<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id64' => $this->id64,
            'name' => $this->name,
            'coords' => ['x' => $this->coords_x, 'y' => $this->coords_y, 'z' => $this->coords_z],
            'information' => new SystemInformationResource($this->whenLoaded('information')),
            'bodies' => $this->whenLoaded('bodies'),
            'stations' => SystemStationResource::collection($this->whenLoaded('stations')),
            'fleet_carriers' => FleetCarrierResource::collection($this->whenLoaded('fleetCarriers')),
            'updated_at' => $this->updated_at,
            'slug' => $this->slug,
        ];
    }
}
