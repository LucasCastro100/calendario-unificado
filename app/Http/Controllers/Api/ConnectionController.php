<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\Calendar\ConnectionStatusDTO;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado das conexões por provider. Nunca inclui tokens — a serialização vem
 * do DTO CalendarConnection, que não tem campo para eles.
 */
final class ConnectionController extends Controller
{
    /**
     * Um item por provider, conectado ou não, para a UI renderizar os dois
     * cards sem precisar cruzar com a lista de providers suportados.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $connections = $user->calendarConnections()->get();

        $items = $user->calendarConnectionDtos();

        return response()->json([
            'data' => array_map(
                static fn (ConnectionStatusDTO $dto): array => $dto->toArray(),
                $items,
            ),
            'meta' => [
                'total' => count($items),
                'connected' => $connections->filter(
                    static fn ($connection): bool => $connection->isUsable(),
                )->count(),
            ],
        ]);
    }
}
