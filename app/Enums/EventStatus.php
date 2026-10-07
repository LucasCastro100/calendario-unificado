<?php

declare(strict_types=1);

namespace App\Enums;

enum EventStatus: string
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
