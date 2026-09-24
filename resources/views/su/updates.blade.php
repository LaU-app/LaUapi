@extends('layouts.app-su')

@section('title', 'Actualizaciones de la App')

@section('view-contenido')
<div class="flex-1 flex flex-col overflow-hidden">
    
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 transition-colors duration-300">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4"><i class="fas fa-bars fa-2x"></i></button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white">Actualizaciones (OTA)</h2>
        </div>
        <button onclick="toggleModal('upload-update-modal')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow transition flex items-center">
            <i class="fas fa-cloud-upload-alt mr-2"></i> Subir Versión
        </button>
    </header>

    <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 md:p-8 transition-colors duration-300">

        {{-- SECCIÓN: Versión Activa --}}
        <div class="mb-8">
            <h3 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-4">Versión Activa en Producción</h3>
            
            @php
                $activeUpdate = $updates->where('is_active', true)->first();
            @endphp

            @if($activeUpdate)
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-[1.5rem] p-8 shadow-lg text-white flex flex-col md:flex-row items-center justify-between relative overflow-hidden">
                    <div class="absolute top-0 right-0 -mt-4 -mr-4 text-white opacity-10">
                        <i class="fas fa-mobile-alt text-9xl"></i>
                    </div>
                    
                    <div class="relative z-10 flex items-center gap-6">
                        <div class="h-20 w-20 rounded-full bg-white/20 flex items-center justify-center text-4xl backdrop-blur-sm border border-white/30">
                            <i class="bx bx-check-shield"></i>
                        </div>
                        <div>
                            <p class="text-indigo-100 font-medium text-sm mb-1">Los usuarios están descargando la versión:</p>
                            <h2 class="text-4xl font-extrabold tracking-tight">v{{ $activeUpdate->version }}</h2>
                            <p class="text-sm text-indigo-200 mt-2"><i class="far fa-clock mr-1"></i> Subida el {{ $activeUpdate->created_at->format('d M, Y \a \l\a\s H:i') }}</p>
                        </div>
                    </div>
                </div>
            @else
                <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] p-8 shadow-sm border border-gray-100 dark:border-gray-700 flex items-center gap-4">
                    <div class="h-12 w-12 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center text-xl">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-lg">No hay versión activa</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Sube un archivo .zip para activar las actualizaciones automáticas.</p>
                    </div>
                </div>
            @endif
        </div>

        {{-- SECCIÓN: Historial de Versiones --}}
        <div>
            <h3 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-4">Historial de Versiones</h3>
            
            <div class="grid grid-cols-1 gap-4">
                @forelse ($updates->sortByDesc('created_at') as $update)
                    <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row items-start sm:items-center justify-between transition hover:shadow-md">
                        
                        <div class="flex items-center gap-4 mb-4 sm:mb-0">
                            <div class="h-12 w-12 rounded-full {{ $update->is_active ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-300' }} flex items-center justify-center text-xl">
                                <i class="fas fa-file-archive"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800 dark:text-white text-lg flex items-center gap-2">
                                    v{{ $update->version }}
                                    @if($update->is_active)
                                        <span class="bg-indigo-100 text-indigo-700 text-[10px] px-2 py-0.5 rounded-full uppercase font-bold tracking-wider">Activa</span>
                                    @endif
                                </h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    {{ $update->created_at->diffForHumans() }} ({{ round(Storage::disk('public')->size($update->file_path) / 1048576, 2) }} MB)
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            @if(!$update->is_active)
                                {{-- Botón para hacer Rollback a una versión anterior --}}
                                <form action="{{ route('su.updates.activate', $update->id) }}" method="POST" class="w-full sm:w-auto">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-medium transition flex items-center justify-center">
                                        <i class="fas fa-history mr-2"></i> Activar (Rollback)
                                    </button>
                                </form>
                            @endif
                            
                            {{-- Botón que abre el modal de eliminar con tu función --}}
                            <button type="button" 
                                    onclick="confirmDelete('{{ route('su.updates.destroy', $update->id) }}', '{{ $update->version }}')" 
                                    class="w-full sm:w-auto px-4 py-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl text-sm font-medium transition flex items-center justify-center">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>

                    </div>
                @empty
                    {{-- ESTADO VACÍO --}}
                    <div class="flex flex-col items-center justify-center p-10 text-gray-400 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-[1.5rem]">
                        <i class="fas fa-box-open fa-3x mb-4 opacity-50"></i>
                        <p>No has subido ninguna actualización todavía.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </main>
