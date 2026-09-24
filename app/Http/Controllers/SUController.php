<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\su_ad;
use App\Models\User;
use App\Models\Universidad;
use App\Models\Carrera;
use App\Models\Banner;
use App\Models\Insignia;
use App\Models\Reporte;
use App\Models\AppUpdate;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Validation\ValidatesRequests;

class SUController extends Controller
{
    use ValidatesRequests;

    public function login()
    {
        return view('su.login');
    }

    public function storelau(Request $request)
    {
        $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 1️⃣ Verificar credenciales en superusuarios
        if (Auth::guard('super')->attempt($request->only('email', 'password'), $request->remember)) {
            return redirect()->route('su.dash');
        }

        // 3️⃣ Si nada coincide → error
        return back()
            ->with('status', 'Las credenciales no coinciden')
            ->withInput($request->only('email'));
    }

    public function storeus()
    {
        if (Auth::guard('super')->check()) {
            Auth::guard('super')->logout();
        }

        return redirect()->route('su.us.laulogin');
    }

    public function dashboard()
    {
        $users = \App\Models\User::latest()->get();
        $totalUsers = $users->count();
        $activeCount = Banner::where('is_active', true)->count();
        $reportesPendientes = Reporte::where('estado', 'pendiente')->count();

        return view('su.dashboard', [
            'users' => $users,
            'totalUsers' => $totalUsers,
            'activeCount' => $activeCount,
            'reportesPendientes' => $reportesPendientes,
        ]);
    }

    public function universidad()
    {
        // Consultamos todas las universidades.
        // Usamos withCount('carreras') para que Laravel genere automáticamente la propiedad $uni->carreras_count
        $universidades = Universidad::withCount(['carreras', 'alumnos'])->get();

        // Pasamos la variable a la vista usando compact()
        return view('su.universidad', compact('universidades'));
    }

    public function storeuni(Request $request)
    {
        // 1. Validar los datos
        $request->validate([
            'nombre' => 'required|string|max:255',
            'dominio' => 'required|string|max:255|unique:universidades,dominio', // Evita dominios duplicados
            'siglas' => 'nullable|string|max:20',
            'color_primario' => 'nullable|string|max:7', // Ej: #FF0000
        ], [
            'dominio.unique' => 'Ya existe una universidad registrada con este dominio.'
        ]);

        // 2. Guardar en la base de datos
        Universidad::create([
            'nombre' => $request->nombre,
            'dominio' => $request->dominio,
            'siglas' => $request->siglas,
            'color_primario' => $request->color_primario,
        ]);

        // 3. Redirigir con mensaje de éxito
        return redirect()->back()->with('success', 'Universidad agregada correctamente.');
    }

    public function updateUni(Request $request, $id)
    {
        // 1. Buscamos la universidad en la base de datos
        $universidad = Universidad::findOrFail($id);

        // 2. Validamos los datos entrantes
        $request->validate([
            'nombre' => 'required|string|max:255',
            'siglas' => 'nullable|string|max:20',
            'color_primario' => 'nullable|string|max:7',
            /*
             * LA REGLA DEL DOMINIO:
             * unique:universidades,dominio,' . $id
             * Esto significa: "Asegúrate de que el dominio sea único en la tabla universidades, 
             * EXCEPTO si pertenece a la universidad con este $id (la que estoy editando)".
             */
            'dominio' => 'required|string|max:255|unique:universidades,dominio,' . $id,
        ], [
            'dominio.unique' => 'Ya existe otra universidad registrada con este dominio.'
        ]);

        // 3. Actualizamos los datos
        $universidad->update([
            'nombre' => $request->nombre,
            'siglas' => $request->siglas,
            'dominio' => $request->dominio,
            'color_primario' => $request->color_primario,
        ]);

        // 4. Redirigimos con mensaje de éxito
        return redirect()->back()->with('success', 'Universidad actualizada correctamente.');
    }

