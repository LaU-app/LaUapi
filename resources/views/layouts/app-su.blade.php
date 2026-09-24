<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="theme-color" content="">
    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('img/icon.svg') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @stack('styles')
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" />
    <title>Lau -- @yield('title', 'Panel')</title>
    @vite(['resources/js/script-lau.js'])

    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia(
                '(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

    <meta name="csrf-token" content="{{ csrf_token() }}">
    @livewireStyles()
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            /* Ancho delgado y elegante */
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
            /* Fondo transparente */
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            /* gris claro (slate-300) */
            border-radius: 20px;
            /* bordes redondeados */
        }

        /* Estilos para Modo Oscuro (si tu HTML tiene class="dark") */
        .dark .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #4b5563;
            /* gris oscuro (gray-600) */
        }

        /* Hover: cuando pasas el mouse sobre la barra */
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background-color: #6366f1;
            /* indigo-500 (Tu color de acento) */
        }

        /* Estilos para Firefox */
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .dark .custom-scrollbar {
            scrollbar-color: #4b5563 transparent;
        }

        .preview-bg {
            background-color: #f3f4f6;
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 20px 20px;
        }

        .dark .preview-bg {
            background-color: #111827;
            background-image: radial-gradient(#374151 1px, transparent 1px);
        }

        /* Animaciones de transición */
        .view-transition {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* 1. MODO CLARO: Fondo Blanco y Texto Gris Oscuro */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            /* Tapa el fondo amarillo con blanco */
            -webkit-text-fill-color: #111827 !important;
            /* Color del texto (gray-900) */
            transition: background-color 5000s ease-in-out 0s;
            /* Evita parpadeos */
        }

        /* 2. MODO OSCURO: Fondo Gris Oscuro y Texto Blanco */
        .dark input:-webkit-autofill,
        .dark input:-webkit-autofill:hover,
        .dark input:-webkit-autofill:focus,
        .dark input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px #1f2937 inset !important;
            /* Tapa con gris oscuro (gray-800) */
            -webkit-text-fill-color: #ffffff !important;
            /* Texto blanco */
            caret-color: white;
            /* Color del cursor parpadeante */
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .animate-slide-in-right {
            animation: slideInRight 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        /* Animación de Salida (Nueva) */
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .animate-slide-out-right {
            animation: slideOutRight 0.4s ease-in forwards;
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-800 dark:bg-gray-900 dark:text-gray-100 transition-colors duration-300">

    <!-- Alertas -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-3 w-full max-w-sm pointer-events-none">
        {{-- Alerta de exito --}}
        @if (session('success'))
            <div id="toast-success"
                class="toast-alert pointer-events-auto bg-white dark:bg-gray-800 border-l-4 border-green-500 rounded-xl shadow-2xl overflow-hidden transform transition-all hover:scale-105 duration-300 flex items-start p-4 animate-slide-in-right">
                <div class="flex-shrink-0">
                    <div
                        class="h-8 w-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-500">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="ml-3 w-0 flex-1 pt-0.5">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">¡Éxito!</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ session('success') }}</p>
                </div>
                <div class="ml-4 flex-shrink-0 flex">
                    <button onclick="closeToast(this.closest('.toast-alert'))"
                        class="bg-white dark:bg-gray-800 rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        @endif
        {{-- Alerta de validación --}}
        @if ($errors->any())
            <div
                class="toast-alert pointer-events-auto bg-white dark:bg-gray-800 border-l-4 border-yellow-500 rounded-xl shadow-2xl overflow-hidden transform transition-all hover:scale-105 duration-300 flex items-start p-4 animate-slide-in-right">
                <div class="flex-shrink-0">
                    <div
                        class="h-8 w-8 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center text-yellow-500">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                </div>
                <div class="ml-3 w-0 flex-1 pt-0.5">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">¡Aviso!</p>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <ul class="list-disc pl-4">
                            {{-- Listamos todos los errores de validación --}}
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="ml-4 flex-shrink-0 flex">
                    <button onclick="closeToast(this.closest('.toast-alert'))"
                        class="bg-white dark:bg-gray-800 rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        @endif
        {{-- Alerta de error --}}
        @if (session('error'))
            <div id="toast-error"
                class="toast-alert pointer-events-auto bg-white dark:bg-gray-800 border-l-4 border-red-500 rounded-xl shadow-2xl overflow-hidden transform transition-all hover:scale-105 duration-300 flex items-start p-4 animate-slide-in-right">
                <div class="flex-shrink-0">
                    <div
                        class="h-8 w-8 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center text-red-500">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                </div>
                <div class="ml-3 w-0 flex-1 pt-0.5">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">Error</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ session('error') }}</p>
                </div>
                <div class="ml-4 flex-shrink-0 flex">
                    <button onclick="closeToast(this.closest('.toast-alert'))"
                        class="bg-white dark:bg-gray-800 rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        @endif

    </div>
    <!-- fin alerta -->

    <div class="flex h-screen overflow-hidden relative">

        <aside id="sidebar"
            class="fixed inset-y-0 left-0 z-40 w-64 bg-indigo-900 text-white flex flex-col shadow-xl transform -translate-x-full transition-transform duration-300 ease-in-out lg:relative lg:translate-x-0 lg:z-auto">
            <div class="h-20 flex items-center justify-center border-b border-indigo-800 px-4 relative">
                <img src="https://res.cloudinary.com/dtmemrt1j/image/upload/v1764889353/Frame_1_3_hz7gdd.png"
                    alt="Lau Logo" class="h-14 w-auto object-contain transition-transform hover:scale-105">
            </div>

            <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">
                <a href="{{ route('su.dash') }}"
                    class="flex items-center px-4 py-3 rounded-lg 
                @if (Route::is('su.dash')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                ">
                    <i class="fas fa-chart-line w-6"></i> <span class="font-medium">Dashboard</span>
                </a>

                <p class="px-4 text-xs font-semibold text-indigo-400 uppercase mt-4">Académico</p>
                <a href="{{ route('su.uni') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.uni')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-university w-6"></i> <span>Universidades</span>
                </a>
                <a href="{{ route('su.uni.ca') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.uni.ca')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-book w-6"></i> <span>Carreras</span>
                </a>

                <p class="px-4 text-xs font-semibold text-indigo-400 uppercase mt-4">Comunidad</p>
                <a href="{{ route('su.insig') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.insig')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-medal w-6"></i> <span>Insignias</span>
                </a>

                {{-- Rutas de Anuncios y Usuarios ya conectadas --}}
                <a href="{{ route('su.ads') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.ads')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-bullhorn w-6"></i> <span>Anuncios</span>
                </a>
                <a href="{{ route('su.usuarios') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.usuarios') || Route::is('su.info')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-users w-6"></i> <span>Usuarios</span>
                </a>

                <p class="px-4 text-xs font-semibold text-indigo-400 uppercase mt-4">Gestión</p>
                <a href="{{ route('su.reportes') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.reportes')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-file-alt w-6"></i> <span>Reportes</span>
                    <span
                        class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ \App\Models\Reporte::where('estado', 'pendiente')->count() }}</span>
                </a>

                <a href="{{ route('su.updates.index') }}"
                    class="flex items-center px-4 py-2 rounded-lg 
                 @if (Route::is('su.reportes')) bg-indigo-800 text-white @else text-indigo-100 hover:bg-indigo-800 hover:text-white transition @endif
                 ">
                    <i class="fas fa-file-alt w-6"></i> <span>Reportes</span>
                </a>
            </nav>

            <div class="px-4 pb-2">
                <button id="theme-toggle"
                    class="flex items-center justify-center w-full px-4 py-2 text-sm text-indigo-200 bg-indigo-800/50 border border-indigo-700 rounded-lg hover:bg-indigo-700 hover:text-white transition-colors">
                    <i id="theme-icon" class="fas fa-moon mr-2"></i>
                    <span id="theme-text">Modo Oscuro</span>
                </button>
            </div>

            <div
                class="p-4 border-t border-indigo-800 flex items-center bg-indigo-900 relative group cursor-pointer transition-colors hover:bg-indigo-800">

                <div class="flex items-center flex-1 min-w-0">
                    <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::guard('super')->user()->name ?? 'Admin') }}&background=random"
                        class="h-10 w-10 rounded-full shrink-0">
                    <div class="ml-3 overflow-hidden">
                        <p class="text-sm font-medium text-white truncate">
                            {{ Auth::guard('super')->user()->name ?? 'Administrador' }}</p>
                        <p class="text-xs text-indigo-300 truncate">
                            {{ Auth::guard('super')->user()->email ?? 'admin@lau.app' }}</p>
                    </div>
                </div>

                <form action="{{ route('logoutus') }}" method="POST">
                    @csrf
                    <button
                        class="ml-2 p-2 text-red-400 hover:text-white hover:bg-red-500 rounded-lg transition-all duration-300 opacity-100 lg:opacity-0 lg:group-hover:opacity-100"
                        title="Cerrar Sesión">
                        <i class="fas fa-sign-out-alt fa-lg"></i>
                    </button>
                </form>
            </div>
        </aside>

        <div id="sidebar-backdrop"
            class="fixed inset-0 bg-gray-900 bg-opacity-50 z-30 hidden lg:hidden transition-opacity duration-300">
        </div>

        @yield('view-contenido')

    </div>

    <script>
        // Función para cerrar una alerta específica
        function closeToast(element) {
            if (!element) return;

            // 1. Agregar clase de animación de salida
            element.classList.remove('animate-slide-in-right'); // Quitamos la de entrada por si acaso
            element.classList.add('animate-slide-out-right'); // Agregamos la de salida

            // 2. Esperar a que termine la animación (400ms) y eliminar del HTML
            setTimeout(() => {
                if (element && element.parentNode) {
                    element.parentNode.removeChild(element);
                }
            }, 400);
        }

        // Inicialización Automática al cargar la página
        document.addEventListener('DOMContentLoaded', () => {
            // Seleccionamos todas las alertas presentes
            const toasts = document.querySelectorAll('.toast-alert');

            toasts.forEach(toast => {
                // Configuramos el temporizador de 10 segundos (10000 ms) para cada una
                setTimeout(() => {
                    closeToast(toast);
                }, 10000);
            });
        });
    </script>

    <style>
        /* Animación de Entrada */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .animate-slide-in-right {
            animation: slideInRight 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        /* Animación de Salida (Nueva) */
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .animate-slide-out-right {
            animation: slideOutRight 0.4s ease-in forwards;
        }
    </style>
</body>

</html>
