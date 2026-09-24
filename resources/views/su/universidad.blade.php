@extends('layouts.app-su')

@section('title', 'Universidades')

@section('view-contenido')

<div class="flex-1 flex flex-col overflow-hidden">
    
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 transition-colors duration-300">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4"><i class="fas fa-bars fa-2x"></i></button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white">Universidades</h2>
        </div>
        <div class="flex items-center space-x-4">
            <button class="text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200"><i class="fas fa-bell fa-lg"></i></button>
        </div>
    </header>

    <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 md:p-8 transition-colors duration-300 custom-scrollbar">
        
        <div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
            <div class="relative w-full md:w-96">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                    <i class="fas fa-search"></i>
                </span>
                <input type="text" placeholder="Buscar universidad..." class="pl-10 pr-4 py-2 w-full border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors">
            </div>
            
            <button onclick="toggleModal('add-university-modal')" class="w-full md:w-auto bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg font-medium shadow-lg transition transform hover:-translate-y-0.5 flex items-center justify-center">
                <i class="fas fa-plus mr-2"></i> Agregar Universidad
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">

            @foreach($universidades as $uni)

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-100 dark:border-gray-700 overflow-hidden group">
                
                <div class="h-24 relative" style="background-color: {{ $uni->color_primario ?? '#4b5563' }};">
                    
                    <div class="absolute -bottom-10 left-6">
                        <div class="h-20 w-20 bg-white dark:bg-gray-700 rounded-lg shadow-lg flex items-center justify-center p-2 border-4 border-white dark:border-gray-800">
                            <span class="text-2xl font-bold uppercase" style="color: {{ $uni->color_primario ?? '#4b5563' }};">
                                {{ $uni->siglas ?? substr($uni->nombre, 0, 3) }}
                            </span> 
                        </div>
                    </div>
                    
                    <div class="absolute top-4 right-4 bg-green-500/90 text-white text-xs px-2 py-1 rounded-full backdrop-blur-sm">
                        Activa
                    </div>
                </div>
                
                <div class="pt-12 px-6 pb-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-white transition-colors truncate" title="{{ $uni->nombre }}">
                        {{ $uni->nombre }}
                    </h3>
                    
                    <div class="flex items-center justify-between mt-4 py-4 border-t border-gray-100 dark:border-gray-700">
                        <div class="text-center">
                            <span class="block text-lg font-bold text-gray-800 dark:text-gray-200">{{ $uni->carreras_count ?? 0 }}</span>
                            <span class="text-xs text-gray-500">Carreras</span>
                        </div>
                        <div class="text-center border-l border-gray-200 dark:border-gray-700 pl-4">
                            <span class="block text-lg font-bold text-gray-800 dark:text-gray-200">{{ $uni->alumnos_count ?? 0 }}</span>
                            <span class="text-xs text-gray-500">Alumnos</span>
                        </div>
                    </div>

                    <div class="flex gap-2 mt-2">

                        <a href="{{ route('su.uni.ca', ['uni_id' => $uni->id]) }}" class="flex-1 flex items-center justify-center bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 py-2 rounded-lg text-sm font-medium hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition">
                            Ver Carreras
                        </a>
                        <div class="relative inline-block text-left dropdown-container">
                            
                            <button onclick="toggleDropdown('dropdown-uni-{{ $uni->id }}')" class="p-2 h-full w-10 flex items-center justify-center text-gray-400 hover:text-indigo-600 dark:hover:text-white transition focus:outline-none rounded-lg">
                                <i class="fas fa-cog"></i>
                            </button>

                            <div id="dropdown-uni-{{ $uni->id }}" class="hidden origin-bottom-right absolute bottom-full right-0 mb-2 w-48 rounded-xl shadow-lg bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 ring-1 ring-black ring-opacity-5 focus:outline-none z-[100] transition-all duration-200 opacity-0 scale-95">
                                <div class="py-1" role="menu" aria-orientation="vertical">

                                    <button onclick="openEditUniModal('{{ route('su.uni.update', $uni->id) }}', '{{ $uni->nombre }}', '{{ $uni->siglas }}', '{{ $uni->dominio }}', '{{ $uni->color_primario }}')" class="w-full text-left group flex items-center px-4 py-2.5 text-sm text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors font-medium" role="menuitem">
                                        <i class="fas fa-edit mr-3 text-center w-4"></i>
                                        Editar
                                    </button>
                                    
                                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>

                                    <button class="w-full text-left group flex items-center px-4 py-2.5 text-sm text-yellow-600 dark:text-yellow-500 hover:bg-yellow-50 dark:hover:bg-yellow-900/30 transition-colors font-medium" role="menuitem">
                                        <i class="fas fa-ban mr-3 text-center w-4"></i>
                                        Desactivar
                                    </button>
                                    
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @endforeach

        </div>

    </main>
</div>

