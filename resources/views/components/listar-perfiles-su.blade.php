<div class="flex flex-col w-full space-y-1">
    @if (isset($users) && $users->count())
        @foreach ($users as $user)
            {{-- Lógica para el estado Activo/Inactivo: El primero sale activo por defecto --}}
            @php
                $activeClass = 'bg-indigo-50 dark:bg-indigo-900/30 border-l-4 border-indigo-600';
                $inactiveClass = 'hover:bg-gray-50 dark:hover:bg-gray-700 border-l-4 border-transparent hover:border-gray-300';
                $currentClass = $loop->first ? $activeClass : $inactiveClass;
                
                // Texto de profesión o default
                $profession = $user->profession ?? 'Sin profesión';
                
                // Imagen de perfil
                $imageSrc = $user->imagen ? asset('perfiles/' . $user->imagen) : "https://ui-avatars.com/api/?name=".urlencode($user->name)."&background=random";
            @endphp

            <button 
                onclick="showUser('{{ $user->id }}')" 
                id="btn-{{ $user->id }}" 
                class="user-btn w-full flex items-center p-3 rounded-lg transition-all group {{ $currentClass }}">
                
                {{-- Sección de Imagen --}}
                <div class="relative mr-3">
                    <img src="{{ $imageSrc }}" 
                         alt="Avatar de {{ $user->name }}" 
                         class="h-10 w-10 rounded-full object-cover">
                    
                    {{-- Indicador de estado (puedes añadir lógica si tienes campo 'online') --}}
                    <span class="absolute bottom-0 right-0 block h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-gray-800 bg-green-400"></span>
                </div>

                {{-- Sección de Texto --}}
                <div class="text-left flex-1 min-w-0">
                    <div class="flex justify-between items-center">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-white truncate">
                            {{ $user->name ?? $user->username }}
                        </h4>
                        
                        {{-- Tiempo (Opcional: usa diffForHumans si tienes created_at o last_seen) --}}
                        <span class="text-[10px] text-gray-400">
                            Hace 2m
                        </span>
                    </div>
                    
                    <p id="text-{{ $user->id }}" 
                        class="user-role-text text-xs truncate transition-colors group-hover:text-indigo-600 
                        {{ $loop->first ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500' }}">
                       {{-- Muestra: "Ing. Sistemas - UES" o "Sin Información" --}}
                        @if($user->carrera && $user->universidad)
                            {{ $user->carrera->nombre }}
                        @else
                            Información académica no disponible
                        @endif
                    </p>
                </div>
            </button>
        @endforeach
    @else
        {{-- Estado Vacío --}}
        <div class="p-4 text-center">
            <p class="text-sm text-gray-500">No se encontraron perfiles.</p>
        </div>
    @endif
</div>