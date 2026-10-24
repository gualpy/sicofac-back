<?php

namespace App\Models;

use App\Enums\CompanyMembershipStatus;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'bool',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function belongsToCompany(int $companyId): bool
    {
        return $this->companies()
            ->whereKey($companyId)
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->exists();
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function hasCompanyRole(int $companyId, array $roles): bool
    {
        return $this->companies()
            ->whereKey($companyId)
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->wherePivotIn('role', $roles)
            ->exists();
    }
}
