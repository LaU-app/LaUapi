@extends('layouts.app-su')

@section('title', 'Compra inteligente')

@section('view-contenido')
@php
    $outcomeLabels = [
        'recommendation' => ['Recomendó', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
        'confirm_choice' => ['Pidió confirmar', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        'over_budget' => ['Fuera de presupuesto', 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'],
        'no_match' => ['Sin coincidencias', 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'],
        'needs_clarification' => ['Pidió aclaración', 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
        'not_a_purchase' => ['No era compra', 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'],
    ];
    $pct = fn ($p) => is_numeric($p) ? round($p * 100).' %' : '—';
@endphp

<div class="flex-1 flex flex-col overflow-hidden h-screen relative">
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 shrink-0 z-20 relative">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4"><i class="fas fa-bars fa-2x"></i></button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white truncate">Compra inteligente con Jev</h2>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto w-full p-4 md:p-8 bg-gray-50 dark:bg-gray-900 custom-scrollbar">
        <div class="mb-6">
            <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Bitácora de decisiones</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">Cada búsqueda guarda las probabilidades que devolvió Jev (TypeSafe AI) sobre productos reales y la decisión que tomó el código. Jev no ve precios ni cobra: el presupuesto y los totales los calcula LaU y el pago siempre lo confirma el estudiante en PayPal.</p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            @foreach ([
                ['Búsquedas', $outcomes->sum(), 'fa-magnifying-glass'],
                ['Recomendaciones', $outcomes['recommendation'] ?? 0, 'fa-thumbs-up'],
                ['Pidió aclarar o confirmar', ($outcomes['needs_clarification'] ?? 0) + ($outcomes['confirm_choice'] ?? 0), 'fa-circle-question'],
                ['Terminaron en pago', $paid, 'fa-brands fa-paypal'],
            ] as [$label, $value, $icon])
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-5 flex items-center gap-4">
                    <span class="h-11 w-11 rounded-xl flex items-center justify-center text-indigo-500 bg-indigo-50 dark:bg-indigo-900/20"><i class="{{ str_contains($icon, 'fa-brands') ? $icon : 'fas '.$icon }}"></i></span>
                    <div><p class="text-2xl font-bold text-gray-800 dark:text-white">{{ $value }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p></div>
                </div>
            @endforeach
        </div>

        <div class="space-y-3">
            @forelse ($decisions as $decision)
                @php([$label, $style] = $outcomeLabels[$decision->outcome] ?? [$decision->outcome, 'bg-gray-100 text-gray-600'])
                @php($understand = $decision->answers['understand'] ?? [])
                <details class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 overflow-hidden group">
                    <summary class="list-none cursor-pointer p-5 flex flex-col md:flex-row md:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-800 dark:text-gray-100 truncate">“{{ $decision->query }}”</p>
                            <p class="text-xs text-gray-500">{{ $decision->user->name ?? 'Usuario' }} · {{ $decision->created_at->diffForHumans() }} · {{ $decision->model }}
                                @if ($decision->max_budget) · Presupuesto ${{ number_format((float) $decision->max_budget, 2) }} ({{ $decision->budget_source === 'field' ? 'campo' : 'mensaje' }}) @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold {{ $style }}">{{ $label }}</span>
                            @if ($decision->order)
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"><i class="fa-brands fa-paypal mr-1"></i>{{ $decision->order->code }} · ${{ number_format((float) $decision->order->total, 2) }}</span>
                            @endif
                            <i class="fas fa-chevron-down text-xs text-gray-400 transition group-open:rotate-180"></i>
                        </div>
                    </summary>
                    <div class="px-5 pb-5 grid grid-cols-1 lg:grid-cols-2 gap-4 text-sm">
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/50 p-4">
                            <p class="text-xs uppercase font-bold text-gray-400 mb-2">Interpretación (Jev)</p>
                            <ul class="space-y-1 text-gray-700 dark:text-gray-200">
                                <li>Intención de compra: <b>{{ $pct($understand['purchase_intent']['noul'] ?? null) }}</b></li>
                                <li>Indica qué producto necesita: <b>{{ $pct($understand['item_named']['noul'] ?? null) }}</b></li>
                                @if (isset($understand['category']))
                                    <li>Categoría: <b>{{ $understand['category']['choice'] }}</b> ({{ $pct($understand['category']['probabilities'][$understand['category']['choice']] ?? null) }})</li>
                                @endif
                                <li>Categoría usada para filtrar: <b>{{ $decision->category_slug ?? 'ninguna' }}</b></li>
                            </ul>
                        </div>
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/50 p-4">
                            <p class="text-xs uppercase font-bold text-gray-400 mb-2">Candidatos reales evaluados</p>
                            @forelse ($decision->candidates as $candidate)
                                <div class="flex items-center justify-between gap-3 py-1 {{ $decision->recommended_product_id === $candidate['product_id'] ? 'font-bold text-green-700 dark:text-green-300' : 'text-gray-700 dark:text-gray-200' }}">
                                    <span class="truncate">#{{ $candidate['product_id'] }} · ${{ number_format((float) $candidate['price'], 2) }}</span>
                                    <span class="text-xs whitespace-nowrap">coincide {{ $pct($candidate['match']) }} · cubre {{ number_format($candidate['fit'], 1) }}/3 · {{ $candidate['within_budget'] === null ? 'sin presupuesto' : ($candidate['within_budget'] ? 'en presupuesto' : 'excede') }}</span>
                                </div>
                            @empty
                                <p class="text-gray-400">No llegó a evaluar productos.</p>
                            @endforelse
                        </div>
                    </div>
                </details>
            @empty
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-12 text-center">
                    <i class="fas fa-wand-magic-sparkles text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <p class="font-semibold text-gray-600 dark:text-gray-300">Todavía no hay búsquedas</p>
                </div>
            @endforelse
        </div>

        <div class="mt-6">{{ $decisions->links() }}</div>
    </main>
</div>
@endsection
