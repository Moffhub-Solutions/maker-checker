<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Moffhub\MakerChecker\Database\Factories\MakerCheckerApprovalNoteFactory;

/**
 * @property int $id
 * @property int $request_id
 * @property string $user_type
 * @property int $user_id
 * @property string $action
 * @property string $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property MakerCheckerRequest $request
 * @property Model $user
 */
class MakerCheckerApprovalNote extends Model
{
    /** @use HasFactory<MakerCheckerApprovalNoteFactory> */
    use HasFactory;

    protected $table = 'maker_checker_approval_notes';

    protected $guarded = ['id'];

    protected static function newFactory(): MakerCheckerApprovalNoteFactory
    {
        return MakerCheckerApprovalNoteFactory::new();
    }

    /**
     * @return BelongsTo<MakerCheckerRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(MakerCheckerRequest::class, 'request_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }
}
