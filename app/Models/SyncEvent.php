<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncEvent extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_NEW = 'completed_new';

    public const STATUS_NO_NEW = 'completed_no_new';

    public const STATUS_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status',
        'trigger',
        'imported_count',
        'days_synced',
        'report',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report' => 'array',
        ];
    }

    public function wasSuccessful(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_NO_NEW], true);
    }
}
