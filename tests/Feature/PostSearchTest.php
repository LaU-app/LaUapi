<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = User::factory()->create(['name' => 'Autor Genérico', 'username' => 'autor']);
    }

    private function makePost(array $attributes = [], ?User $author = null): Post
    {
        // La factory elige imagen o música al azar: se limpian los campos que se buscan
        return Post::factory()->create(array_merge([
            'user_id' => ($author ?? $this->author)->id,
            'tipo' => 'texto',
            'texto' => null,
            'titulo' => null,
            'descripcion' => null,
            'imagen' => null,
            'itunes_track_name' => null,
            'itunes_artist_name' => null,
            'visibility' => 'public',
        ], $attributes));
    }

    private function search(array $params)
    {
        return $this->getJson('/api/posts/search?' . http_build_query($params));
    }

    private function idsOf($response): array
    {
        return collect($response->json('data.data'))->pluck('id')->all();
    }

    private function catalogo(string $tabla, string $nombre): int
    {
        return DB::table($tabla)->insertGetId(['nombre' => $nombre, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_la_ruta_no_queda_capturada_por_posts_post(): void
    {
        $post = $this->makePost(['texto' => 'hola a todos']);

        $this->search(['q' => 'hola'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.id', $post->id)
            ->assertJsonPath('data.total', 1);

        $this->getJson("/api/posts/{$post->id}")->assertOk();
    }

    public function test_busca_en_contenido_titulo_descripcion_cancion_y_autor(): void
    {
        $ana = User::factory()->create(['name' => 'Ana López', 'username' => 'analopez']);

        $texto = $this->makePost(['texto' => 'Guía de CALCULO para el parcial']);
        $titulo = $this->makePost(['tipo' => 'imagen', 'titulo' => 'Apuntes de física']);
        $descripcion = $this->makePost(['tipo' => 'imagen', 'descripcion' => 'Resumen de química orgánica']);
        $cancion = $this->makePost(['tipo' => 'musica', 'itunes_track_name' => 'Bohemian Rhapsody', 'itunes_artist_name' => 'Queen']);
        $deAna = $this->makePost(['texto' => 'Nada que ver'], $ana);
        $this->makePost(['texto' => 'Otro tema']);

        $esperado = [
            'calculo' => [$texto->id],
            'apuntes' => [$titulo->id],
            'orgánica' => [$descripcion->id],
            'queen' => [$cancion->id],
            'rhapsody' => [$cancion->id],
            'lópez' => [$deAna->id],
            'analopez' => [$deAna->id],
        ];

        foreach ($esperado as $q => $ids) {
            $this->assertSame($ids, $this->idsOf($this->search(['q' => $q])->assertOk()), "q={$q}");
        }
    }

    public function test_exige_todas_las_palabras_aunque_esten_en_campos_distintos(): void
    {
        $ana = User::factory()->create(['name' => 'Ana López', 'username' => 'analopez']);
        $parcial = $this->makePost(['texto' => 'Guía de calculo para el parcial']);
        $deAna = $this->makePost(['texto' => 'Vendo calculadora'], $ana);

        $this->assertSame([$parcial->id], $this->idsOf($this->search(['q' => 'parcial calculo'])));
        $this->assertSame([], $this->idsOf($this->search(['q' => 'parcial queen'])));
        $this->assertSame([$deAna->id], $this->idsOf($this->search(['q' => 'ana calculadora'])));
    }

    public function test_filtra_por_universidad_y_carrera_con_o_sin_texto(): void
    {
        $uca = $this->catalogo('universidades', 'UCA');
        $ues = $this->catalogo('universidades', 'UES');
        $informatica = $this->catalogo('carreras', 'Ingeniería Informática');
        $arquitectura = $this->catalogo('carreras', 'Arquitectura');

        $deInformatica = User::factory()->create(['universidad_id' => $uca, 'carrera_id' => $informatica]);
        $deArquitectura = User::factory()->create(['universidad_id' => $uca, 'carrera_id' => $arquitectura]);
        $deLaUes = User::factory()->create(['universidad_id' => $ues]);

        $tutoria = $this->makePost(['texto' => 'Tutoría de cálculo'], $deInformatica);
        $maqueta = $this->makePost(['texto' => 'Maqueta y cálculo estructural'], $deArquitectura);
        $ues1 = $this->makePost(['texto' => 'Cálculo en la UES'], $deLaUes);

        $this->assertSame([$maqueta->id, $tutoria->id], $this->idsOf($this->search(['universidad' => $uca])));
        $this->assertSame([$maqueta->id], $this->idsOf($this->search(['universidad' => $uca, 'carrera' => $arquitectura])));
        $this->assertSame([$tutoria->id], $this->idsOf($this->search(['q' => 'tutoría', 'universidad' => $uca])));
        $this->assertSame([$ues1->id], $this->idsOf($this->search(['q' => 'cálculo', 'universidad' => $ues])));
    }

    public function test_respeta_la_visibilidad_de_los_posts(): void
    {
        $autor = User::factory()->create();
        $seguidor = User::factory()->create();
        $extrano = User::factory()->create();
        DB::table('followers')->insert(['user_id' => $autor->id, 'follower_id' => $seguidor->id]);

        $publico = $this->makePost(['texto' => 'examen público'], $autor);
        $soloSeguidores = $this->makePost(['texto' => 'examen solo seguidores', 'visibility' => 'followers'], $autor);
        $propio = $this->makePost(['texto' => 'examen mío', 'visibility' => 'followers'], $extrano);

        // Sin sesión: solo lo público
        $this->assertSame([$publico->id], $this->idsOf($this->search(['q' => 'examen'])));

        // Quien sigue al autor ve también sus posts "solo seguidores"
        Sanctum::actingAs($seguidor);
        $this->assertSame([$soloSeguidores->id, $publico->id], $this->idsOf($this->search(['q' => 'examen'])));

        // Quien no lo sigue no los ve, pero sí los suyos
        Sanctum::actingAs($extrano);
        $this->assertSame([$propio->id, $publico->id], $this->idsOf($this->search(['q' => 'examen'])));
    }

    public function test_busca_literal_guion_bajo_y_porcentaje(): void
    {
        $guionBajo = $this->makePost(['texto' => 'contacto: ana_1']);
        $this->makePost(['texto' => 'contacto: anax1']);
        $porcentaje = $this->makePost(['texto' => 'descuento del 100% hoy']);
        $this->makePost(['texto' => 'tengo 1000 apuntes']);

        $this->assertSame([$guionBajo->id], $this->idsOf($this->search(['q' => 'ana_1'])));
        $this->assertSame([$porcentaje->id], $this->idsOf($this->search(['q' => '100%'])));
    }

    public function test_pagina_de_20_en_20_empezando_por_lo_mas_nuevo(): void
    {
        $posts = collect(range(1, 25))->map(fn ($n) => $this->makePost([
            'texto' => "repaso {$n}",
            'created_at' => now()->subMinutes(30 - $n),
        ]));

        $primera = $this->search(['q' => 'repaso'])
            ->assertOk()
            ->assertJsonPath('data.total', 25)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(20, 'data.data')
            ->assertJsonPath('data.data.0.id', $posts->last()->id);
        $this->assertStringContainsString('q=repaso', $primera->json('data.next_page_url'));

        $segunda = $this->search(['q' => 'repaso', 'page' => 2])->assertJsonCount(5, 'data.data');
        $this->assertSame([], array_intersect($this->idsOf($primera), $this->idsOf($segunda)));
    }

    public function test_devuelve_urls_y_contadores_como_el_feed(): void
    {
        $this->makePost(['tipo' => 'imagen', 'titulo' => 'Foto del campus', 'imagen' => 'campus.jpg', 'imagen_mini' => 'campus-mini.jpg']);

        $this->search(['q' => 'campus'])
            ->assertJsonPath('data.data.0.imagen_url', url('uploads/campus.jpg'))
            ->assertJsonPath('data.data.0.imagen_mini_url', url('uploads/campus-mini.jpg'))
            ->assertJsonPath('data.data.0.likes_count', 0)
            ->assertJsonPath('data.data.0.comentarios_count', 0)
            ->assertJsonPath('data.data.0.user.username', 'autor');
    }

    public function test_valida_los_parametros(): void
    {
        $this->getJson('/api/posts/search')->assertStatus(422)->assertJsonPath('success', false);
        $this->search(['q' => '   '])->assertStatus(422);
        $this->search(['q' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(['q' => 'hola', 'universidad' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('universidad');
    }

    public function test_ignora_tildes_y_mayusculas_en_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Depende de la collation utf8mb4_unicode_ci de MySQL.');
        }

        $post = $this->makePost(['texto' => 'Guía de Cálculo Diferencial']);

        $this->assertSame([$post->id], $this->idsOf($this->search(['q' => 'calculo diferencial'])));
        $this->assertSame([$post->id], $this->idsOf($this->search(['q' => 'GUIA'])));
    }
}
