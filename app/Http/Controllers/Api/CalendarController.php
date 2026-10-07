<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OauthProvider;
use App\Http\Controllers\Controller;
use App\Http\Middleware\UniversalRefreshTokenMiddleware;
use App\Http\Requests\Calendar\DestroyCalendarEventRequest;
use App\Http\Requests\Calendar\GetEventsRequest;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Requests\Calendar\UpdateCalendarEventRequest;
use App\Http\Resources\CalendarEventResource;
use App\Services\Calendar\UnifiedCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Controller magro: valida (FormRequest) → delega (Service) → formata (Resource).
 * Nenhuma regra de negócio aqui.
 */
final class CalendarController extends Controller
{
    public function __construct(
        private readonly UnifiedCalendarService $calendar,
    ) {}

    /**
     * Leitura unificada: Google + Microsoft em paralelo via Http::pool.
     *
     * Falha de um provider não quebra a resposta — ele aparece em
     * `meta.failed_providers` e o cache local cobre o buraco.
     */
    public function index(GetEventsRequest $request): AnonymousResourceCollection
    {
        $range = $request->toRange();
        $providers = $request->providers();
        $search = $request->search();

        $events = $this->calendar->events(
            $request->user(),
            $range,
            $providers,
            UniversalRefreshTokenMiddleware::staleProviders($request),
        );

        if ($search !== null) {
            $needle = mb_strtolower($search);
            $events = $events->filter(
                static fn ($event): bool => str_contains(mb_strtolower($event->title), $needle)
                    || ($event->location !== null && str_contains(mb_strtolower($event->location), $needle))
            )->values();
        }

        return CalendarEventResource::collection($events)
            ->additional([
                'meta' => [
                    'range' => $range->toArray(),
                    'total' => $events->count(),
                    'search' => $search,
                    'requested_providers' => array_map(
                        static fn (OauthProvider $provider): string => $provider->value,
                        // Sem filtro explícito, todos os providers conectados
                        // entram na resposta — `null` viraria `[]` e a UI
                        // mostraria "nenhum provider solicitado".
                        $providers ?? OauthProvider::cases(),
                    ),
                    // O frontend avisa "Google não respondeu" sem esconder os
                    // eventos que vieram do Microsoft.
                    'failed_providers' => $this->calendar->failedProviders(),
                ],
            ]);
    }

    /**
     * Leitura de um provider específico. Aqui o erro é propagado: quem pediu
     * aquele provider explicitamente precisa saber que ele falhou.
     */
    public function show(GetEventsRequest $request, string $provider): JsonResponse
    {
        $providerEnum = OauthProvider::fromInput($provider);
        $range = $request->toRange();

        $events = $this->calendar->eventsFromProvider($request->user(), $providerEnum, $range);

        return response()->json([
            'data' => $events->map(static fn ($event): array => $event->toArray())->all(),
            'meta' => [
                'range' => $range->toArray(),
                'total' => $events->count(),
                'provider' => $providerEnum->value,
            ],
        ]);
    }

    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        $event = $this->calendar->createEvent($request->user(), $request->provider(), $request->validated());

        return (new CalendarEventResource($event))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCalendarEventRequest $request): JsonResponse
    {
        $event = $this->calendar->updateEvent(
            $request->user(),
            $request->provider(),
            $request->eventId(),
            $request->validated(),
        );

        return (new CalendarEventResource($event))->response();
    }

    public function destroy(DestroyCalendarEventRequest $request): JsonResponse
    {
        $this->calendar->deleteEvent($request->user(), $request->provider(), $request->eventId());

        return response()->json(status: 204);
    }

    /**
     * Lista os calendários do usuário no provider indicado.
     */
    public function calendars(Request $request, string $provider): JsonResponse
    {
        $providerEnum = OauthProvider::fromInput($provider);

        $calendars = $this->calendar->calendars($request->user(), $providerEnum);

        return response()->json([
            'data' => $calendars->all(),
            'meta' => ['provider' => $providerEnum->value, 'total' => $calendars->count()],
        ]);
    }
}
