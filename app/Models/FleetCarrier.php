<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Cviebrock\EloquentSluggable\SluggableScopeHelpers;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetCarrier extends Model
{
    use HasFactory, Sluggable, SluggableScopeHelpers;

    /**
     * The table associated with the model.
     *
     * @var string - the table name
     */
    protected $table = 'fleet_carriers';

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
     * Get the system this fleet carrier is currently docked in.
     *
     * @return BelongsTo - the current system for this fleet carrier
     */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /**
     * Fetch the other_services attribute as an array.
     *
     * @return Attribute - the other_services attribute
     */
    protected function otherServices(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? explode(',', $value) : null
        );
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
                'source' => ['market_id', 'name'],
                'separator' => '-',
            ],
        ];
    }
}
