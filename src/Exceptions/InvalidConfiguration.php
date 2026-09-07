<?php

declare(strict_types=1);

namespace Symphoria\Apex\Exceptions;

use InvalidArgumentException;

final class InvalidConfiguration extends InvalidArgumentException
{
    public static function modelMustExtend(mixed $given, string $expected): self
    {
        return new self(sprintf(
            'The configured model [%s] must extend [%s]. Update config/package-template.php.',
            is_string($given) ? $given : get_debug_type($given),
            $expected,
        ));
    }

    public static function missingTableName(string $key): self
    {
        return new self(sprintf(
            'No table name is configured for [%s]. Add it to config/package-template.php.',
            $key,
        ));
    }
}
