<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetCarrierResource extends JsonResource
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
            'market_id' => $this->market_id,
            'name' => $this->name,
            'system' => new SystemResource($this->whenLoaded('system')),
            'distance_to_arrival' => $this->distance_to_arrival,
            'allegiance' => $this->allegiance,
            'government' => $this->government,
            'economy' => $this->economy,
            'second_economy' => $this->second_economy,
            'has_market' => $this->has_market === 1 ? true : false,
            'has_shipyard' => $this->has_shipyard === 1 ? true : false,
            'has_outfitting' => $this->has_outfitting === 1 ? true : false,
            'other_services' => $this->other_services,
            'last_updated' => [
                'information' => $this->information_last_updated,
                'market' => $this->market_last_updated,
                'shipyard' => $this->shipyard_last_updated,
                'outfitting' => $this->outfitting_last_updated,
            ],
            'slug' => $this->slug,
        ];
    }
}
