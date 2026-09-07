<?php

declare(strict_types=1);

namespace Mapin\Extract\Discovery;

final class Hasher
{
    public static function hashFile(string $absolutePath): string
    {
        return hash_file('xxh128', $absolutePath) ?: hash('sha1', (string) file_get_contents($absolutePath));
    }
}
