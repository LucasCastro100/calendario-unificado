<?php

declare(strict_types=1);

namespace App\DTOs\Calendar;

/**
 * Participante de um evento. DTO aninhado do UnifiedEventDTO.
 */
final readonly class UnifiedAttendeeDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public bool $isOrganizer = false,
        public ?string $responseStatus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            name: (string) ($attributes['displayName'] ?? $attributes['name'] ?? ''),
            email: (string) ($attributes['emailAddress']['address'] ?? $attributes['email'] ?? ''),
            isOrganizer: (bool) ($attributes['isOrganizer'] ?? false),
            responseStatus: isset($attributes['responseStatus'])
                ? (is_array($attributes['responseStatus'])
                    ? strtolower((string) ($attributes['responseStatus']['response'] ?? ''))
                    : strtolower((string) $attributes['responseStatus']))
                : null,
        );
    }

    /**
     * @return array{email: string, name: string, isOrganizer: bool, responseStatus: ?string}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
            'isOrganizer' => $this->isOrganizer,
            'responseStatus' => $this->responseStatus,
        ];
    }
}
