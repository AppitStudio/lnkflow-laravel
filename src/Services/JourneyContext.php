<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use Illuminate\Contracts\Session\Session;

final class JourneyContext
{
    public function __construct(private readonly Session $session) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $value = $this->session->get($this->key(), []);

        return is_array($value) ? $value : [];
    }

    public function visitorId(): ?string
    {
        $visitor = $this->all()['visitor_id'] ?? null;

        return is_string($visitor) && $visitor !== '' ? $visitor : null;
    }

    /** @param array<string, mixed> $state */
    public function replace(array $state): void
    {
        $this->session->put($this->key(), $state);
    }

    public function clear(): void
    {
        $this->session->forget($this->key());
    }

    /**
     * @param  array<string, mixed>  $explicit
     * @return array<string, mixed>
     */
    public function enrich(array $explicit): array
    {
        $state = $this->all();
        $context = array_filter([
            'website_id' => config('lnkflow.connections.'.config('lnkflow.default').'.website'),
            'visitor_id' => $state['visitor_id'] ?? null,
            'first_click_id' => $state['first_click_id'] ?? null,
            'click_id' => $state['last_click_id'] ?? null,
            'last_click_id' => $state['last_click_id'] ?? null,
            'promo_code' => $state['promo_code'] ?? null,
            'consent' => $state['consent'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$context, ...$explicit];
    }

    private function key(): string
    {
        return (string) config('lnkflow.journeys.session_key', '_lnkflow');
    }
}
