<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CompanyChatAppIntegration extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'chatapp_company_id',
        'chatapp_company_uuid',
        'chatapp_inbound_url',
        'chatapp_delivery_status_url',
        'chatapp_tenant_key',
        'chatapp_inbound_secret_encrypted',
        'status',
        'outbound_rotated_at',
        'inbound_rotated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'outbound_rotated_at' => 'datetime',
        'inbound_rotated_at' => 'datetime',
    ];

    /**
     * Get the owning gateway company.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Encrypt and assign the inbound relay signing secret.
     *
     * @param string $secret
     * @return void
     */
    public function setInboundSecret(string $secret): void
    {
        $this->chatapp_inbound_secret_encrypted = Crypt::encryptString($secret);
    }

    /**
     * Decrypt the inbound relay signing secret.
     *
     * @return string
     */
    public function inboundSecret(): string
    {
        return Crypt::decryptString((string) $this->chatapp_inbound_secret_encrypted);
    }
}