</div>

{{-- MODAL PARA SUBIR ACTUALIZACIÓN --}}
<div id="upload-update-modal" class="hidden fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="toggleModal('upload-update-modal')"></div>
        
        <div class="bg-white dark:bg-gray-800 rounded-[2rem] shadow-2xl max-w-md w-full relative z-10 overflow-hidden transform transition-all p-8">
            
            <div class="text-center mb-6">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-indigo-100 text-indigo-600 mb-4 text-3xl">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Subir Nueva Actualización</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Sube el archivo .zip generado por tu comando build en Ionic.</p>
            </div>

            {{-- IMPORTANTE: enctype="multipart/form-data" es obligatorio para subir archivos --}}
            <form action="{{ route('su.updates.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                @csrf
                
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Versión (Ej: 1.0.5)</label>
                    <input type="text" name="version" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-3 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="1.0.0">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Archivo compilado (.zip)</label>
                    <input type="file" name="update_file" accept=".zip" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-gray-700 dark:file:text-gray-300 transition outline-none cursor-pointer">
                </div>
                
                <div class="mt-8 w-full flex gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <button type="button" onclick="toggleModal('upload-update-modal')" class="flex-1 py-3 px-4 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-bold rounded-xl transition">Cancelar</button>
                    
                    <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl shadow-lg transition transform hover:-translate-y-0.5">Subir y Activar</button>
                </div>
            </form>

        </div>
    </div>
</div>

