<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commander extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string - the table name
     */
    protected $table = 'commanders';

    /**
     * Guarded attributes that should not be mass assignable.
     *
     * @var array - the guarded attributes
     */
    protected $guarded = [];

    /**
     * Get the user that owns the commander.
     *
     * @return BelongsTo - the user that owns the commander
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the commander's last known system (most recent CAPI report).
     *
     * @return BelongsTo - the last system the commander was seen in
     */
    public function lastSystem(): BelongsTo
    {
        return $this->belongsTo(System::class, 'last_system_id64', 'id64');
    }
}
