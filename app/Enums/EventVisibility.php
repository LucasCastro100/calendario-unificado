<?php

declare(strict_types=1);

namespace App\Enums;

enum EventVisibility: string
{
    case Default = 'default';
    case Public = 'public';
    case Private = 'private';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
