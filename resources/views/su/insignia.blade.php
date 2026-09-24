@extends('layouts.app-su')

@section('title', 'Insignias')

@section('view-contenido')
<div class="flex-1 flex flex-col overflow-hidden">
    
    <header class="h-20 bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-4 md:px-8 transition-colors duration-300">
        <div class="flex items-center">
            <button id="open-sidebar-button" class="text-gray-500 dark:text-gray-200 focus:outline-none lg:hidden mr-4"><i class="fas fa-bars fa-2x"></i></button>
            <h2 class="text-xl md:text-2xl font-bold text-gray-700 dark:text-white">Librería de Insignias</h2>
        </div>
        <button onclick="toggleModal('create-badge-modal')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow transition flex items-center">
            <i class="fas fa-plus mr-2"></i> Nueva Insignia
        </button>
    </header>

    <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 md:p-8 transition-colors duration-300">
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">

		    @forelse ($insignias as $insignia)
		        @php
		            // Extraer las clases guardadas en 'bgicon'
		            // Ej: "bg-yellow-100 text-yellow-600"
		            // Necesitamos separar el color de fondo para la barra superior
		            // Un truco simple es mapear colores base si usas la lógica anterior
		            
		            // Si guardaste el string completo, lo usamos directo para el icono.
		            // Para la barra superior, podemos inferir el color o usar uno por defecto.
		            
		            $bgIconClass = $insignia->bgicon; 
		            
		            // Lógica simple para la barra superior basada en el color del icono
		            $barColor = 'bg-gray-400';
		            if (str_contains($bgIconClass, 'blue')) $barColor = 'bg-blue-500';
		            elseif (str_contains($bgIconClass, 'green')) $barColor = 'bg-green-500';
		            elseif (str_contains($bgIconClass, 'yellow')) $barColor = 'bg-yellow-400';
		            elseif (str_contains($bgIconClass, 'purple')) $barColor = 'bg-purple-500';
		            elseif (str_contains($bgIconClass, 'red')) $barColor = 'bg-red-500';
		        @endphp

		        <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] p-6 shadow-sm border border-gray-100 dark:border-gray-700 flex flex-col items-center text-center group hover:shadow-md transition relative overflow-hidden">
		            
		            <div class="absolute top-0 w-full h-1 {{ $barColor }}"></div>

		            {{-- Icono Dinámico --}}
		            <div class="h-16 w-16 rounded-full {{ $bgIconClass }} flex items-center justify-center text-3xl mb-4 group-hover:scale-110 transition-transform">
		                <i class="{{ $insignia->icono }}"></i>
		            </div>

		            {{-- Título y Descripción --}}
		            <h3 class="font-bold text-gray-800 dark:text-white text-lg">{{ $insignia->nombre }}</h3>
		            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-4 line-clamp-2">
		                {{ $insignia->descripcion ?? 'Sin descripción' }}
		            </p>

		            <div class="w-full pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
		                <span class="text-xs font-semibold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-2 py-1 rounded-full">
		                    <i class="fas fa-user mr-1"></i> {{ $insignia->users_count ?? 0 }}
		                </span>
       

		                <div class="flex gap-1">
	                        <button onclick="editInsignia({{ $insignia->id }})" class="text-gray-400 hover:text-indigo-600 transition">
			                    <i class="fas fa-pen"></i>
			                </button>

                            <button class="text-gray-400 hover:text-red-500 p-1 transition">
							    <i class="fas fa-trash"></i>
							</button>
	                    </div>
		            </div>
		        </div>

		    @empty
		        {{-- ESTADO VACÍO (Se muestra si no hay insignias) --}}
		        <div class="col-span-full flex flex-col items-center justify-center p-10 text-gray-400 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-2xl">
		            <i class="fas fa-folder-open fa-3x mb-4 opacity-50"></i>
		            <p>No hay insignias creadas aún.</p>
		        </div>
		    @endforelse

		</div>
    </main>
</div>

