<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Collection;

/**
 * Textos del asistente de compras. Son plantillas fijas y breves que se completan con los
 * resultados (decisión y datos del catálogo): el modelo de decisiones no redacta texto.
 * No se nombra el modelo ante el estudiante; la herramienta se documenta para auditoría.
 */
final class SmartShoppingCopy
{
    /** @return array{title: string, body: list<string>, clarification: ?string} */
    public static function for(string $outcome, array $base, ?array $recommended, Collection $scored): array
    {
        $budget = $base['budget'];

        $copy = match ($outcome) {
            'recommendation' => [
                'title' => 'Encontré lo que buscás',
                'body' => [$recommended['product']->name.' es la mejor opción entre comercios verificados.'],
            ],
            'over_budget' => [
                'title' => 'Lo encontré, pero se pasa de tu presupuesto',
                'body' => ['La opción más económica cuesta $'.PayPalCheckoutService::money(
                    (int) $scored->filter(fn ($c) => $c['eligible'])->min('price_cents')
                ).'.'],
            ],
            'confirm_choice' => [
                'title' => '¿Te sirve alguna de estas?',
                'body' => ['Se parecen a lo que pediste, pero no estoy seguro. Si no, contame más detalles.'],
            ],
            'no_match' => [
                'title' => 'No encontré eso en comercios verificados',
                'body' => ['Probá con otras palabras o algo más general.'],
            ],
            'needs_clarification' => [
                'title' => 'Contame un poco más',
                'body' => ['¿Qué producto necesitás? Por ejemplo: «calculadora científica para Cálculo I».'],
            ],
            default => [
                'title' => 'Te ayudo a comprar en LaU',
                'body' => ['Decime qué necesitás y, si querés, tu presupuesto máximo.'],
            ],
        };

        if ($budget['unclear_amounts'] !== []) {
            $copy['body'][] = 'No tomé '.implode(', ', $budget['unclear_amounts']).' como presupuesto porque no quedó claro.';
        }

        return $copy + ['clarification' => null];
    }
}
