<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SimHealthLog extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'sim_id',
        'status',
        'error_message',
        'logged_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'logged_at' => 'datetime',
    ];

    /**
     * Get the SIM this health log belongs to.
     */
    public function sim()
    {
        return $this->belongsTo(Sim::class);
    }
}
