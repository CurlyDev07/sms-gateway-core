<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Modem extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'device_name',
        'vendor',
        'model',
        'chipset',
        'serial_number',
        'usb_path',
        'control_port',
        'status',
        'last_seen_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get SIMs attached to this modem.
     */
    public function sims()
    {
        return $this->hasMany(Sim::class);
    }
}
