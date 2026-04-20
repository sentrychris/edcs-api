<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class SearchSystemRequest extends ApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string',
            'withInformation' => 'sometimes|integer|max:1',
            'withBodies' => 'sometimes|integer|max:1',
            'withStations' => 'sometimes|integer|max:1',
            'withFleetCarriers' => 'sometimes|integer|max:1',
            'exactSearch' => 'sometimes|integer|max:1',
            'limit' => 'sometimes|int|max:100',
        ];
    }
}
