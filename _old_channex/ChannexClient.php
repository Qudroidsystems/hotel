<?php

namespace App\Services\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Channex.io's API (https://docs.channex.io): the channel manager that connects the hotel to Booking.com,
 * Expedia, Airbnb, Agoda and the rest. The key goes in the user-api-key header.
 */
class ChannexClient
{
    public const BASES = [
        'production' => 'https://app.channex.io/api/v1',
        'staging'    => 'https://staging.channex.io/api/v1',
    ];

    public function enabled(): bool
    {
        return (bool) config('hotel.channels.channex.enabled');
    }

    /** Switched on, with a key and a property. */
    public function configured(): bool
    {
        return $this->enabled() && filled($this->key()) && filled($this->propertyId());
    }

    public function propertyId(): ?string
    {
        return config('hotel.channels.channex.property_id') ?: null;
    }

    public function base(): string
    {
        return self::BASES[config('hotel.channels.channex.environment')] ?? self::BASES['production'];
    }

    /** The property, to check the key and id work. */
    public function property(): array
    {
        return (array) ($this->get('/properties/' . $this->propertyId())['data'] ?? []);
    }

    /** The property's room types: id => title. */
    public function roomTypes(): array
    {
        return collect($this->get('/room_types', ['filter[property_id]' => $this->propertyId(), 'pagination[limit]' => 100])['data'] ?? [])
            ->mapWithKeys(fn ($r) => [$r['id'] => data_get($r, 'attributes.title', $r['id'])])->all();
    }

    /** The property's rate plans: id => [title, room_type_id]. */
    public function ratePlans(): array
    {
        return collect($this->get('/rate_plans', ['filter[property_id]' => $this->propertyId(), 'pagination[limit]' => 100])['data'] ?? [])
            ->mapWithKeys(fn ($r) => [$r['id'] => [
                'title' => data_get($r, 'attributes.title', $r['id']),
                'room_type_id' => data_get($r, 'relationships.room_type.data.id', data_get($r, 'attributes.room_type_id')),
            ]])->all();
    }

    /** @param array<int, array> $values {property_id, room_type_id, date_from, date_to, availability} */
    public function availability(array $values): array
    {
        return $this->post('/availability', ['values' => $values]);
    }

    /** @param array<int, array> $values {property_id, rate_plan_id, date_from, date_to, rate, min_stay_arrival, stop_sell ...} */
    public function restrictions(array $values): array
    {
        return $this->post('/restrictions', ['values' => $values]);
    }

    /** Booking revisions not acknowledged yet, oldest first. */
    public function feed(): array
    {
        return $this->get('/booking_revisions/feed', ['filter[property_id]' => $this->propertyId(), 'order[inserted_at]' => 'asc'])['data'] ?? [];
    }

    public function revision(string $id): array
    {
        return $this->get('/booking_revisions/' . $id)['data'] ?? [];
    }

    /** Tell Channex a revision is saved here, so it stops sending it. */
    public function ack(string $id): void
    {
        $this->post('/booking_revisions/' . $id . '/ack', []);
    }

    // ------------------------------------------------------------------

    private function key(): ?string
    {
        return config('hotel.channels.channex.api_key') ?: null;
    }

    private function get(string $path, array $query = []): array
    {
        return $this->send(fn ($http) => $http->get($this->base() . $path, $query));
    }

    private function post(string $path, array $body): array
    {
        return $this->send(fn ($http) => $http->post($this->base() . $path, $body));
    }

    private function send(callable $call): array
    {
        if (! $this->key()) {
            throw new ChannelException('Add the Channex API key under Settings → Channel Manager first.');
        }
        try {
            /** @var Response $res */
            $res = $call(Http::withHeaders(['user-api-key' => $this->key()])->acceptJson()->asJson()->timeout(30)->connectTimeout(10));
        } catch (ConnectionException|RequestException $e) {
            throw new ChannelException('Couldn’t reach Channex: ' . $e->getMessage());
        }
        if ($res->status() === 401) {
            throw new ChannelException('Channex refused the API key. Check it under Settings → Channel Manager.');
        }
        if ($res->failed()) {
            $why = collect(data_get($res->json(), 'errors.details', []))->flatten()->implode(' ')
                ?: data_get($res->json(), 'errors.title') ?: ('HTTP ' . $res->status());
            throw new ChannelException('Channex: ' . str($why)->limit(300));
        }

        return (array) $res->json();
    }
}
