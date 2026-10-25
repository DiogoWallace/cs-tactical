<?php

/*
| Envio de demos (etapa M3, docs/training/mvp-m3-envio-de-demos.md; decisões U1–U6 e D-M3).
*/

return [

    // Tamanho máximo de um arquivo .dem (600 MiB).
    'max_size_bytes' => 600 * 1024 * 1024,

    // Tamanho fixo de cada parte (U1: 8 MiB; a borda aceita 9 MB só na rota das partes).
    'part_size_bytes' => 8 * 1024 * 1024,

    // Envios concluídos permitidos por usuário em 24 horas (U3).
    'daily_completed_limit' => 5,

    // Envio aberto sem nenhuma parte nova por este tempo expira (U3).
    'idle_minutes' => 120,

    // Envio concluído sem análise é apagado depois deste tempo (U6, D-M4).
    'completed_ttl_hours' => 24,

    // Espaço livre que precisa sobrar no disco além do arquivo (U3).
    'disk_reserve_bytes' => 2 * 1024 * 1024 * 1024,

    // `matches:prune-uploads` só remove arquivos sem envio aberto com mais deste tempo.
    'orphan_retention_hours' => 24,

];
