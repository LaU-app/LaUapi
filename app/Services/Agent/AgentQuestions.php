<?php

namespace App\Services\Agent;

/**
 * Preguntas que el agente de compras le hace a Jev, en un solo lugar para auditarlas.
 * El modelo de lenguaje conversa y elige herramientas; Jev da juicios tipados con
 * probabilidades calibradas, y el código decide (umbrales 0.7 / 0.3).
 *
 * Guía: https://docs.typesafe.ai/go/how-to-build.md (patrón "verify and escalate").
 */
final class AgentQuestions
{
    public const YES = 0.7;

    public const NO = 0.3;

    /** Máximo de frases de la respuesta que se verifican contra los datos. */
    public const MAX_SENTENCES = 12;

    public const CONTEXT = 'LaU Marketplace is a store inside LaU, a social network for university students in El Salvador. Only businesses verified by LaU sell there, and buyers pay with PayPal. A shopping assistant chats with students about these products.';

    /**
     * Antes de llamar al modelo de lenguaje: ¿el mensaje es sobre el Marketplace?
     *
     * @param  list<array{from: string, text: string}>  $recent  últimos mensajes del chat
     */
    public static function gate(string $message, array $recent): array
    {
        return [
            'state' => array_filter([
                'context' => self::CONTEXT,
                'recent_conversation' => $recent ?: null,
                'message' => $message,
            ]),
            'questions' => [
                'on_topic' => [
                    'type' => 'noul',
                    'instructions' => 'Is the student\'s `message` about finding, comparing, asking about or buying products, or about their orders, in LaU Marketplace?',
                    'criteria' => [
                        'true' => 'The student wants to find, compare, ask about or buy a product, or asks about a store or an order, for example "¿cuál me conviene para Cálculo?" or "y la otra, ¿tiene reseñas?".',
                        'false' => 'The student asks for something unrelated to shopping, for example to solve homework, write an essay, or chat about a topic with no product involved.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Después de que el modelo de lenguaje responde: frase por frase, ¿afirma datos de productos
     * y están respaldados por los datos reales que devolvieron las herramientas?
     *
     * @param  array<string, array>  $facts  productos/pedidos conocidos, con claves estables (p12, o5)
     * @param  array<string, string>  $sentences  s1 => frase
     */
    public static function grounding(array $facts, array $sentences): array
    {
        $questions = [];
        foreach (array_keys($sentences) as $key) {
            $questions["claim_{$key}"] = [
                'type' => 'noul',
                'instructions' => "Does `reply_sentences.{$key}` state a fact about a product or an order, such as its name, price, stock, features, reviews or store?",
                'criteria' => [
                    'true' => 'The sentence states something concrete about a product or an order, for example "La calculadora cuesta $18.50" or "Quedan 3 unidades".',
                    'false' => 'The sentence only greets, asks the student a question or explains how buying works, for example "¿Para qué materia la necesitás?".',
                ],
            ];
            $questions["supported_{$key}"] = [
                'type' => 'noul',
                'instructions' => "Is every product or order fact stated in `reply_sentences.{$key}` found in `facts`?",
                'criteria' => [
                    'true' => 'Each product, price, stock amount, feature, review or store the sentence mentions appears in `facts`, for example the sentence says $18.50 and `facts` lists that price for that product.',
                    'false' => 'The sentence mentions a product, price, stock amount, feature, review or store that `facts` does not contain, for example a price that appears nowhere in `facts`.',
                ],
            ];
        }

        return [
            'state' => [
                'context' => self::CONTEXT.' The assistant may only state facts that come from `facts`, which is the real data its tools returned.',
                'facts' => $facts ?: ['note' => 'The tools returned no products or orders in this conversation.'],
                'reply_sentences' => $sentences,
            ],
            'questions' => $questions,
        ];
    }

    /**
     * Frases de la respuesta, con claves estables. Se ignoran las muy cortas.
     *
     * @return array<string, string>
     */
    public static function sentences(string $reply): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\R+/u', trim($reply)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t-*•");
            if (mb_strlen($part) < 8) {
                continue;
            }
            $out['s'.(count($out) + 1)] = $part;
            if (count($out) >= self::MAX_SENTENCES) {
                break;
            }
        }

        return $out;
    }
}
