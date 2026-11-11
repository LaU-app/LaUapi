<?php

namespace App\Services\Marketplace;

/**
 * Todas las preguntas que LaU le hace a Jev en la compra inteligente, en un solo lugar
 * para poder auditarlas y ajustarlas. Cada pregunta pide UN juicio que una persona podría
 * hacer de un vistazo; las decisiones (umbrales, presupuesto, totales) viven en código.
 *
 * Guía: https://docs.typesafe.ai/go/how-to-build.md
 */
final class SmartShoppingQuestions
{
    /** Umbrales de un Noul: arriba de YES es "sí", debajo de NO es "no", en medio no está claro. */
    public const YES = 0.7;

    public const NO = 0.3;

    /** Probabilidad mínima de la categoría para filtrar el catálogo por ella. */
    public const CATEGORY_MIN = 0.5;

    /** Puntaje mínimo de "cubre la necesidad" (escala 0–3) para recomendar sin preguntar. */
    public const FIT_MIN = 2.0;

    /** Probabilidad mínima para mostrar la frase de la descripción que eligió Jev como evidencia. */
    public const EVIDENCE_MIN = 0.4;

    public const FIT_LEVELS = [
        'It would not help with this need',
        'It helps a little, but the student would still need something else',
        'It covers the need with some compromises',
        'It covers the need described completely',
    ];

    public const FIT_LABELS = ['No cubre la necesidad', 'Ayuda poco', 'Cubre la necesidad con concesiones', 'Cubre la necesidad por completo'];

    public const ACADEMIC_LEVELS = [
        'It is not useful for studying',
        'It is somewhat useful for studying',
        'It is directly useful for the course or studies the student mentions',
    ];

    public const ACADEMIC_LABELS = ['Poca relación con estudios', 'Útil para estudiar', 'Directamente útil para la materia'];

    /**
     * Primera consulta: entender la solicitud. Las cifras las extrae el código; Jev solo dice
     * qué significa cada una (selección, no generación).
     *
     * @param  array<string, string>  $categories  slug => descripción
     * @param  array<string, string>  $moneyMentions  m1 => "$25"
     */
    public static function understand(array $categories, array $moneyMentions): array
    {
        $questions = [
            'purchase_intent' => [
                'type' => 'noul',
                'instructions' => 'Does the student in `request` want to buy or get a product or service?',
                'criteria' => [
                    'true' => 'The student is looking for something to buy, for example "necesito una calculadora para Cálculo".',
                    'false' => 'The student is doing something else, for example greeting, asking how the app works or selling something.',
                ],
            ],
            'item_named' => [
                'type' => 'noul',
                'instructions' => 'Does `request` say what kind of item or service the student needs?',
                'criteria' => [
                    'true' => 'It names the kind of item, for example "una calculadora científica" or "una bata de laboratorio".',
                    'false' => 'It only says the student wants to buy something, for example "quiero comprar algo barato".',
                ],
            ],
            'need_described' => [
                'type' => 'noul',
                'instructions' => 'Does `request` say what the student needs the item for, such as a course, a class or a project?',
                'criteria' => [
                    'true' => 'It names a use, for example "algo para mis clases de matemáticas" or "para el laboratorio de Química".',
                    'false' => 'It gives no use, for example "algo barato" or just a greeting.',
                ],
            ],
            'category' => [
                'type' => 'choice',
                'instructions' => 'Which category in `categories` would sell what the student asks for in `request`?',
                'criteria' => $categories,
            ],
            'category_exists' => [
                'type' => 'noul',
                'instructions' => 'Is what the student asks for in `request` sold in one of the categories in `categories`?',
                'criteria' => [
                    'true' => 'The item clearly belongs to one of the listed categories.',
                    'false' => 'The item belongs to none of them, for example food or a bus ticket.',
                ],
            ],
        ];

        foreach (array_keys($moneyMentions) as $key) {
            $questions['budget_role_'.$key] = [
                'type' => 'choice',
                'instructions' => "What does the amount `money_mentions.{$key}` mean in `request`?",
                'criteria' => [
                    'maximum_price' => 'The most the student is willing to pay for the item.',
                    'money_to_keep' => 'Money the student says they must keep for something else, like food or transport.',
                    'other_amount' => 'An amount that is not a spending limit, like a quantity, a grade or a price they saw elsewhere.',
                ],
            ];
        }

        return $questions;
    }

    /**
     * Segunda consulta: juzgar cada candidato real del catálogo frente a la necesidad.
     * Cada candidato tiene sus propias preguntas (no se le pide a Jev buscar en una lista).
     *
     * @param  array<string, string>  $candidateNames  clave => nombre del producto
     * @param  array<string, array<string, string>>  $descriptionParts  clave => frases reales de su descripción
     */
    public static function evaluate(array $candidateNames, array $descriptionParts = []): array
    {
        $questions = [];

        foreach (array_keys($candidateNames) as $key) {
            // Seleccionar en vez de generar: Jev elige una frase real de la descripción.
            if (! empty($descriptionParts[$key])) {
                $questions['evidence_'.$key] = [
                    'type' => 'choice',
                    'instructions' => "Which part of `candidates.{$key}.description_parts` best shows that this product fits what the student needs in `request`?",
                    'criteria' => $descriptionParts[$key],
                ];
            }
            $questions['match_'.$key] = [
                'type' => 'noul',
                'instructions' => "Is `candidates.{$key}` the kind of item the student asks for in `request`?",
                'criteria' => [
                    'true' => 'It is the same kind of item, for example a scientific calculator when the student needs a calculator for Calculus.',
                    'false' => 'It is a different kind of item, for example a notebook when the student needs a calculator.',
                ],
            ];
            $questions['fit_'.$key] = [
                'type' => 'score',
                'instructions' => "How well would `candidates.{$key}` cover the need the student describes in `request`?",
                'criteria' => self::FIT_LEVELS,
            ];
            $questions['academic_'.$key] = [
                'type' => 'score',
                'instructions' => "How useful is `candidates.{$key}` for the studies or course the student mentions in `request`?",
                'criteria' => self::ACADEMIC_LEVELS,
            ];
        }

        if (count($candidateNames) > 1) {
            $questions['best'] = [
                'type' => 'choice',
                'instructions' => 'Which product in `candidates` best matches what the student needs in `request`?',
                'criteria' => $candidateNames,
            ];
        }

        return $questions;
    }
}
