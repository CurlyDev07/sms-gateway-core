<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SimDailyStat extends Model
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
        'stat_date',
        'sent_count',
        'sent_chat_count',
        'sent_auto_reply_count',
        'sent_follow_up_count',
        'sent_blast_count',
        'failed_count',
        'inbound_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stat_date' => 'date',
    ];

    /**
     * Get the SIM this stat row belongs to.
     */
    public function sim()
    {
        return $this->belongsTo(Sim::class);
    }
}
