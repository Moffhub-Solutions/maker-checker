<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Enums;

enum Hooks: string
{
    case POST_INITIATE = 'post_initiate';

    case PRE_APPROVAL = 'pre_approval';

    case POST_APPROVAL = 'post_approval';

    case PRE_REJECTION = 'pre_rejection';

    case POST_REJECTION = 'post_rejection';

    case ON_FAILURE = 'on_failure';

    public static function executableHooks(): array
    {
        return [
            self::PRE_APPROVAL,
            self::POST_APPROVAL,
            self::PRE_REJECTION,
            self::POST_REJECTION,
            self::ON_FAILURE,
        ];
    }

    public function hookMethods(): string
    {
        return match ($this) {
            self::PRE_APPROVAL => 'beforeApproval',
            self::POST_APPROVAL => 'afterApproval',
            self::PRE_REJECTION => 'beforeRejection',
            self::POST_REJECTION => 'afterRejection',
            self::ON_FAILURE => 'onFailure',
            default => '',
        };
    }
}
