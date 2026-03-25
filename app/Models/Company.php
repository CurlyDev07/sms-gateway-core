<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'code',
        'status',
        'timezone',
    ];

    /**
     * Get the SIM records for the company.
     */
    public function sims()
    {
        return $this->hasMany(Sim::class);
    }

    /**
     * Get the outbound messages for the company.
     */
    public function outboundMessages()
    {
        return $this->hasMany(OutboundMessage::class);
    }

    /**
     * Get the inbound messages for the company.
     */
    public function inboundMessages()
    {
        return $this->hasMany(InboundMessage::class);
    }

    /**
     * Get sticky customer-SIM assignments for the company.
     */
    public function customerSimAssignments()
    {
        return $this->hasMany(CustomerSimAssignment::class);
    }

    /**
     * Get API clients registered to the company.
     */
    public function apiClients()
    {
        return $this->hasMany(ApiClient::class);
    }
}
