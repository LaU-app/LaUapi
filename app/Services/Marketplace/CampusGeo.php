<?php

namespace App\Services\Marketplace;

use App\Models\Universidad;

/** Cálculos de ubicación de los campus. Todo se entrega dentro del área de un campus. */
final class CampusGeo
{
    private const EARTH_RADIUS_M = 6371000;

    /** Margen por imprecisión del GPS al validar un punto de entrega. */
    public const GPS_MARGIN_M = 60;

    /** Límites aproximados de El Salvador (para validar coordenadas de campus). */
    public const LAT_MIN = 13.0;

    public const LAT_MAX = 14.5;

    public const LNG_MIN = -90.2;

    public const LNG_MAX = -87.6;

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($h)));
    }

    /** Área de entrega por defecto: la universidad y un poco alrededor (se ajusta por campus en el panel). */
    public const DEFAULT_RADIUS_M = 300;

    public static function located(?Universidad $campus): bool
    {
        return $campus !== null && $campus->latitud !== null && $campus->longitud !== null;
    }

    /** ¿El punto está dentro del área de entrega del campus (con margen de GPS)? */
    public static function contains(Universidad $campus, float $lat, float $lng): bool
    {
        return self::located($campus)
            && self::distanceMeters((float) $campus->latitud, (float) $campus->longitud, $lat, $lng) <= $campus->radio_entrega_m + self::GPS_MARGIN_M;
    }

    public static function label(?Universidad $campus): string
    {
        return $campus ? ($campus->siglas ?: $campus->nombre) : 'tu campus';
    }
}
