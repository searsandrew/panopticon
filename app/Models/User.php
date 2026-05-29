<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'netsuite_managed_sales_rep_ids' => 'array',
            'netsuite_user_id' => 'integer',
            'password' => 'hashed',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function netsuiteSalesRepScopeIds(): array
    {
        return collect([$this->netsuite_user_id])
            ->merge(is_array($this->netsuite_managed_sales_rep_ids) ? $this->netsuite_managed_sales_rep_ids : [])
            ->filter(fn (mixed $salesRepId): bool => is_numeric($salesRepId) && (int) $salesRepId > 0)
            ->map(fn (mixed $salesRepId): int => (int) $salesRepId)
            ->unique()
            ->values()
            ->all();
    }

    public function canAccessNetSuiteSalesRep(mixed $salesRepId): bool
    {
        return is_numeric($salesRepId)
            && in_array((int) $salesRepId, $this->netsuiteSalesRepScopeIds(), true);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
