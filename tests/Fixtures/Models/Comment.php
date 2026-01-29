<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Traits\RequiresApproval;

/**
 * Test model that uses RequiresApproval trait without explicit requirements.
 * This model relies on ConfigResolver for approval requirements.
 *
 * @property int $id
 * @property string $body
 * @property int $user_id
 * @property int $post_id
 */
class Comment extends Model
{
    use RequiresApproval;

    protected $guarded = [];
}
