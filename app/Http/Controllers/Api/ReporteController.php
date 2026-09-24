<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reporte;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReporteController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'reported_user_id' => ['required', 'integer', 'exists:users,id'],
            'motivo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
        ]);

        $reporter = $request->user();

        if (!$reporter) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ((int) $reporter->id === (int) $data['reported_user_id']) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes reportarte a ti mismo',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reported = User::query()
            ->select(['id', 'username'])
            ->findOrFail($data['reported_user_id']);

        $reporte = Reporte::query()->create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'motivo' => $data['motivo'],
            'descripcion' => $data['descripcion'] ?? null,
            'estado' => 'pendiente',
        ]);

        $this->sendWebhook($reporte, $reporter, $reported);

        return response()->json([
            'success' => true,
            'message' => 'Reporte enviado correctamente',
            'data' => $reporte,
        ], Response::HTTP_CREATED);
    }

    private function sendWebhook(Reporte $reporte, User $reporter, User $reported): void
    {
        $webhookUrl = config('services.make.webhook_url');

        if (!$webhookUrl || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return;
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'report_id' => $reporte->id,
                'reporter_id' => $reporter->id,
                'reporter_name' => $reporter->username,
                'reporter_email' => $reporter->email,
                'reported_user_id' => $reported->id,
                'reported_name' => $reported->username,
                'motivo' => $reporte->motivo,
                'descripcion' => $reporte->descripcion,
                'estado' => $reporte->estado,
                'created_at' => optional($reporte->created_at)->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Webhook de reporte falló (reporte guardado)', [
                'report_id' => $reporte->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}