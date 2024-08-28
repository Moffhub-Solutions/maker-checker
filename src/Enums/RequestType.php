<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Enums;

enum RequestType: string
{
    case CREATE = 'create';

    case UPDATE = 'update';

    case DELETE = 'delete';

    case EXECUTE = 'execute';

    public function display(): string
    {
        return match ($this) {
            self::CREATE => 'Create',
            self::UPDATE => 'Update',
            self::DELETE => 'Delete',
            self::EXECUTE => 'Execute',
        };
    }

}
