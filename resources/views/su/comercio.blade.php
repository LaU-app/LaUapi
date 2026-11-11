@extends('layouts.app-su')

@section('title', $verification->store->name)

@section('view-contenido')
@php
    $store = $verification->store;
    $statusStyles = [
        'submitted' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
        'approved' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
        'rejected' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
        'suspended' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    ];
    $field = 'text-xs uppercase tracking-wider font-bold text-gray-400';
    $merchant = $store->paypal_merchant_id;
@endphp

<div class="flex-1 flex flex-col overflow-hidden h-screen relative">
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 shrink-0 z-20 relative">
        <div class="flex items-center gap-3 min-w-0">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-1"><i class="fas fa-bars fa-2x"></i></button>
            <a href="{{ route('su.comercios') }}" class="h-9 w-9 rounded-lg flex items-center justify-center text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="Volver"><i class="fas fa-arrow-left"></i></a>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white truncate">{{ $store->name }}</h2>
            <span class="px-2.5 py-1 rounded-full text-xs font-bold {{ $statusStyles[$verification->status] ?? '' }}">{{ $verification->status_label }}</span>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto w-full p-4 md:p-8 bg-gray-50 dark:bg-gray-900 custom-scrollbar">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            {{-- Datos legales --}}
            <section class="xl:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] border border-gray-100 dark:border-gray-700 p-6">
                    <div class="flex items-center gap-4 mb-6">
                        @if ($store->logo_url)
                            <img src="{{ $store->logo_url }}" alt="" class="h-16 w-16 rounded-2xl object-cover">
                        @else
                            <span class="h-16 w-16 rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300 flex items-center justify-center text-2xl font-bold">{{ strtoupper(mb_substr($store->name, 0, 1)) }}</span>
                        @endif
                        <div>
                            <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $verification->legal_name }}</p>
                            <p class="text-sm text-gray-500">{{ \App\Models\StoreVerification::BUSINESS_TYPES[$verification->business_type] ?? $verification->business_type }} · {{ $store->category->name ?? 'Sin categoría' }}</p>
                            <p class="text-xs text-gray-400 mt-1">Cuenta LaU: {{ $store->user->name ?? '—' }} ({{ '@'.($store->user->username ?? '—') }} · {{ $store->user->email ?? '—' }})</p>
                        </div>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5 text-sm">
                        <div><dt class="{{ $field }}">NIT</dt><dd class="mt-1 font-mono text-gray-800 dark:text-gray-100">{{ $verification->nit }}</dd></div>
                        <div><dt class="{{ $field }}">NRC</dt><dd class="mt-1 font-mono text-gray-800 dark:text-gray-100">{{ $verification->nrc ?: 'No aplica' }}</dd></div>
                        <div><dt class="{{ $field }}">Representante legal</dt><dd class="mt-1 text-gray-800 dark:text-gray-100">{{ $verification->legal_representative }}</dd></div>
                        <div><dt class="{{ $field }}">Dirección</dt><dd class="mt-1 text-gray-800 dark:text-gray-100">{{ $verification->address }}</dd></div>
                        <div><dt class="{{ $field }}">Correo de contacto</dt><dd class="mt-1"><a href="mailto:{{ $verification->contact_email }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $verification->contact_email }}</a></dd></div>
                        <div><dt class="{{ $field }}">Teléfono</dt><dd class="mt-1 text-gray-800 dark:text-gray-100">{{ $verification->contact_phone }}</dd></div>
                        <div class="sm:col-span-2"><dt class="{{ $field }}">Sitio web o redes</dt><dd class="mt-1">
                            @if ($verification->website)
                                <a href="{{ $verification->website }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 dark:text-indigo-400 hover:underline break-all">{{ $verification->website }}</a>
                            @else
                                <span class="text-gray-400">No indicó</span>
                            @endif
                        </dd></div>
                        <div class="sm:col-span-2"><dt class="{{ $field }}">Qué venderá a estudiantes</dt><dd class="mt-1 text-gray-700 dark:text-gray-200 whitespace-pre-line leading-relaxed">{{ $verification->offer_description }}</dd></div>
                    </dl>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] border border-gray-100 dark:border-gray-700 p-6 flex flex-col sm:flex-row sm:items-center gap-4">
                    <span class="h-12 w-12 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 flex items-center justify-center text-xl"><i class="fas fa-file-shield"></i></span>
                    <div class="flex-1">
                        <p class="font-semibold text-gray-800 dark:text-white">Documento legal</p>
                        <p class="text-sm text-gray-500">{{ $verification->document_original_name ?: 'Sin documento' }} · Verifica que coincidan razón social, NIT y representante.</p>
                    </div>
                    @if ($verification->has_document)
                        <a href="{{ route('su.comercios.document', $verification) }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-indigo-200 dark:border-indigo-700 text-indigo-600 dark:text-indigo-300 font-semibold text-sm hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                            <i class="fas fa-download"></i> Descargar
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-5">
                        <p class="{{ $field }}">Productos activos</p><p class="text-2xl font-bold mt-1 text-gray-800 dark:text-white">{{ $productsCount }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-5">
                        <p class="{{ $field }}">Ventas pagadas</p><p class="text-2xl font-bold mt-1 text-gray-800 dark:text-white">{{ $sales['count'] }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-5">
                        <p class="{{ $field }}">Monto cobrado</p><p class="text-2xl font-bold mt-1 text-gray-800 dark:text-white">${{ number_format((float) $sales['total'], 2) }}</p>
                    </div>
                </div>
            </section>

            {{-- Decisión y PayPal --}}
            <aside class="space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="font-bold text-gray-800 dark:text-white mb-1">Decisión de LaU</h3>
                    <p class="text-xs text-gray-500 mb-4">Enviada {{ $verification->submitted_at?->format('d/m/Y H:i') ?? '—' }}</p>

                    @if ($verification->reviewed_at)
                        <div class="mb-4 rounded-xl bg-gray-50 dark:bg-gray-900/50 p-4 text-sm">
                            <p class="text-gray-600 dark:text-gray-300"><b>{{ $verification->status_label }}</b> por {{ $verification->reviewed_by }}</p>
                            <p class="text-xs text-gray-400">{{ $verification->reviewed_at->format('d/m/Y H:i') }}</p>
                            @if ($verification->review_notes)
                                <p class="mt-2 text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ $verification->review_notes }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($verification->status !== 'approved')
                        <form method="POST" action="{{ route('su.comercios.approve', $verification) }}" class="space-y-3 mb-4">
                            @csrf
                            <textarea name="notes" rows="2" placeholder="Nota interna (opcional)" class="w-full rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-sm p-3 focus:ring-2 focus:ring-green-500 focus:outline-none"></textarea>
                            <button class="w-full py-3 rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold text-sm transition"><i class="fas fa-circle-check mr-2"></i>Aprobar y otorgar distintivo</button>
                        </form>
                    @endif

                    @if (in_array($verification->status, ['submitted', 'approved'], true))
                        <details class="group">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-600 dark:text-gray-300 py-2 list-none flex items-center justify-between">
                                {{ $verification->status === 'approved' ? 'Suspender comercio' : 'Pedir correcciones' }}
                                <i class="fas fa-chevron-down text-xs transition group-open:rotate-180"></i>
                            </summary>
                            <form method="POST" action="{{ $verification->status === 'approved' ? route('su.comercios.suspend', $verification) : route('su.comercios.reject', $verification) }}" class="space-y-3 pt-2">
                                @csrf
                                <textarea name="notes" rows="3" required minlength="10" placeholder="{{ $verification->status === 'approved' ? 'Motivo de la suspensión' : 'Qué debe corregir el comercio (lo verá en la app)' }}" class="w-full rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-sm p-3 focus:ring-2 focus:ring-rose-500 focus:outline-none"></textarea>
                                <button class="w-full py-2.5 rounded-xl border border-rose-300 dark:border-rose-700 text-rose-600 dark:text-rose-300 font-bold text-sm hover:bg-rose-50 dark:hover:bg-rose-900/20 transition">
                                    {{ $verification->status === 'approved' ? 'Suspender' : 'Devolver con observaciones' }}
                                </button>
                            </form>
                        </details>
                    @endif
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] border border-gray-100 dark:border-gray-700 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-gray-800 dark:text-white"><i class="fa-brands fa-paypal text-indigo-500 mr-2"></i>PayPal</h3>
                        @if ($store->paypal_status === 'enabled')
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">Cobrando</span>
                        @elseif ($store->paypal_status === 'pending')
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Conexión pendiente</span>
                        @else
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">Sin conectar</span>
                        @endif
                    </div>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Merchant ID</dt><dd class="font-mono text-gray-800 dark:text-gray-100">{{ $merchant ? substr($merchant, 0, 4).'••••'.substr($merchant, -3) : '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Última consulta</dt><dd class="text-gray-800 dark:text-gray-100">{{ $store->paypal_checked_at?->diffForHumans() ?? 'Nunca' }}</dd></div>
                    </dl>
                    <p class="text-xs text-gray-400 mt-4">El comercio conecta su cuenta desde la app después de la aprobación. PayPal es la fuente de verdad: LaU confirma que puede recibir pagos, que su correo está confirmado y que otorgó permisos antes de cada cobro.</p>
                    @if ($store->paypal_tracking_id)
                        <form method="POST" action="{{ route('su.comercios.paypal', $verification) }}" class="mt-4">
                            @csrf
                            <button class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm transition"><i class="fas fa-rotate mr-2"></i>Consultar PayPal ahora</button>
                        </form>
                    @endif
                </div>
            </aside>
        </div>
    </main>
</div>
@endsection
