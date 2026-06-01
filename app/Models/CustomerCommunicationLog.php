<?php

namespace App\Models;

use Database\Factories\CustomerCommunicationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    'netsuite_customer_sales_rep_id',
    'netsuite_customer_pipeline_owner_id',
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

    public const STATUS_UPDATE_REQUESTED = 'update_requested';

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isUpdateRequested(): bool
    {
        return $this->status === self::STATUS_UPDATE_REQUESTED;
    }

    /**
     * @return array<int, string>
     */
    public static function visibleStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_UPDATE_REQUESTED,
        ];
    }

    /**
     * @param  Builder<CustomerCommunicationLog>  $query
     * @return Builder<CustomerCommunicationLog>
     */
    public function scopeVisibleToUsers(Builder $query): Builder
    {
        return $query->whereIn('status', self::visibleStatuses());
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
     * @return BelongsToMany<User, CustomerCommunicationLog>
     */
    public function readByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_communication_log_reads')
            ->withPivot('read_at')
            ->withTimestamps();
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
            'netsuite_customer_pipeline_owner_id' => 'integer',
            'netsuite_customer_sales_rep_id' => 'integer',
            'netsuite_sales_rep_id' => 'integer',
            'requires_follow_up' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }
}
