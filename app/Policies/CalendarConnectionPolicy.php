<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CalendarConnection;
use App\Models\User;

/**
 * Anti-IDOR. Toda conexão pertence a exatamente um usuário — não há wildcard,
 * não há `viewAny` liberado e não há papéis: a posse é estrita.
 */
final class CalendarConnectionPolicy
{
    public function view(User $user, CalendarConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    /**
     * Escrita exige posse **e** escopo de escrita concedido no OAuth.
     */
    public function update(User $user, CalendarConnection $connection): bool
    {
        return $user->id === $connection->user_id
            && $connection->isUsable()
            && $connection->canWrite();
    }

    public function delete(User $user, CalendarConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }
}