    public function carrera(Request $request)
    {
        // Traemos todas las universidades con sus carreras
        $universidades = Universidad::with(['carreras' => function ($query) {
            $query->withCount('users');
        }])->get();

        $todasLasCarreras = Carrera::orderBy('nombre')->get();

        // Obtenemos el ID de la URL (si existe) para marcarlo como activo por defecto
        $activeUniId = $request->query('uni_id', $universidades->first()->id ?? null);

        return view('su.carreras', compact('universidades', 'todasLasCarreras', 'activeUniId'));
    }

    public function storeCarrera(Request $request)
    {
        // 1. Validamos que el nombre venga y que no exista ya en la tabla 'carreras'
        $request->validate([
            'nombre' => 'required|string|max:255|unique:carreras,nombre',
        ], [
            'nombre.unique' => 'Esta carrera ya existe en el catálogo general.'
        ]);

        // 2. Creamos la carrera
        Carrera::create([
            'nombre' => $request->nombre,
        ]);

        // 3. Regresamos a la misma vista
        // Como no le pasamos 'uni_id', se quedará en la pestaña que estaba por defecto
        return redirect()->back()->with('success', 'Carrera agregada al catálogo exitosamente.');
    }

    public function assignCarrera(Request $request)
    {
        // 1. Validamos que nos manden los dos IDs y que existan en la base de datos
        $request->validate([
            'universidad_id' => 'required|exists:universidades,id',
            'carrera_id'     => 'required|exists:carreras,id',
        ]);

        // 2. Buscamos la universidad
        $universidad = Universidad::findOrFail($request->universidad_id);

        // 3. LA MAGIA PIVOTE: 
        // syncWithoutDetaching enlaza la carrera con la universidad sin borrar las que ya tenía.
        // Además, si el usuario intenta vincular una carrera que ya estaba vinculada, 
        // Laravel lo ignora y no crea duplicados.
        $universidad->carreras()->syncWithoutDetaching([$request->carrera_id]);

        // 4. Redirigimos a la vista de carreras PERO le pasamos el ID de la universidad
        // Esto activará nuestro Javascript y dejará abierta la pestaña correcta.
        return redirect()->route('su.uni.ca', ['uni_id' => $universidad->id])
            ->with('success', 'Carrera vinculada exitosamente a ' . ($universidad->siglas ?? $universidad->nombre));
    }

    // --- NUEVAS FUNCIONES DE USUARIOS ---

    public function userperfil(User $user)
    {
        $insignia = Insignia::latest()->get();
        $authUser = Auth::user();
        $users = User::with(['universidad', 'carrera', 'insignias'])
            ->withCount(['posts', 'followers', 'reportesRecibidos'])
            ->latest()
            ->get();

        $totalUsers = $users->count();

        return view('su.usuarios', [
            'users' => $users,
            'authUser' => $authUser,
            'totalUsers' => $totalUsers,
            'insignia' => $insignia,
        ]);
    }

    public function buscarUsuarios(Request $request)
    {
        $query = $request->input('buscar');
        $users = User::when($query, function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('username', 'like', "%{$query}%");
        })->get();

