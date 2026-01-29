<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moffhub\MakerChecker\Contracts\MakerCheckerConfigurable;
use Moffhub\MakerChecker\Enums\RequestType;

/**
 * @property int $id
 * @property string $title
 * @property string $content
 * @property int $user_id
 * @property int|null $team_id
 */
class Post extends Model implements MakerCheckerConfigurable
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function makerCheckerApprovals(): array
    {
        return [
            'create' => ['admin' => 1],
            'update' => ['admin' => 1, 'editor' => 1],
            'delete' => ['admin' => 2],
        ];
    }

    public static function makerCheckerUniqueFields(): array
    {
        return [
            'create' => ['title'],
            'update' => ['title'],
        ];
    }

    public static function requiresMakerChecker(RequestType $action): bool
    {
        return true;
    }

    public static function makerCheckerDescription(RequestType $action, array $payload): string
    {
        $title = $payload['title'] ?? 'unknown';

        return match ($action) {
            RequestType::CREATE => "Create post: {$title}",
            RequestType::UPDATE => "Update post: {$title}",
            RequestType::DELETE => 'Delete post',
            RequestType::EXECUTE => 'Execute action on post',
        };
    }
}
