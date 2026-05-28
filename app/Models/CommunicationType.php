<?php

namespace App\Models;

use Database\Factories\CommunicationTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable(['name', 'slug', 'sort_order', 'is_active', 'is_system'])]
class CommunicationType extends Model implements Auditable
{
    /** @use HasFactory<CommunicationTypeFactory> */
    use AuditableTrait, HasFactory, HasUlids;

    public const PHONE = 'phone';

    /**
     * @param  Builder<CommunicationType>  $query
     * @return Builder<CommunicationType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