        return view('components.listar-perfiles-su', compact('users'));
    }

    public function reportes()
    {
        $reportes = Reporte::with(['reporter', 'reportedUser'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('su.reportes', compact('reportes'));
    }

    // ------------------------------------

    public function store(Request $request)
    {
        // Simulación: datos de usuario definidos directamente en PHP
        $user = su_ad::create([
            'name' => 'Mateyo Admin',
            'username' => 'mateyo',
            'email' => 'mateyo@example.com',
            'password' => bcrypt('./54777uSiVAra-l'),
            'is_admin' => true, // admin = 1
            'last_login' => now(), // fecha actual
            'password_verific_modify' => bcrypt('.//5777u51VAr-s0'),
            'imagen' => '8dbd213d-9d8f-48bb-a24e-e9b1a2be793f.jpg',
            'profession' => 'Desarrollador',
        ]);

        return "¡Registro de prueba exitoso! Usuario creado correctamente.";
    }

    public function info(User $user)
    {
        if (!$user->exists) {
            abort(404, 'Usuario no encontrado');
        }

        return view('su.perfil', [
            'user' => $user,
        ]);
    }

    // Agregar insignia al usuario
    public function addInsignia(Request $request)
    {
        // 1. Validar
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'badges'  => 'required', // Viene como string JSON: "[1, 5]"
        ]);

        // 2. Buscar Usuario
        $user = User::find($request->user_id);

        // 3. Decodificar el JSON a un array de IDs (Ej: [1, 5])
        $badgeIds = json_decode($request->badges);

        // 4. LA MAGIA: sync()
        // Esto crea tantas filas en 'insignia_user' como IDs haya en el array.
        $user->insignias()->sync($badgeIds);

        return back()->with('success', 'Insignias actualizadas correctamente.');
    }

    // Editar insignia del usuario
    public function editInsignia(Request $request, User $user)
    {
        $request->validate([
            'type' => 'required|string|in:Colaborador,Comunidad',
        ]);

        $user->insignia = $request->type;
        $user->save();

        return back()->with('success', 'Insignia actualizada correctamente.');
    }

    // Eliminar insignia del usuario
    public function deleteInsignia(Request $request, User $user)
    {
        $request->validate([
            'pass_verific' => 'required|string',
        ]);

        // Traemos al super usuario logueado
        $su = auth()->guard('super')->user(); // si usas un guard "su"

        // Verificamos contraseña
        if (!$su || !Hash::check($request->pass_verific, $su->password_verific_modify)) {
            return back()->withErrors(['pass_verific' => 'La contraseña no es correcta'])->withInput();
        }

        // Si la contraseña es correcta, eliminamos la insignia
        $user->insignia = null;
        $user->save();

        return back()->with('success', 'Insignia eliminada correctamente.');
    }

    // --- FUNCIONES DE ANUNCIOS (BANNERS) ---

    public function ads()
    {
        $banners = Banner::with('viewedByUsers')
            ->latest()
            ->get();
        $activeCount = $banners->where('is_active', true)->count();
        return view('su.anuncio', compact('banners', 'activeCount'));
    }

    public function resetViews($id)
    {
        $banner = Banner::findOrFail($id);

        // El método detach() sin argumentos elimina TODAS las relaciones en la tabla pivote para este modelo
        $banner->viewedByUsers()->detach();

        return back()->with('success', 'Las visualizaciones han sido reiniciadas. El anuncio volverá a mostrarse a todos.');
    }

    public function create(Request $request)
    {
        // Validación
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'type' => 'required|in:feature,update,info,warning', // Añadido 'warning' por si acaso
            'image_url' => 'nullable|string|max:255',
            'file' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:20480', // 20MB
            'action_text' => 'nullable|string|max:255',
            'action_url' => 'nullable|string|max:255',
            'is_active' => 'required|boolean',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        // Manejar subida de archivo
        $imagePath = $request->image_url; // por defecto URL
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('public/banners', $filename);
            $imagePath = '/storage/banners/' . $filename;
        }

        // Crear banner
        Banner::create([
            'title' => $request->input('title'),
            'content' => $request->input('content'),
            'type' => $request->input('type'),
            'image_url' => $imagePath,
            'action_text' => $request->input('action_text'),
            'action_url' => $request->input('action_url'),
            'is_active' => $request->input('is_active'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ]);

        return redirect()->route('su.ads')->with('success', 'Banner creado correctamente');
    }

    /**
     * Actualiza un banner existente en la base de datos.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id  El ID del banner que viene de la URL
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // 1. Buscar el banner existente
        $banner = Banner::findOrFail($id);

        // 2. Validar los datos recibidos
        $validatedData = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'content'     => ['required', 'string'],
            // Usamos la constante del modelo para asegurar que el tipo sea válido (info, feature, update, warning)
            'type'        => ['required', Rule::in(Banner::getAvailableTypes())],
            'image_url'   => ['nullable', 'url', 'max:500'],
            'action_text' => ['nullable', 'string', 'max:50'],
            'action_url'  => ['nullable', 'string', 'max:500'],
            'is_active'   => ['required', 'boolean'],
            'start_date'  => ['nullable', 'date'],
            'end_date'    => ['nullable', 'date', 'after_or_equal:start_date'],
        ], [
            'type.in' => 'El tipo de anuncio seleccionado no es válido.',
            'end_date.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
        ]);

        $banner->update($validatedData);

        return redirect()
            ->route('su.ads')
            ->with('success', '¡El anuncio se ha actualizado correctamente!');
    }

    public function delete($id)
    {
        $banner = Banner::findOrFail($id);
        $banner->delete();

        return back()->with('success', 'Banner eliminado');
    }

    // ------------------------------------

    public function insig()
    {
        $insignias = Insignia::withCount('users')
            ->latest()
            ->get();

        return view('su.insignia', compact('insignias'));
    }

    public function storeinsig(Request $request)
    {
        // 1. Validar
        $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'icono' => 'required|string',
            'bgicon' => 'required|string',
        ]);

        // 2. Crear
        Insignia::create([
            'nombre' => $request->nombre,
            'slug' => Str::slug($request->nombre), // Ej: "Super Lector" -> "super-lector"
            'descripcion' => $request->descripcion,
            'icono' => $request->icono,
            'bgicon' => $request->bgicon,
        ]);

        // 3. Redirigir
        return back()->with('success', 'Insignia creada correctamente');
    }

    public function destroy(Request $request, $id)
    {
        // 1. Validar que el campo de contraseña venga en el formulario
        $request->validate([
            'password_verific_modify' => 'required|string',
        ], [
            'password_verific_modify.required' => 'Debes ingresar tu contraseña para confirmar.'
        ]);

        // 2. Verificar que la contraseña del Admin (quien está logueado) sea correcta
        $admin = auth()->user(); // Obtiene el admin actual

        if (!Hash::check($request->password_verific_modify, $admin->password_verific_modify)) {
            // Si la contraseña no coincide, regresa atrás con un error
            return back()->withErrors(['password_verific_modify' => 'La contraseña de administrador es incorrecta.']);
        }

        // 3. Si la contraseña es correcta, buscar al usuario y eliminarlo
        $usuarioAEliminar = User::findOrFail($id);
        $usuarioAEliminar->delete();

        // 4. Redirigir con mensaje de éxito
        return redirect()->back()->with('success', 'Usuario eliminado permanentemente.');
    }

    // ✨ 1. MOSTRAR LA VISTA ✨
    public function updateindex()
    {
        // Traemos todas las actualizaciones ordenadas de la más nueva a la más vieja
        $updates = AppUpdate::orderBy('created_at', 'desc')->get();
        
        // Retornamos tu vista Blade (Ajusta 'su.updates.index' según tu estructura de carpetas)
        return view('su.updates', compact('updates'));
    }

    // ✨ 2. GUARDAR (El que ya teníamos) ✨
    public function storeup(Request $request)
    {
        $request->validate([
            'version' => 'required|string|unique:app_updates',
            'update_file' => 'required|file|mimes:zip|max:50000',
        ]);

        $path = $request->file('update_file')->storeAs(
            'updates', 
            'v' . $request->version . '.zip', 
            'public'
        );

        AppUpdate::query()->update(['is_active' => false]);

        AppUpdate::create([
            'version' => $request->version,
            'file_path' => $path,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Actualización subida y activada con éxito.');
    }

    // ✨ 3. ACTIVAR / ROLLBACK ✨
    // En lugar de editar, esta función cambia cuál es la versión que la app va a descargar
    public function activateup($id)
    {
        $update = AppUpdate::findOrFail($id);

        // 1. Desactivamos todas las versiones
        AppUpdate::query()->update(['is_active' => false]);

        // 2. Activamos solo la que el usuario seleccionó
        $update->update(['is_active' => true]);

        return redirect()->back()->with('success', 'La versión v' . $update->version . ' ha sido activada. Los usuarios ahora descargarán esta versión.');
    }

    // ✨ 4. ELIMINAR ✨
    public function destroyup($id)
    {
        $update = AppUpdate::findOrFail($id);

        // 1. Borramos el archivo físico (.zip) del VPS para liberar espacio
        if (Storage::disk('public')->exists($update->file_path)) {
            Storage::disk('public')->delete($update->file_path);
        }

        // 2. Borramos el registro de la base de datos
        $update->delete();

        return redirect()->back()->with('success', 'El archivo de la actualización fue eliminado del servidor de forma permanente.');
    }
}
