<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $label
 */
class Compensation extends Model
{
    protected $table = 'compensations';

    protected $guarded = [];
}
