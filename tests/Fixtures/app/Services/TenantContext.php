<?php

declare(strict_types=1);

namespace Fixture\Services;

class TenantContext
{
    private static ?int $id = null;

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function setCurrent(int $id): void
    {
        self::$id = $id;
    }
}
