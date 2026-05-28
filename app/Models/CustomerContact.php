<?php

namespace App\Models;

use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable(['netsuite_customer_id', 'customer_account_number', 'name', 'normalized_name', 'last_used_at'])]
class CustomerContact extends Model implements Auditable
{
    /** @use HasFactory<CustomerContactFactory> */
    use AuditableTrait, HasFactory, HasUlids, SoftDeletes;

    public static function normalizeName(string $name): string
    {
        return Str::of($name)
            ->squish()
            ->lower()
            ->toString();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'netsuite_customer_id' => 'integer',
        ];
    }
}
