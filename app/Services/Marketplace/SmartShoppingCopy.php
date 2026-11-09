<?php

namespace App\Services\Marketplace;

/**
 * Textos de la compra inteligente. Son plantillas fijas que se completan con los resultados
 * (decisión, probabilidades y datos del catálogo): Jev no redacta texto.
 */
final class SmartShoppingCopy
{
    /** @return array{title: string, body: list<string>, clarification: ?string} */
    public static function for(string $outcome, array $base, ?array $recommended): array
    {
        $budget = $base['budget'];
        $budgetLine = $budget['amount'] !== null
            ? 'Presupuesto máximo para esta búsqueda: $'.$budget['amount'].($budget['source'] === 'message' ? ' (lo tomamos de tu mensaje).' : '.')
            : null;
        $unclear = $budget['unclear_amounts'] !== []
            ? 'No usamos '.implode(', ', $budget['unclear_amounts']).' como límite porque no quedó claro si era tu presupuesto. Si lo es, escribilo en "Presupuesto máximo".'
            : null;

        $copy = match ($outcome) {
            'recommendation' => [
                'title' => 'Te recomendamos '.$recommended['product']->name,
                'body' => [
                    'Jev estima un '.self::percent($recommended['match']).' de probabilidad de que sea lo que buscás.',
                    'Compatibilidad con tu necesidad: '.mb_strtolower(SmartShoppingQuestions::FIT_LABELS[(int) round($recommended['fit'])]).'.',
                    $recommended['within_budget'] === true
                        ? 'Cuesta $'.PayPalCheckoutService::money($recommended['price_cents']).': está dentro de tu presupuesto máximo de $'.$budget['amount'].'.'
                        : 'Cuesta $'.PayPalCheckoutService::money($recommended['price_cents']).'.',
                    'Lo vende '.$recommended['product']->store->name.', comercio verificado por LaU que cobra con PayPal.',
                ],
                'clarification' => null,
            ],
            'over_budget' => [
                'title' => 'Lo encontramos, pero se pasa de tu presupuesto',
                'body' => ['Las opciones que coinciden cuestan más de $'.$budget['amount'].'. Te las mostramos por si querés ajustar el monto.'],
                'clarification' => null,
            ],
            'confirm_choice' => [
                'title' => '¿Alguna de estas te sirve?',
                'body' => ['Encontramos opciones parecidas, pero Jev no está seguro de que sean exactamente lo que necesitás. Revisalas antes de elegir.'],
                'clarification' => 'Si ninguna sirve, contanos con más detalle qué necesitás (modelo, materia o uso).',
            ],
            'no_match' => [
                'title' => 'No encontramos algo así en comercios verificados',
                'body' => ['Por ahora ningún comercio verificado que cobra con PayPal vende lo que buscás.'],
                'clarification' => 'Probá con otras palabras o una categoría más general.',
            ],
            'needs_clarification' => [
                'title' => 'Necesitamos un poco más de detalle',
                'body' => ['Jev no tiene suficiente información para buscar con confianza.'],
                'clarification' => '¿Qué producto necesitás? Por ejemplo: "una calculadora científica para Cálculo I, máximo $25".',
            ],
            default => [
                'title' => 'Esta búsqueda es para comprar en LaU Marketplace',
                'body' => ['Jev no detectó que quieras comprar algo.'],
                'clarification' => 'Escribí qué necesitás comprar y, si querés, tu presupuesto máximo.',
            ],
        };

        $copy['body'] = array_values(array_filter([...$copy['body'], $budgetLine, $unclear]));

        return $copy;
    }

    private static function percent(float $p): string
    {
        return (int) round($p * 100).' %';
    }
}
