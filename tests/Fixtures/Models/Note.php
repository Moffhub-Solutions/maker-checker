<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $body
 * @property int|null $employee_id
 */
class Note extends Model
{
    protected $guarded = [];
}
