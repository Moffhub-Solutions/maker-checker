<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Enums;

enum RequestType: string
{
    case CREATE = 'create';

    case UPDATE = 'update';

    case DELETE = 'delete';

    case EXECUTE = 'execute';

    /**
     * A change to an Eloquent relationship (pivot attach/detach/sync/toggle/
     * updateExistingPivot, or belongsTo associate/dissociate).
     */
    case RELATION = 'relation';

    public function display(): string
    {
        return match ($this) {
            self::CREATE => 'Create',
            self::UPDATE => 'Update',
            self::DELETE => 'Delete',
            self::EXECUTE => 'Execute',
            self::RELATION => 'Relation',
        };
    }

}