<div id="create-badge-modal" class="hidden fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="toggleModal('create-badge-modal')"></div>
        
        <div class="bg-white dark:bg-gray-800 rounded-[2rem] shadow-2xl max-w-2xl w-full relative z-10 overflow-hidden transform transition-all">
            
            <div class="flex flex-col md:flex-row">
                <div class="w-full md:w-3/5 p-8 border-b md:border-b-0 md:border-r border-gray-100 dark:border-gray-700">
				    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-6">Crear Nueva Insignia</h3>

				    <form id="badgeForm" action="{{ route('su.insig.create') }}" method="POST" class="space-y-5">
				        @csrf
				        
				        {{-- INPUT OCULTO PARA EL COLOR (bgicon) --}}
				        <input type="hidden" name="bgicon" id="inputBgIcon" value="bg-blue-100 text-blue-600">

				        <div>
				            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Nombre</label>
				            <input type="text" name="nombre" id="inputName" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-2 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Ej. Super Lector" oninput="updateBadgePreview()">
				        </div>

				        <div>
				            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Color</label>
				            <div class="flex gap-3">
				                <label class="cursor-pointer"><input type="radio" name="color_selector" value="blue" class="peer sr-only" checked onchange="updateBadgePreview()"><div class="w-8 h-8 rounded-full bg-blue-500 peer-checked:ring-4 ring-blue-200 dark:ring-blue-900"></div></label>
				                <label class="cursor-pointer"><input type="radio" name="color_selector" value="green" class="peer sr-only" onchange="updateBadgePreview()"><div class="w-8 h-8 rounded-full bg-green-500 peer-checked:ring-4 ring-green-200 dark:ring-green-900"></div></label>
				                <label class="cursor-pointer"><input type="radio" name="color_selector" value="yellow" class="peer sr-only" onchange="updateBadgePreview()"><div class="w-8 h-8 rounded-full bg-yellow-400 peer-checked:ring-4 ring-yellow-200 dark:ring-yellow-900"></div></label>
				                <label class="cursor-pointer"><input type="radio" name="color_selector" value="purple" class="peer sr-only" onchange="updateBadgePreview()"><div class="w-8 h-8 rounded-full bg-purple-500 peer-checked:ring-4 ring-purple-200 dark:ring-purple-900"></div></label>
				                <label class="cursor-pointer"><input type="radio" name="color_selector" value="red" class="peer sr-only" onchange="updateBadgePreview()"><div class="w-8 h-8 rounded-full bg-red-500 peer-checked:ring-4 ring-red-200 dark:ring-red-900"></div></label>
				            </div>
				        </div>

				        <div>
				            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Icono (BoxIcons)</label>
				            {{-- name="icono" --}}
				            <select name="icono" id="inputIcon" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-2 text-gray-800 dark:text-white outline-none" onchange="updateBadgePreview()">
				                <option value="ti ti-mood-check">Mood Check</option>
				                <option value="fas fa-hands-helping">Mano Amiga</option>
				                <option value="bx bxs-badge-check">Verificado</option>
				                <option value="bx bx-crown">Corona</option>
				                <option value="bx bx-heart">Corazón</option>
				                <option value="bx bx-book">Libro</option>
				            </select>
				        </div>

				        <div>
				            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Descripción Corta</label>
				            {{-- name="descripcion" --}}
				            <textarea name="descripcion" id="inputDesc" rows="2" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-2 text-gray-800 dark:text-white resize-none outline-none" placeholder="Breve descripción..." oninput="updateBadgePreview()"></textarea>
				        </div>
				        
				        {{-- Botón Submit dentro del form --}}
				        <div class="mt-8 w-full flex flex-col md:flex-row gap-3">
				             <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl shadow-lg transition transform hover:-translate-y-0.5">Crear Insignia</button>

				             <button type="button" onclick="toggleModal('create-badge-modal')" class="w-full py-3 px-4 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-medium rounded-xl transition">Cancelar</button>
				        </div>
				    </form>
				</div>

                <div class="w-full md:w-2/5 bg-gray-50 dark:bg-gray-900/50 p-8 flex flex-col items-center justify-center text-center">
                    <p class="text-xs font-bold text-gray-400 uppercase mb-6 tracking-widest">Vista Previa</p>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-[1.5rem] p-6 shadow-xl w-full max-w-[200px] border border-gray-100 dark:border-gray-700 relative overflow-hidden transform transition-all duration-300">
                        <div id="preview-bar" class="absolute top-0 left-0 w-full h-1 bg-blue-500 transition-colors"></div>
                        
                        <div id="preview-icon-container" class="h-16 w-16 mx-auto rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-3xl mb-4 transition-colors">
                            <i id="preview-icon" class='bx bx-star'></i>
                        </div>
                        
                        <h3 id="preview-title" class="font-bold text-gray-800 dark:text-white text-lg leading-tight mb-1">Nombre</h3>
                        <p id="preview-desc" class="text-xs text-gray-500 dark:text-gray-400">Descripción...</p>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
    function toggleModal(id) { document.getElementById(id).classList.toggle("hidden"); }

    function updateBadgePreview() {
        const name = document.getElementById('inputName').value || 'Nombre';
        const desc = document.getElementById('inputDesc').value || 'Descripción...';
        const iconClass = document.getElementById('inputIcon').value;
        // Nota: cambié el name a color_selector en el HTML
        const color = document.querySelector('input[name="color_selector"]:checked').value;

        // Actualizar Textos
        document.getElementById('preview-title').innerText = name;
        document.getElementById('preview-desc').innerText = desc;

        // Actualizar Icono
        const iconEl = document.getElementById('preview-icon');
        iconEl.className = `${iconClass}`;

        // Actualizar Colores
        const bar = document.getElementById('preview-bar');
        const iconContainer = document.getElementById('preview-icon-container');

        const colors = {
            blue:   { bar: 'bg-blue-500', bg: 'bg-blue-100', text: 'text-blue-600' },
            green:  { bar: 'bg-green-500', bg: 'bg-green-100', text: 'text-green-600' },
            yellow: { bar: 'bg-yellow-400', bg: 'bg-yellow-100', text: 'text-yellow-600' },
            purple: { bar: 'bg-purple-500', bg: 'bg-purple-100', text: 'text-purple-600' },
            red:    { bar: 'bg-red-500', bg: 'bg-red-100', text: 'text-red-600' },
        };

        const selected = colors[color];

        // Actualizar Visual
        bar.className = `absolute top-0 left-0 w-full h-1 transition-colors ${selected.bar}`;
        iconContainer.className = `h-16 w-16 mx-auto rounded-full flex items-center justify-center text-3xl mb-4 transition-colors ${selected.bg} ${selected.text}`;

        // Guardamos el string completo: "bg-blue-100 text-blue-600"
        document.getElementById('inputBgIcon').value = `${selected.bg} ${selected.text}`;
    }
</script>
@endsection