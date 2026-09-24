<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Universidad;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:15|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:aspirante,estudiante,egresado',
            'email' => 'required|email|unique:users',
            'carrera_id' => 'required|exists:carreras,id',
            'universidad_id' => [
                function ($attribute, $value, $fail) {
                    $role = $this->input('role');
                    // Universidad es requerida solo para estudiante/egresado
                    if (($role === 'estudiante' || $role === 'egresado') && !$value) {
                        $fail('Debes seleccionar una universidad');
                    }
                    // Si hay valor, validar que existe
                    if ($value && !Universidad::find($value)) {
                        $fail('La universidad seleccionada no existe');
                    }
                }
            ],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $role = $this->input('role');
            $email = $this->input('email');
            $universidadId = $this->input('universidad_id');

            // VALIDACIÓN 1: Aspirantes NO pueden usar correos .edu.sv
            if ($role === 'aspirante' && str_ends_with(strtolower($email), '.edu.sv')) {
                $validator->errors()->add('email', 'Los aspirantes no pueden usar correos institucionales (.edu.sv)');
                return; // Detener validaciones adicionales
            }

            // VALIDACIÓN 2: Estudiantes/Egresados DEBEN usar correo institucional de su universidad
            if (($role === 'estudiante' || $role === 'egresado') && $universidadId) {
                $universidad = Universidad::find($universidadId);
                if ($universidad && !str_ends_with(strtolower($email), strtolower($universidad->dominio))) {
                    $validator->errors()->add('email', "Solo se aceptan correos institucionales {$universidad->dominio}");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'carrera_id.required' => 'Debes seleccionar una carrera',
            'carrera_id.exists' => 'La carrera seleccionada no existe',
            'email.unique' => 'Este correo ya está registrado',
            'role.required' => 'Debes seleccionar un rol',
            'role.in' => 'El rol debe ser aspirante, estudiante o egresado',
            'password.confirmed' => 'Las contraseñas no coinciden',
        ];
    }
}
