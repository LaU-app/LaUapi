<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lau - Iniciar Sesión</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config = { darkMode: 'class' } </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        /* ... tus estilos previos ... */

        /* 1. MODO CLARO: Fondo Blanco y Texto Gris Oscuro */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus, 
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px white inset !important; /* Tapa el fondo amarillo con blanco */
            -webkit-text-fill-color: #111827 !important; /* Color del texto (gray-900) */
            transition: background-color 5000s ease-in-out 0s; /* Evita parpadeos */
        }

        /* 2. MODO OSCURO: Fondo Gris Oscuro y Texto Blanco */
        /* Usamos la clase .dark que Tailwind agrega al <html> o <body> */
        .dark input:-webkit-autofill,
        .dark input:-webkit-autofill:hover, 
        .dark input:-webkit-autofill:focus, 
        .dark input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px #1f2937 inset !important; /* Tapa con gris oscuro (gray-800) */
            -webkit-text-fill-color: #ffffff !important; /* Texto blanco */
            caret-color: white; /* Color del cursor parpadeante */
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-300">

    <div class="flex min-h-screen">

        <div class="hidden lg:flex lg:w-1/2 bg-indigo-900 relative items-center justify-center overflow-hidden">
            
            <div class="absolute top-0 left-0 w-64 h-64 bg-indigo-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 -translate-x-1/2 -translate-y-1/2"></div>
            <div class="absolute bottom-0 right-0 w-64 h-64 bg-blue-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 translate-x-1/2 translate-y-1/2"></div>
            
            <div class="z-10 text-center px-12">
                <img src="https://res.cloudinary.com/dtmemrt1j/image/upload/v1764889353/Frame_1_3_hz7gdd.png" alt="Lau Logo" class="h-32 w-auto mx-auto mb-8 object-contain">
                
                <h2 class="text-3xl font-bold text-white mb-4">Bienvenido a Lau</h2>
                <p class="text-indigo-200 text-lg">La plataforma académica que conecta estudiantes, universidades y oportunidades en un solo lugar.</p>
            </div>
        </div>

        <div class="w-full lg:w-1/2 flex items-center justify-center p-8 bg-white dark:bg-gray-900 transition-colors">
            <div class="w-full max-w-md">
                
                <div class="lg:hidden text-center mb-8">
                    <img src="https://res.cloudinary.com/dtmemrt1j/image/upload/v1764889358/Frame_3_uc0a1v.png" class="h-16 mx-auto">
                </div>

                <div class="text-center mb-10">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">¡Hola de nuevo! 👋</h1>
                    <p class="text-gray-500 dark:text-gray-400">Ingresa tus credenciales para acceder al panel.</p>
                </div>

                <form class="space-y-6" method="POST" action="{{ route('su.us.lausess')}}"> 
                    @csrf
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Correo Electrónico</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-gray-400"></i>
                            </div>
                            <input type="email" id="email" name="email" class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-700 rounded-xl bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all" placeholder="admin@lau.app" required>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contraseña</label>
                            <a href="#" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">¿Olvidaste tu contraseña?</a>
                        </div>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input type="password" id="password" name="password" class="block w-full pl-10 pr-10 py-3 border border-gray-300 dark:border-gray-700 rounded-xl bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all" placeholder="••••••••" required>
                            
                            <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 cursor-pointer focus:outline-none">
                                <i class="fas fa-eye" id="eye-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center">
                        <input id="remember-me" name="remember-me" type="checkbox" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded cursor-pointer">
                        <label for="remember-me" class="ml-2 block text-sm text-gray-900 dark:text-gray-300 cursor-pointer">
                            Recordar dispositivo
                        </label>
                    </div>

                    <div>
                        <button type="submit" class="w-full flex justify-center py-3.5 px-4 border border-transparent rounded-xl shadow-lg text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all transform hover:-translate-y-0.5">
                            Iniciar Sesión
                        </button>
                    </div>
                </form>

<!--                 <p class="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    ¿No tienes una cuenta? 
                    <a href="#" class="font-bold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Solicitar acceso</a>
                </p> -->
                
                <div class="mt-8 flex justify-center">
                     <button id="theme-toggle" class="p-2 rounded-full text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                        <i id="theme-icon" class="fas fa-moon"></i>
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script>
        // 1. Lógica Ver/Ocultar Contraseña
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }

        // 2. Lógica Modo Oscuro (Misma que en el Dashboard)
        const html = document.documentElement;
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');

        // Chequear preferencia inicial
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark');
            themeIcon.classList.replace('fa-moon', 'fa-sun');
        } else {
            html.classList.remove('dark');
        }

        themeToggle.addEventListener('click', () => {
            html.classList.toggle('dark');
            if (html.classList.contains('dark')) {
                localStorage.setItem('theme', 'dark');
                themeIcon.classList.replace('fa-moon', 'fa-sun');
            } else {
                localStorage.setItem('theme', 'light');
                themeIcon.classList.replace('fa-sun', 'fa-moon');
            }
        });
    </script>
</body>
</html>