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
                'body' => ['Se parecen a lo que buscás, pero no estoy seguro. Describí el producto para afinar la búsqueda.'],
            ],
            'no_match' => [
                'title' => 'No encontré eso en comercios verificados',
                'body' => ['Probá con otras palabras o algo más general.'],
            ],
            'needs_clarification' => [
                'title' => 'Describí el producto que necesitás',
                'body' => ['Por ejemplo: «calculadora científica para Cálculo I, máximo $25».'],
            ],
            default => [
                'title' => 'Buscá un producto',
                'body' => ['Escribí qué necesitás y, si querés, tu presupuesto máximo.'],
            ],
        };

        if ($budget['unclear_amounts'] !== []) {
            $copy['body'][] = 'No tomé '.implode(', ', $budget['unclear_amounts']).' como presupuesto porque no quedó claro.';
        }

        return $copy + ['clarification' => null];
    }

    /**
     * Razones de una recomendación, calculadas con datos del catálogo y las decisiones tomadas.
     *
     * @return list<string>
     */
    public static function reasons(array $candidate, ?array $recommended, int $evaluated, ?string $budget): array
    {
        $reasons = [];
        $isTop = $recommended !== null && $recommended['product']->id === $candidate['product']->id;

        if ($isTop) {
            $reasons[] = $evaluated > 1
                ? "Es la que mejor coincide entre {$evaluated} productos de comercios verificados."
                : 'Es el producto de comercios verificados que coincide con tu búsqueda.';
        } elseif ($candidate['eligible']) {
            $reasons[] = 'También coincide con lo que buscás.';
        } elseif ($candidate['uncertain']) {
            $reasons[] = 'Se parece a lo que buscás; revisá si te sirve.';
        }

        if ($budget !== null && $candidate['within_budget'] !== null) {
            $budgetCents = PayPalCheckoutService::cents($budget);
            $diff = abs($budgetCents - $candidate['price_cents']);
            $reasons[] = $candidate['within_budget']
                ? 'Entra en tu presupuesto de $'.$budget.($diff > 0 ? ' y te sobran $'.PayPalCheckoutService::money($diff).'.' : '.')
                : 'Supera tu presupuesto de $'.$budget.' por $'.PayPalCheckoutService::money($diff).'.';
        }

        $stock = (int) $candidate['product']->stock;
        if ($stock > 0 && $stock <= 3) {
            $reasons[] = $stock === 1 ? 'Queda 1 sola unidad.' : "Quedan solo {$stock} unidades.";
        }

        return $reasons;
    }
}
