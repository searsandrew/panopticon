<?php

namespace App\Models;

use Database\Factories\CustomerCommunicationLogBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable(['customer_communication_log_id', 'communication_block_type_id', 'position', 'body'])]
class CustomerCommunicationLogBlock extends Model implements Auditable
{
    /** @use HasFactory<CustomerCommunicationLogBlockFactory> */
    use AuditableTrait, HasFactory, HasUlids, SoftDeletes;

    /**
     * @return BelongsTo<CustomerCommunicationLog, CustomerCommunicationLogBlock>
     */
    public function log(): BelongsTo
    {
        return $this->belongsTo(CustomerCommunicationLog::class, 'customer_communication_log_id');
    }

    /**
     * @return BelongsTo<CommunicationBlockType, CustomerCommunicationLogBlock>
     */
    public function blockType(): BelongsTo
    {
        return $this->belongsTo(CommunicationBlockType::class, 'communication_block_type_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
