<?php

namespace Tests\Support;

final class StudySchema
{
    public static function migrate(): void
    {
        foreach ([
            '2014_10_12_000000_create_users_table.php', '2025_05_14_145416_add_username_to_users_table.php',
            '2025_06_05_044134_add_imagen_field_to_users_table.php', '2025_10_28_005331_create_universidades_table.php',
            '2025_10_28_005410_create_carreras_table.php', '2025_11_08_121438_add_universidad_and_carrera_to_users_table.php',
            '2025_09_29_234018_create_personal_access_tokens_table.php', '2026_09_08_000001_create_study_module_tables.php',
            '2026_09_08_000002_optimize_study_scale_indexes.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }
}
