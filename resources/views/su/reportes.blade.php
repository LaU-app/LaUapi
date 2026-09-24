@extends('layouts.app-su')

@section('title', 'Reportes')

@section('view-contenido')

<div class="flex-1 flex flex-col overflow-hidden h-screen relative">
    
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 shrink-0 z-20 relative">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4"><i class="fas fa-bars fa-2x"></i></button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white truncate">Reportes</h2>
        </div>
        
        <div class="relative hidden sm:block">
            <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            <input type="text" placeholder="Buscar reporte..." class="w-64 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 border-transparent">
        </div>
    </header>

    <main class="flex-1 overflow-y-auto w-full p-4 md:p-8 bg-gray-50 dark:bg-gray-900 custom-scrollbar">
        
        <div class="mb-6">
            <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Gestión de Denuncias</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Revisa y toma acciones sobre los reportes generados por los usuarios.</p>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
            
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full whitespace-nowrap text-left">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 dark:text-gray-400 text-xs uppercase font-bold tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Reportador</th>
                            <th class="px-6 py-4">Reportado</th>
                            <th class="px-6 py-4">Motivo</th>
                            <th class="px-6 py-4">Fecha</th>
                            <th class="px-6 py-4">Estado</th>
                            <th class="px-6 py-4 text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                        
                        @forelse($reportes as $reporte)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors group">
                                
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs mr-3">
                                            {{ strtoupper(substr($reporte->reporter->name ?? '?', 0, 2)) }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $reporte->reporter->name ?? 'Usuario Eliminado' }}</p>
                                            <p class="text-xs text-gray-500">{{ $reporte->reporter->email ?? 'N/A' }}</p>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center font-bold text-xs mr-3">
                                            {{ strtoupper(substr($reporte->reportedUser->name ?? '?', 0, 2)) }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $reporte->reportedUser->name ?? 'Usuario Eliminado' }}</p>
                                            <p class="text-xs text-gray-500">{{ $reporte->reportedUser->email ?? 'N/A' }}</p>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="px-6 py-4">
                                    <p class="font-medium text-gray-800 dark:text-gray-200">{{ $reporte->motivo ?? 'Reporte' }}</p>
                                    <p class="text-xs text-gray-500 truncate w-40" title="{{ $reporte->descripcion }}">{{ $reporte->descripcion ?? 'Sin descripción extra' }}</p>
                                </td>
                                
                                <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                                    {{ $reporte->created_at->format('d M Y') }}<br>
                                    <span class="text-xs text-gray-400">{{ $reporte->created_at->format('h:i A') }}</span>
                                </td>
                                
                                <td class="px-6 py-4">
                                    @if($reporte->estado === 'pendiente')
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-500 border border-yellow-200 dark:border-yellow-800/50">
                                            <i class="fas fa-clock mr-1.5 mt-0.5"></i> Pendiente
                                        </span>
                                    @else
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-500 border border-green-200 dark:border-green-800/50">
                                            <i class="fas fa-check-circle mr-1.5 mt-0.5"></i> Resuelto
                                        </span>
                                    @endif
                                </td>
                                
                                <td class="px-6 py-4 text-center space-x-2">
                                    <button class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/30 p-2 rounded-lg transition-colors" title="Ver Detalles">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    @if($reporte->estado === 'Pendiente')
                                        <button class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300 bg-green-50 dark:bg-green-900/30 p-2 rounded-lg transition-colors" title="Resolver / Aprobar">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300 bg-red-50 dark:bg-red-900/30 p-2 rounded-lg transition-colors" title="Desestimar / Rechazar">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    @else
                                        <button class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors" title="Ver Historial">
                                            <i class="fas fa-history fa-lg"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-500 dark:text-gray-400 border-none">
                                    <i class="fas fa-clipboard-check fa-3x mb-3 opacity-20"></i>
                                    <p>No hay reportes registrados en el sistema.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            @if($reportes->hasPages())
                <div class="bg-gray-50 dark:bg-gray-800 px-6 py-3 border-t border-gray-100 dark:border-gray-700">
                    {{ $reportes->links() }}
                </div>
            @endif
            
        </div>
    </main>
</div>
@endsection