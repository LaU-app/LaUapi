@extends('layouts.app-su')

@section('title', 'Dashboard')

@section('view-contenido')
<div class="flex-1 flex flex-col overflow-hidden">
    
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 transition-colors duration-300">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4">
                <i class="fas fa-bars fa-2x"></i>
            </button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white">Resumen</h2>
        </div>
        
        <div class="flex items-center space-x-2 md:space-x-4">
            <a href="{{ route('su.ads', ['view' => 'create']) }}" 
               class="hidden md:block bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow transition no-underline text-center">
                <i class="fas fa-plus mr-1"></i> Crear Anuncio
            </a>
            <a href="{{ route('su.ads', ['view' => 'create']) }}" class="md:hidden bg-indigo-600 hover:bg-indigo-700 text-white p-2 rounded-lg shadow transition">
                <i class="fas fa-plus"></i>
            </a>
            <button class="text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200 ml-2">
                <i class="fas fa-bell fa-lg"></i>
            </button>
        </div>
    </header>

    <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 md:p-8 transition-colors duration-300">
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 border-l-4 border-indigo-500 flex items-center justify-between transition-colors">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Total Usuarios</p>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white">{{ $totalUsers }}</h3>
                </div>
                <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-full text-indigo-600 dark:text-indigo-400">
                    <i class="fas fa-users"></i>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 border-l-4 border-green-500 flex items-center justify-between transition-colors">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Universidades</p>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white">12</h3>
                </div>
                <div class="p-3 bg-green-50 dark:bg-green-900/30 rounded-full text-green-600 dark:text-green-400">
                    <i class="fas fa-university"></i>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 border-l-4 border-yellow-500 flex items-center justify-between transition-colors">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Anuncios Activos</p>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white">{{ $activeCount }}</h3>
                </div>
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-full text-yellow-600 dark:text-yellow-400">
                    <i class="fas fa-bullhorn"></i>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 border-l-4 border-red-500 flex items-center justify-between transition-colors">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Reportes Nuevos</p>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white">3</h3>
                </div>
                <div class="p-3 bg-red-50 dark:bg-red-900/30 rounded-full text-red-600 dark:text-red-400">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 overflow-hidden transition-colors">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-gray-700 dark:text-white">Usuarios Recientes</h3>
                    <a href="#" class="text-indigo-600 dark:text-indigo-400 text-sm hover:underline">Ver todos</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[500px]">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700">
                                <th class="py-3">Nombre</th>
                                <th class="py-3">Universidad</th>
                                <th class="py-3">Estado</th>
                                <th class="py-3 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700 dark:text-gray-300">
                            <tr class="border-b border-gray-50 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="py-3 font-medium flex items-center">
                                     <img src="https://ui-avatars.com/api/?name=Ana+M&background=EBF4FF&color=7F9CF5&size=28" class="h-7 w-7 rounded-full mr-2">
                                    Ana Martínez
                                </td>
                                <td class="py-3 text-gray-500 dark:text-gray-400">UES</td>
                                <td class="py-3"><span class="bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 py-1 px-2 rounded text-xs">Activo</span></td>
                                <td class="py-3 text-right"><button class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 mr-[10px]"><i class="fas fa-edit"></i></button></td>
                            </tr>
                            <tr class="border-b border-gray-50 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="py-3 font-medium flex items-center">
                                     <img src="https://ui-avatars.com/api/?name=Jorge+R&background=FEF2F2&color=F87171&size=28" class="h-7 w-7 rounded-full mr-2">
                                    Jorge Ruíz
                                </td>
                                <td class="py-3 text-gray-500 dark:text-gray-400">Don Bosco</td>
                                <td class="py-3"><span class="bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 py-1 px-2 rounded text-xs">Reportado</span></td>
                                <td class="py-3 text-right"><button class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 mr-[10px]"><i class="fas fa-edit"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 transition-colors">
                <h3 class="font-bold text-gray-700 dark:text-white mb-4">Actividad por Universidad</h3>
                <div class="space-y-4 text-gray-700 dark:text-gray-300">
                    <div>
                         <div class="flex justify-between text-xs mb-1"><span>UES</span><span class="font-bold">45%</span></div>
                         <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2"><div class="bg-indigo-600 h-2 rounded-full" style="width: 45%"></div></div>
                     </div>
                     <div>
                         <div class="flex justify-between text-xs mb-1"><span>UCA</span><span class="font-bold">30%</span></div>
                         <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2"><div class="bg-teal-500 h-2 rounded-full" style="width: 30%"></div></div>
                     </div>
                     <div>
                         <div class="flex justify-between text-xs mb-1"><span>Don Bosco</span><span class="font-bold">15%</span></div>
                         <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2"><div class="bg-orange-600 h-2 rounded-full" style="width: 15%"></div></div>
                     </div>
                     <div>
                         <div class="flex justify-between text-xs mb-1"><span>Otras</span><span class="font-bold">10%</span></div>
                         <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2"><div class="bg-gray-500 h-2 rounded-full" style="width: 10%"></div></div>
                     </div>
                </div>
                
                <div class="mt-8 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg p-4 border border-indigo-100 dark:border-indigo-800 transition-colors">
                    <h4 class="text-indigo-800 dark:text-indigo-200 font-bold text-sm">💡 Tip Rápido</h4>
                    <p class="text-indigo-600 dark:text-indigo-300 text-xs mt-1">Recuerda revisar los reportes pendientes antes de finalizar el día.</p>
                </div>
            </div>
        </div>
    </main>
</div>
@endsection