<div id="add-university-modal" class="hidden fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm transition-opacity duration-300" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen py-4 px-4 text-center sm:block sm:p-0 items-center">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="toggleModal('add-university-modal')"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        
        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-[1.5rem] text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
            
            <form action="{{ route('su.uni.store') }}" method="POST">
                @csrf
                
                <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 dark:bg-indigo-900 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-university text-indigo-600 dark:text-indigo-400"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white" id="modal-title">Agregar Nueva Universidad</h3>
                            
                            <div class="mt-4 space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nombre de la Institución <span class="text-red-500">*</span></label>
                                    <input type="text" name="nombre" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border" placeholder="Ej. Universidad de El Salvador" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Siglas / Acrónimo</label>
                                    <input type="text" name="siglas" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border" placeholder="Ej. UES">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Dominio <span class="text-red-500">*</span></label>
                                    <input type="text" name="dominio" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border" placeholder="Ej. ues.edu.sv" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Color Primario (Hex)</label>
                                    <div class="flex items-center mt-1">
                                        <input type="color" id="colorPickerAdd" class="h-8 w-8 rounded cursor-pointer border-0 p-0 mr-2 bg-transparent" value="#4F46E5" oninput="document.getElementById('colorHexAdd').value = this.value">
                                        
                                        <input type="text" id="colorHexAdd" name="color_primario" class="flex-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border uppercase" value="#4F46E5" oninput="document.getElementById('colorPickerAdd').value = this.value" maxlength="7">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">
                        Guardar
                    </button>
                    <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-800 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" onclick="toggleModal('add-university-modal')">
                        Cancelar
                    </button>
                </div>
                
            </form>
        </div>
    </div>
</div>

<div id="edit-university-modal" class="hidden fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm transition-opacity duration-300" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen py-4 px-4 text-center sm:block sm:p-0 items-center">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="toggleModal('edit-university-modal')"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
        
        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-[1.5rem] text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
            
            <form id="edit-uni-form" action="" method="POST">
                @csrf
                @method('PUT') <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 dark:bg-indigo-900 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-edit text-indigo-600 dark:text-indigo-400"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white" id="modal-title">Editar Universidad</h3>
                            
                            <div class="mt-4 space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nombre de la Institución <span class="text-red-500">*</span></label>
                                    <input type="text" id="edit_nombre" name="nombre" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Siglas / Acrónimo</label>
                                    <input type="text" id="edit_siglas" name="siglas" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Dominio <span class="text-red-500">*</span></label>
                                    <input type="text" id="edit_dominio" name="dominio" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Color Primario (Hex)</label>
                                    <div class="flex items-center mt-1">
                                        <input type="color" id="edit_colorPicker" class="h-8 w-8 rounded cursor-pointer border-0 p-0 mr-2 bg-transparent" oninput="document.getElementById('edit_colorHex').value = this.value">
                                        <input type="text" id="edit_colorHex" name="color_primario" class="flex-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white sm:text-sm px-3 py-2 border uppercase" oninput="document.getElementById('edit_colorPicker').value = this.value" maxlength="7">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">
                        Actualizar
                    </button>
                    <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-800 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" onclick="toggleModal('edit-university-modal')">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
	// --- LOGICA MODAL ---
    function toggleModal(modalID) {
        document.getElementById(modalID).classList.toggle("hidden");
    }

    function openEditUniModal(url, nombre, siglas, dominio, color) {
        // 1. CERRAR EL MENÚ DESPLEGABLE ANTES DE ABRIR EL MODAL
        document.querySelectorAll('[id^="dropdown-uni-"]').forEach(dropdown => {
            if (!dropdown.classList.contains('hidden')) {
                dropdown.classList.remove('opacity-100', 'scale-100');
                dropdown.classList.add('opacity-0', 'scale-95');
                setTimeout(() => dropdown.classList.add('hidden'), 200);
            }
        });

        // 2. Ponemos la URL correcta en el formulario
        document.getElementById('edit-uni-form').action = url;
        
        // 3. Llenamos los inputs con los datos de la base de datos
        document.getElementById('edit_nombre').value = nombre;
        document.getElementById('edit_siglas').value = siglas;
        document.getElementById('edit_dominio').value = dominio;
        
        // 4. Llenamos los campos de color
        const finalColor = color || '#4F46E5'; 
        document.getElementById('edit_colorPicker').value = finalColor;
        document.getElementById('edit_colorHex').value = finalColor;
        
        // 5. Abrimos el modal
        toggleModal('edit-university-modal');
    }

    function toggleDropdown(id) {
        const dropdown = document.getElementById(id);
        
        // Cierra cualquier otro dropdown que esté abierto
        document.querySelectorAll('[id^="dropdown-uni-"]').forEach(el => {
            if (el.id !== id) {
                el.classList.add('hidden');
                el.classList.remove('opacity-100', 'scale-100');
            }
        });

        if (dropdown.classList.contains('hidden')) {
            dropdown.classList.remove('hidden');
            setTimeout(() => {
                dropdown.classList.remove('opacity-0', 'scale-95');
                dropdown.classList.add('opacity-100', 'scale-100');
            }, 10);
        } else {
            dropdown.classList.remove('opacity-100', 'scale-100');
            dropdown.classList.add('opacity-0', 'scale-95');
            setTimeout(() => {
                dropdown.classList.add('hidden');
            }, 200);
        }
    }

    // EL CÓDIGO MÁGICO PARA CERRAR AL HACER CLIC AFUERA
    document.addEventListener('click', function(event) {
        // Verificamos si el clic ocurrió fuera de nuestro contenedor
        if (!event.target.closest('.dropdown-container')) {
            
            // Si fue afuera, buscamos todos los dropdowns de universidades y los cerramos
            document.querySelectorAll('[id^="dropdown-uni-"]').forEach(dropdown => {
                if (!dropdown.classList.contains('hidden')) {
                    // Hacemos la animación de salida
                    dropdown.classList.remove('opacity-100', 'scale-100');
                    dropdown.classList.add('opacity-0', 'scale-95');
                    
                    // Lo ocultamos después de la animación
                    setTimeout(() => dropdown.classList.add('hidden'), 200);
                }
            });
        }
    });
</script>
@endsection