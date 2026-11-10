<?php

namespace App\Console\Commands;

use App\Models\Universidad;
use App\Services\Google\PlacesClient;
use App\Services\Marketplace\CampusGeo;
use App\Services\TypeSafe\JevClient;
use App\Services\TypeSafe\JevException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ubica cada universidad en el mapa: Google Places propone lugares y Jev elige cuál es el campus
 * principal (seleccionar, no inventar). Si Jev no está seguro, no se guarda nada y se avisa para
 * revisarlo a mano en el panel del equipo (/equipo/campus).
 */
class LocateUniversities extends Command
{
    protected $signature = 'lau:ubicar-universidades
        {--id= : solo esta universidad}
        {--todas : volver a ubicar también las que ya tienen ubicación}';

    protected $description = 'Ubica las universidades en el mapa con Google Places y Jev (área de entrega del Marketplace)';

    private const YES = 0.7;

    private const PICK_MIN = 0.5;

    public function handle(PlacesClient $places, JevClient $jev): int
    {
        if (! $places->isConfigured()) {
            $this->error('Falta GOOGLE_SERVER_KEY en el .env (Places API).');

            return self::FAILURE;
        }
        if (! $jev->isConfigured()) {
            $this->error('Falta TYPESAFE_API_KEY en el .env: Jev elige el campus correcto entre los resultados.');

            return self::FAILURE;
        }

        $query = Universidad::query()->orderBy('id');
        if ($this->option('id')) {
            $query->whereKey($this->option('id'));
        } elseif (! $this->option('todas')) {
            $query->whereNull('latitud');
        }

        $pending = 0;
        foreach ($query->get() as $campus) {
            try {
                $candidates = array_values(array_filter(
                    $places->search($campus->nombre.' El Salvador'),
                    fn ($p) => $p['lat'] >= CampusGeo::LAT_MIN && $p['lat'] <= CampusGeo::LAT_MAX
                        && $p['lng'] >= CampusGeo::LNG_MIN && $p['lng'] <= CampusGeo::LNG_MAX,
                ));
            } catch (RuntimeException $e) {
                $this->error("{$campus->nombre}: {$e->getMessage()}");
                $pending++;

                continue;
            }

            if ($candidates === []) {
                $this->warn("{$campus->nombre}: Google Places no encontró lugares en El Salvador. Ubicala en /equipo/campus.");
                $pending++;

                continue;
            }

            // Nombres de opción descriptivos (el modelo los lee): el nombre del lugar, no "place_1".
            $keyed = [];
            foreach ($candidates as $i => $c) {
                $keyed[(Str::limit(Str::slug($c['name'], '_'), 40, '') ?: 'lugar').'_'.($i + 1)] = $c;
            }

            try {
                $result = $jev->ask([
                    'university' => array_filter(['name' => $campus->nombre, 'acronym' => $campus->siglas, 'website' => $campus->dominio]),
                    'places' => array_map(fn ($c) => ['name' => $c['name'], 'address' => $c['address'], 'types' => $c['types']], $keyed),
                ], [
                    'campus_listed' => [
                        'type' => 'noul',
                        'instructions' => 'Is one of the places in `places` a campus of the university in `university`?',
                        'criteria' => [
                            'true' => 'A place is the university itself or one of its campuses, for example "Universidad de El Salvador" at Ciudad Universitaria.',
                            'false' => 'The places are other things, for example a bookstore, a school or a different university.',
                        ],
                    ],
                    'main_campus' => [
                        'type' => 'choice',
                        'instructions' => 'Which place in `places` is the main campus of the university in `university`?',
                        'criteria' => array_map(fn ($c) => $c['name'].' — '.$c['address'], $keyed),
                    ],
                ]);
            } catch (JevException $e) {
                $this->error("{$campus->nombre}: Jev no respondió ({$e->getMessage()}).");
                $pending++;

                continue;
            }

            $listed = $result->noul('campus_listed');
            $pick = $result->choice('main_campus');
            $probability = $pick['probabilities'][$pick['choice']] ?? 0;

            if ($listed < self::YES || $probability < self::PICK_MIN) {
                $this->warn(sprintf('%s: no hay un campus claro (campus %.2f, elección %.2f). Ubicala en /equipo/campus.', $campus->nombre, $listed, $probability));
                $pending++;

                continue;
            }

            $place = $keyed[$pick['choice']];
            $campus->forceFill([
                'latitud' => $place['lat'],
                'longitud' => $place['lng'],
                'direccion' => mb_substr($place['address'], 0, 255),
                'google_place_id' => $place['id'],
                'ubicado_en' => now(),
            ])->save();

            $this->info(sprintf('OK %s → %s (%.5f, %.5f) · campus %.2f, elección %.2f', $campus->siglas ?: $campus->nombre, $place['name'], $place['lat'], $place['lng'], $listed, $probability));
        }

        $this->line($pending ? "Quedan {$pending} por revisar en /equipo/campus." : 'Listo.');
        $this->line('El radio de entrega por defecto es 600 m; ajustalo por campus en /equipo/campus.');

        return self::SUCCESS;
    }
}
