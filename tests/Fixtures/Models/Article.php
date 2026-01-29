<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Traits\RequiresApproval;

/**
 * Test model that uses RequiresApproval trait.
 *
 * @property int $id
 * @property string $title
 * @property string|null $content
 * @property int $user_id
 */
class Article extends Model
{
    use RequiresApproval;

    protected $guarded = [];

    /**
     * Actions that require approval.
     *
     * @var array<string>
     */
    protected static array $requiresApprovalFor = ['create', 'update', 'delete'];

    /**
     * Approval requirements per action.
     *
     * @var array<string, array<string, int>>
     */
    protected static array $approvalRequirements = [
        'create' => ['editor' => 1],
        'update' => ['editor' => 1],
        'delete' => ['admin' => 1],
    ];
}
