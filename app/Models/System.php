<?php

namespace App\Models;

use App\Traits\HasQueryFilter;
use Cviebrock\EloquentSluggable\Sluggable;
use Cviebrock\EloquentSluggable\SluggableScopeHelpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class System extends Model
{
    use HasFactory, HasQueryFilter, Sluggable, SluggableScopeHelpers;

    /**
     * The table associated with the model.
     *
     * @var string - the table name
     */
    protected $table = 'systems';

    /**
     * Guarded attributes that should not be mass assignable.
     *
     * @var array - the guarded attributes
     */
    protected $guarded = [];

    /**
     * Whether or not `created_at` and updated_at should be handled automatically.
     *
     * @var bool - whether or not the model should be timestamped
     */
    public $timestamps = false;

    /**
     * Get information related to the system.
     *
     * This will retrieve the information relation for the system which includes stuff such
     * as government, allegiance, economy, population etc.
     *
     * @return HasOne - the information for the system
     */
    public function information(): HasOne
    {
        return $this->hasOne(SystemInformation::class);
    }

    /**
     * Get bodies related to the system.
     *
     * This will retrieve the celestial bodies in the system.
     *
     * @return HasMany - the bodies in the system
     */
    public function bodies(): HasMany
    {
        return $this->hasMany(SystemBody::class);
    }

    /**
     * Get stations related to the system.
     *
     * This will retrieve the stations in the system.
     *
     * @return HasMany - the stations in the system
     */
    public function stations(): HasMany
    {
        return $this->hasMany(SystemStation::class);
    }

    /**
     * Get fleet carriers currently docked in the system.
     *
     * Fleet carriers are mobile — this relation reflects the carriers EDSM
     * last reported as present in this system. A carrier's `system_id` is
     * overwritten when it shows up elsewhere.
     *
     * @return HasMany - the fleet carriers currently in the system
     */
    public function fleetCarriers(): HasMany
    {
        return $this->hasMany(FleetCarrier::class);
    }

    /**
     * Add a query filter scope to filter systems by name.
     *
     * This scope also allows for exact search or `like` search based on the passed options.
     *
     * @param  Builder  $builder  - the query builder
     * @param  array  $options  - the filter options including the search term
     * @param  bool  $exact  - whether or not to use exact search or `like` search
     * @return Builder - the query builder
     */
    public function scopeFilter(Builder $builder, array $options, bool $exact): Builder
    {
        if (! empty($options['search'])) {
            $builder->search($options['search']);
        }

        return $this->buildFilterQuery($builder, $options, [
            'name',
        ], $exact);
    }

    /**
     * Search for systems by distance.
     *
     * Uses the indexed coords_x/y/z generated columns for a bounding-box
     * pre-filter so MySQL can use the compound index instead of a full table
     * scan. HAVING then refines to the exact sphere.
     *
     * @param  array{x: float, y: float, z: float}  $coords  - the origin coordinates
     * @param  float  $distance  - the search radius in light years
     */
    public static function findNearest(array $coords, float $distance)
    {
        $selectRaw = <<<'SQL'
            id,
            id64,
            name,
            coords_x,
            coords_y,
            coords_z,
            slug,
            updated_at,
            SQRT(
                POW(coords_x - ?, 2) +
                POW(coords_y - ?, 2) +
                POW(coords_z - ?, 2)
            ) AS distance
        SQL;

        return self::selectRaw($selectRaw, [$coords['x'], $coords['y'], $coords['z']])
            ->whereBetween('coords_x', [$coords['x'] - $distance, $coords['x'] + $distance])
            ->whereBetween('coords_y', [$coords['y'] - $distance, $coords['y'] + $distance])
            ->whereBetween('coords_z', [$coords['z'] - $distance, $coords['z'] + $distance])
            ->havingRaw('distance <= ?', [$distance])
            ->orderByRaw('distance ASC');
    }

    /**
     * Find all systems reachable within a given jump range for route-finding.
     *
     * Returns only the columns needed by the A* algorithm (id, coords_x/y/z),
     * with no LIMIT so the full reachable neighbourhood is returned. Uses the
     * same indexed bounding-box pre-filter as findNearest.
     *
     * @param  array{x: float, y: float, z: float}  $coords  - the origin coordinates
     * @param  float  $jumpRange  - the maximum reachable distance in light years
     * @return Collection<int, object{id: int, coords_x: float, coords_y: float, coords_z: float}>
     */
    public static function findNearestForRoute(array $coords, float $jumpRange): Collection
    {
        return self::select(['id', 'coords_x', 'coords_y', 'coords_z'])
            ->whereBetween('coords_x', [$coords['x'] - $jumpRange, $coords['x'] + $jumpRange])
            ->whereBetween('coords_y', [$coords['y'] - $jumpRange, $coords['y'] + $jumpRange])
            ->whereBetween('coords_z', [$coords['z'] - $jumpRange, $coords['z'] + $jumpRange])
            ->whereRaw(
                'SQRT(POW(coords_x - ?, 2) + POW(coords_y - ?, 2) + POW(coords_z - ?, 2)) <= ?',
                [$coords['x'], $coords['y'], $coords['z'], $jumpRange],
            )
            ->get();
    }

    /**
     * Configure the URL slug.
     *
     * @return array - the configuration for the slug
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => ['id64', 'name'],
                'separator' => '-',
            ],
        ];
    }

    /**
     * Get the cache key for system related attributes.
     *
     * @param  string  $type  - the attribute type
     * @return string - the cache key
     */
    private function getAttributeCacheKey(string $type)
    {
        return "system_{$this->id64}_{$type}";
    }
}
