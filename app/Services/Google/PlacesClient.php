<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Places API (New) — Text Search. Solo se usa desde el servidor (comando para ubicar campus).
 * La clave nunca se registra ni se devuelve.
 */
final class PlacesClient
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    public function isConfigured(): bool
    {
        return (bool) config('services.google.server_key');
    }

    /**
     * @return list<array{id: string, name: string, address: string, lat: float, lng: float, types: list<string>}>
     */
    public function search(string $query, int $max = 5): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)
                ->withHeaders([
                    'X-Goog-Api-Key' => (string) config('services.google.server_key'),
                    'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.types',
                ])
                ->post(self::ENDPOINT, [
                    'textQuery' => $query,
                    'languageCode' => 'es',
                    'regionCode' => 'SV',
                    'maxResultCount' => $max,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('No se pudo conectar con Google Places.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Google Places respondió HTTP '.$response->status().': '.($response->json('error.status') ?? 'error'));
        }

        return collect($response->json('places') ?? [])
            ->filter(fn ($p) => isset($p['id'], $p['location']['latitude'], $p['location']['longitude']))
            ->map(fn ($p) => [
                'id' => (string) $p['id'],
                'name' => (string) ($p['displayName']['text'] ?? ''),
                'address' => (string) ($p['formattedAddress'] ?? ''),
                'lat' => (float) $p['location']['latitude'],
                'lng' => (float) $p['location']['longitude'],
                'types' => array_values((array) ($p['types'] ?? [])),
            ])
            ->values()->all();
    }
}
