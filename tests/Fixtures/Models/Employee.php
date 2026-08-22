<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Moffhub\MakerChecker\Traits\InterceptsRelationships;
use Moffhub\MakerChecker\Traits\RequiresApproval;

/**
 * Demonstrates transparent relationship interception.
 *
 * @property int $id
 * @property string $name
 * @property int|null $user_id
 */
class Employee extends Model
{
    use InterceptsRelationships;
    use RequiresApproval;

    protected $guarded = [];

    /**
     * Only relationship changes are gated here, not create/update/delete.
     *
     * @var array<string>
     */
    protected static array $requiresApprovalFor = [];

    /**
     * @var array<int|string, mixed>
     */
    protected static array $approvableRelations = [
        'compensations',
        'manager' => ['associate', 'dissociate'],
    ];

    public function compensations(): BelongsToMany
    {
        return $this->belongsToMany(
            Compensation::class,
            'compensation_employee',
            'employee_id',
            'compensation_id',
        )->withPivot('role');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
