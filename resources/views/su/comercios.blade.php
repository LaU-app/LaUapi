@extends('layouts.app-su')

@section('title', 'Comercios verificados')

@section('view-contenido')
@php
    $statusStyles = [
        'submitted' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
        'approved' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
        'rejected' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
        'suspended' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    ];
    $tabs = ['' => 'Todas', 'submitted' => 'En revisión', 'approved' => 'Aprobadas', 'rejected' => 'Requieren cambios', 'suspended' => 'Suspendidas'];
@endphp

<div class="flex-1 flex flex-col overflow-hidden h-screen relative">
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 shrink-0 z-20 relative">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4"><i class="fas fa-bars fa-2x"></i></button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white truncate">Comercios verificados</h2>
        </div>
        <form method="GET" class="relative hidden sm:block">
            <input type="hidden" name="estado" value="{{ $status }}">
            <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            <input type="text" name="q" value="{{ $search }}" placeholder="Comercio, razón social o NIT"
                class="w-72 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 border-transparent">
        </form>
    </header>

    <main class="flex-1 overflow-y-auto w-full p-4 md:p-8 bg-gray-50 dark:bg-gray-900 custom-scrollbar">
        <div class="mb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
                <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Solicitudes de comercios</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Revisa los datos legales antes de otorgar el distintivo de LaU. Solo los comercios aprobados pueden conectar PayPal y cobrar en el Marketplace.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            @foreach ([
                ['En revisión', $counts['submitted'] ?? 0, 'fa-hourglass-half', 'text-amber-500 bg-amber-50 dark:bg-amber-900/20'],
                ['Aprobados', $counts['approved'] ?? 0, 'fa-circle-check', 'text-green-500 bg-green-50 dark:bg-green-900/20'],
                ['Cobrando con PayPal', $withPaypal, 'fa-brands fa-paypal', 'text-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'],
                ['Requieren cambios', $counts['rejected'] ?? 0, 'fa-pen-to-square', 'text-rose-500 bg-rose-50 dark:bg-rose-900/20'],
            ] as [$label, $value, $icon, $tone])
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-5 flex items-center gap-4">
                    <span class="h-11 w-11 rounded-xl flex items-center justify-center {{ $tone }}"><i class="{{ str_contains($icon, 'fa-brands') ? $icon : 'fas '.$icon }}"></i></span>
                    <div>
                        <p class="text-2xl font-bold text-gray-800 dark:text-white">{{ $value }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <nav class="flex gap-2 mb-4 overflow-x-auto no-scrollbar">
            @foreach ($tabs as $value => $label)
                <a href="{{ route('su.comercios', array_filter(['estado' => $value, 'q' => $search])) }}"
                    class="px-4 py-2 rounded-full text-sm font-semibold whitespace-nowrap transition
                    {{ (string) $status === (string) $value ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-indigo-400' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full whitespace-nowrap text-left">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 dark:text-gray-400 text-xs uppercase font-bold tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Comercio</th>
                            <th class="px-6 py-4">Razón social / NIT</th>
                            <th class="px-6 py-4">Enviada</th>
                            <th class="px-6 py-4">Verificación</th>
                            <th class="px-6 py-4">PayPal</th>
                            <th class="px-6 py-4 text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                        @forelse ($verifications as $verification)
                            @php($store = $verification->store)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if ($store->logo_url)
                                            <img src="{{ $store->logo_url }}" alt="" class="h-10 w-10 rounded-xl object-cover">
                                        @else
                                            <span class="h-10 w-10 rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300 flex items-center justify-center font-bold">{{ strtoupper(mb_substr($store->name, 0, 1)) }}</span>
                                        @endif
                                        <div>
                                            <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $store->name }}</p>
                                            <p class="text-xs text-gray-500">{{ '@'.($store->user->username ?? '—') }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-medium text-gray-700 dark:text-gray-200">{{ $verification->legal_name }}</p>
                                    <p class="text-xs text-gray-500 font-mono">{{ $verification->nit }}</p>
                                </td>
                                <td class="px-6 py-4 text-gray-500 dark:text-gray-400">{{ $verification->submitted_at?->diffForHumans() ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold {{ $statusStyles[$verification->status] ?? '' }}">{{ $verification->status_label }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    @if ($store->paypal_status === 'enabled')
                                        <span class="text-green-600 dark:text-green-400 text-xs font-bold"><i class="fa-brands fa-paypal mr-1"></i>Cobrando</span>
                                    @elseif ($store->paypal_status === 'pending')
                                        <span class="text-amber-600 dark:text-amber-400 text-xs font-bold"><i class="fas fa-clock mr-1"></i>Conectando</span>
                                    @else
                                        <span class="text-gray-400 text-xs">Sin conectar</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('su.comercios.show', $verification) }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition">
                                        Revisar <i class="fas fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-16 text-center">
                                    <i class="fas fa-store text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                                    <p class="font-semibold text-gray-600 dark:text-gray-300">No hay solicitudes en esta vista</p>
                                    <p class="text-sm text-gray-400">Los comercios se registran desde la app: Marketplace → Vender como comercio verificado.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">{{ $verifications->links() }}</div>
    </main>
</div>
@endsection
