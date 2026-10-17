<?php

/*
| Academy (Fase 2). Decisões: docs/decisions/0015 a 0019.
*/

return [

    /*
    | Pasta com os arquivos de conteúdo importáveis (docs/academy/conteudo-importavel.md).
    | Padrão: content/academy na raiz do repositório. No container da API o repositório não é
    | montado inteiro; o compose monta ./content e define ACADEMY_CONTENT_PATH.
    */
    'content_path' => env('ACADEMY_CONTENT_PATH', base_path('../../content/academy')),

    // Disco das imagens (config/filesystems.php). Arquivos endereçados pelo hash (ADR 0019).
    'media_disk' => 'academy',

    // academy:media-prune só remove órfãos mais antigos que isto (ADR 0019, item 14).
    'media_retention_hours' => 24,

    // Limites de validação das imagens (ADR 0019, item 9).
    'media' => [
        'max_bytes' => 2 * 1024 * 1024,
        'max_dimension' => 4096,
        'mimes' => [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ],
    ],

];
