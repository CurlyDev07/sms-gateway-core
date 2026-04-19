<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class ApiClient extends Model
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
        'name',
        'api_key',
        'api_secret',
        'status',
    ];

    /**
     * Get the owning company.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Hash api_secret when set from plain text.
     *
     * @param string $value
     * @return void
     */
    public function setApiSecretAttribute(string $value): void
    {
        if ($value === '') {
            $this->attributes['api_secret'] = $value;
            return;
        }

        $isHash = (password_get_info($value)['algo'] ?? 0) !== 0;
        $this->attributes['api_secret'] = $isHash ? $value : Hash::make($value);
    }
}