{{-- Modal Eliminar Actualización --}}
<div id="delete-modal" class="hidden fixed inset-0 z-50" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div id="delete-backdrop" class="fixed inset-0 bg-gray-900/75 backdrop-blur-sm transition-opacity opacity-100" onclick="closeDeleteModal()"></div>
    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center text-center sm:items-center p-4 sm:p-4">
            <div id="delete-card" class="relative transform overflow-hidden bg-white dark:bg-gray-800 text-left shadow-2xl transition-all w-full sm:max-w-lg rounded-t-[2rem] sm:rounded-[1.5rem]" style="touch-action: none;">
                
                <div id="delete-drag-handle" class="flex justify-center pt-3 pb-1 sm:hidden cursor-grab active:cursor-grabbing w-full">
                    <div class="w-12 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full"></div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 px-6 pt-5 pb-4 sm:p-8">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-trash text-red-600 dark:text-red-400"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-xl font-bold leading-6 text-gray-900 dark:text-white" id="modal-title">¿Eliminar la versión <span id="delete-version-text" class="text-red-500"></span>?</h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Esta acción no se podrá deshacer. El archivo .zip será borrado permanentemente de tu servidor VPS.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 dark:bg-gray-700/50 px-6 py-4 sm:flex sm:flex-row-reverse gap-2 sm:px-8">
                    {{-- El formulario ahora está vacío, el JS le inyectará la URL --}}
                    <form id="delete-form" action="" method="POST" class="w-full sm:w-auto">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full inline-flex justify-center rounded-xl border border-transparent bg-red-600 text-white px-4 py-3 text-base font-bold hover:bg-red-700 focus:outline-none sm:mt-0 sm:w-auto sm:text-sm transition-transform active:scale-95">
                        Eliminar
                        </button>
                    </form>
                    <button type="button" onclick="closeDeleteModal()" class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-3 text-base font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none sm:mt-0 sm:w-auto sm:text-sm sm:mr-auto transition">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // 1. FUNCIÓN PARA ABRIR CUALQUIER MODAL (Animado)
    function openModal(idName) {
        const modal = document.getElementById(`${idName}-modal`);
        const backdrop = document.getElementById(`${idName}-backdrop`);
        const card = document.getElementById(`${idName}-card`);
        
        if (!modal || !backdrop || !card) return;
        
        modal.classList.remove('hidden');
        
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            card.classList.remove('translate-y-full', 'opacity-0', 'sm:scale-95');
            card.classList.add('sm:scale-100', 'opacity-100');
        }, 10);
    }

    // 2. FUNCIÓN PARA OCULTAR EL MODAL
    function closeModal(idName) {
        const modal = document.getElementById(`${idName}-modal`);
        const backdrop = document.getElementById(`${idName}-backdrop`);
        const card = document.getElementById(`${idName}-card`);
        if (!modal || !backdrop || !card) return;
        
        backdrop.classList.add('opacity-0');
        card.classList.add('translate-y-full', 'opacity-0', 'sm:scale-95');
        card.classList.remove('sm:scale-100');
        
        setTimeout(() => {
            modal.classList.add('hidden');
            card.style.transform = ''; 
            
            // Si es el de eliminar, limpiamos la acción por seguridad
            if(idName === 'delete') {
                const form = document.getElementById('delete-form');
                if(form) form.action = '';
            }
        }, 300);
    }

    // 3. FUNCIÓN ESPECÍFICA PARA ELIMINAR ACTUALIZACIONES
    function confirmDelete(deleteUrl, version) {
        // Cerrar dropdowns si los tuvieras
        document.querySelectorAll('[id^="dropdown-"]').forEach(dd => {
            dd.classList.add('hidden');
            dd.classList.remove('opacity-100', 'scale-100');
        });

        // Actualizamos la URL del formulario
        const form = document.getElementById('delete-form');
        if (form) form.action = deleteUrl;
        
        // Actualizamos el texto rojo con la versión
        const versionText = document.getElementById('delete-version-text');
        if (versionText) versionText.innerText = 'v' + version;

        // Abrimos el modal con tu función animada
        openModal('delete');
    }

    // 4. LA FUNCIÓN PRINCIPAL DE SWIPE (Arrastre)
    function enableSwipeDown(cardId, handleId, closeCallback) {
        const card = document.getElementById(cardId);
        const handle = document.getElementById(handleId);
        
        let startY = 0;
        let currentY = 0;
        let isDragging = false;

        if (!handle || !card) return;

        handle.addEventListener('touchstart', (e) => {
            startY = e.touches[0].clientY;
            isDragging = true;
            card.style.transition = 'none';
        }, { passive: true });

        handle.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            currentY = e.touches[0].clientY;
            const diff = currentY - startY;

            if (diff > 0) card.style.transform = `translateY(${diff}px)`;
        }, { passive: true });

        handle.addEventListener('touchend', () => {
            isDragging = false;
            const diff = currentY - startY;
            
            card.style.transition = 'transform 0.3s ease-out, opacity 0.3s ease-out';

            if (diff > 100) {
                card.style.transform = `translateY(100%)`;
                setTimeout(() => {
                    closeCallback();
                    setTimeout(() => { card.style.transform = ''; }, 50);
                }, 300);
            } else {
                card.style.transform = '';
            }
            startY = 0;
            currentY = 0;
        });
    }

    // 5. ACTIVACIÓN
    document.addEventListener("DOMContentLoaded", function() {
        // Activar swipe para el modal de eliminar
        enableSwipeDown('delete-card', 'delete-drag-handle', () => closeModal('delete'));
        
        // Activar swipe para el modal de subir (si quieres)
        // Asegúrate de agregarle el ID 'upload-update-drag-handle' y 'upload-update-card' al HTML
        enableSwipeDown('upload-update-card', 'upload-update-drag-handle', () => closeModal('upload-update'));
    });
</script>

<script>
    function toggleModal(id) { 
        document.getElementById(id).classList.toggle("hidden"); 
    }
</script>
@endsection