function closeToast(element) {
    if (!element) return;
    
    // 1. Agregar clase de animación de salida
    element.classList.remove('animate-slide-in-right');
    element.classList.add('animate-slide-out-right');

    // 2. Esperar a que termine la animación
    setTimeout(() => {
        if (element && element.parentNode) {
            element.parentNode.removeChild(element);
        }
    }, 400); 
}
window.closeToast = closeToast;
// Inicialización Automática al cargar la página
document.addEventListener('DOMContentLoaded', () => {
    // Seleccionamos todas las alertas presentes
    const toasts = document.querySelectorAll('.toast-alert');

    toasts.forEach(toast => {
        // Configuramos el temporizador de 10 segundos (10000 ms) para cada una
        setTimeout(() => {
            closeToast(toast);
        }, 6000);
    });
});

const html = document.documentElement;
const themeToggle = document.getElementById('theme-toggle');
const themeIcon = document.getElementById('theme-icon');
const themeText = document.getElementById('theme-text');

const sidebar = document.getElementById('sidebar');
const openSidebarButton = document.getElementById('open-sidebar-button');
const closeSidebarButton = document.getElementById('close-sidebar-button');
const sidebarBackdrop = document.getElementById('sidebar-backdrop');

function updateThemeUI(isDark) {
    if (isDark) {
        themeIcon.classList.remove('fa-moon');
        themeIcon.classList.add('fa-sun');
        themeText.textContent = "Modo Claro";
    } else {
        themeIcon.classList.remove('fa-sun');
        themeIcon.classList.add('fa-moon');
        themeText.textContent = "Modo Oscuro";
    }
}

if (html.classList.contains('dark')) {
    updateThemeUI(true);
} else {
    updateThemeUI(false);
}

// Evento Click
themeToggle.addEventListener('click', () => {
    html.classList.toggle('dark');
    const isDark = html.classList.contains('dark');
    
    // Guardamos la preferencia en LocalStorage
    if (isDark) {
        localStorage.setItem('theme', 'dark');
    } else {
        localStorage.setItem('theme', 'light');
    }

    updateThemeUI(isDark);
});


// --- FUNCIONES DE SIDEBAR (MÓVIL) ---
function openSidebar() {
    sidebar.classList.remove('-translate-x-full');
    sidebarBackdrop.classList.remove('hidden');
}
function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    sidebarBackdrop.classList.add('hidden');
}

openSidebarButton.addEventListener('click', openSidebar);
sidebarBackdrop.addEventListener('click', closeSidebar);