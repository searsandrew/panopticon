<?php

namespace App\Models;

use Database\Factories\CustomerCommunicationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable([
    'user_id',
    'netsuite_customer_id',
    'customer_account_number',
    'customer_name',
    'netsuite_sales_rep_id',
    'communication_type_id',
    'customer_contact_id',
    'contact_person_name',
    'contact_at',
    'status',
    'requires_follow_up',
    'submitted_at',
    'last_autosaved_at',
    'communication_block_type_id',
    'position',
    'body',
])]
class CustomerCommunicationLog extends Model implements Auditable
{
    /** @use HasFactory<CustomerCommunicationLogFactory> */
    use AuditableTrait, HasFactory, HasUlids, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $auditExclude = [
        'last_autosaved_at',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * @return BelongsTo<User, CustomerCommunicationLog>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CommunicationType, CustomerCommunicationLog>
     */
    public function communicationType(): BelongsTo
    {
        return $this->belongsTo(CommunicationType::class);
    }

    /**
     * @return BelongsTo<CustomerContact, CustomerCommunicationLog>
     */
    public function customerContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class);
    }

    /**
     * @return HasMany<CustomerCommunicationLogBlock>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(CustomerCommunicationLogBlock::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contact_at' => 'datetime',
            'last_autosaved_at' => 'datetime',
            'netsuite_customer_id' => 'integer',
            'netsuite_sales_rep_id' => 'integer',
            'requires_follow_up' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }
}
