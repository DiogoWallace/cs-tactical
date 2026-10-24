<?php

/*
| Cenário ampliado da etapa 2.7d (decisão R3): 50 cursos × 5 módulos × 8 aulas = 2.000 aulas, com um
| quiz na primeira aula de cada módulo (250 quizzes). Ver docs/development/desempenho.md.
|
| Roda DENTRO do container `api` da stack E2E, lendo o script pela entrada padrão:
|
|   docker compose -p cs-tactical-e2e ... exec -T -e PERF_DIR=/tmp/perf-academy api php < scripts/perf/generate-academy.php
|
| Só escreve YAML em PERF_DIR (dentro do container, descartado com a stack); quem grava no banco é o
| `academy:import`, chamado depois pelo scripts/perf.sh. Conteúdo determinístico: a mesma execução
| gera sempre os mesmos arquivos.
|
| Mesma forma do curso de fixture fundamentos-e2e, para as contagens de consultas serem comparáveis
| entre os cenários: capa no curso, imagem e callout em cada aula, quiz com uma pergunta de escolha
| única e uma de múltipla escolha. As imagens são as PNG da fixture (montada só leitura na stack E2E).
*/

use Symfony\Component\Yaml\Yaml;

require 'vendor/autoload.php';

const COURSES = 50;
const MODULES = 5;
const LESSONS = 8;
const LEVELS = ['beginner', 'intermediate', 'advanced'];
const FIXTURE_MEDIA = '/var/www/e2e-content/academy/fundamentos-e2e/media';

$dir = getenv('PERF_DIR') ?: '';
if ($dir === '' || ! str_starts_with($dir, '/tmp/')) {
    fwrite(STDERR, "PERF_DIR precisa estar em /tmp/ (dentro do container descartável).\n");
    exit(2);
}
if (getenv('APP_ENV') !== 'e2e') {
    fwrite(STDERR, "Recusado: APP_ENV precisa ser e2e.\n");
    exit(2);
}

if (is_dir($dir)) {
    exec('rm -rf '.escapeshellarg($dir));
}

$write = function (string $path, array $data): void {
    is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
    file_put_contents($path, Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
};

// Texto com o tamanho de uma aula real (~2 KB de Markdown em 4 seções).
$markdown = function (int $c, int $m, int $l): string {
    $sections = [];
    foreach (['Objetivo', 'Passo a passo', 'Erros comuns', 'Treino'] as $index => $title) {
        $sections[] = "## {$title}\n\n".str_repeat(
            "Curso {$c}, módulo {$m}, aula {$l}: posicione a mira na altura da cabeça, pare antes de atirar e "
            ."confira o ângulo seguinte. Repita até o movimento sair sem pensar. ",
            3 + $index,
        )."\n\n- Primeiro ponto da seção {$title}.\n- Segundo ponto, com **destaque**.\n";
    }

    return implode("\n", $sections);
};

for ($c = 1; $c <= COURSES; $c++) {
    $course = sprintf('perf-curso-%02d', $c);
    $write("{$dir}/{$course}/course.yaml", [
        'key' => $course,
        'slug' => $course,
        'title' => sprintf('Curso de desempenho %02d', $c),
        'summary' => 'Curso gerado para a medição de desempenho (etapa 2.7d), nunca publicado de verdade.',
        'level' => LEVELS[($c - 1) % count(LEVELS)],
        'status' => 'published',
        'position' => 100 + $c,
        'cover_image' => 'capa.png',
    ]);
    mkdir("{$dir}/{$course}/media", 0755, true);
    foreach (['capa.png', 'grafico.png'] as $image) {
        copy(FIXTURE_MEDIA."/{$image}", "{$dir}/{$course}/media/{$image}") || exit(1);
    }

    for ($m = 1; $m <= MODULES; $m++) {
        $moduleDir = sprintf('%s/%s/%02d-modulo-%d', $dir, $course, $m, $m);
        $write("{$moduleDir}/module.yaml", [
            'key' => "{$course}-m{$m}",
            'slug' => "modulo-{$m}",
            'title' => "Módulo {$m}",
            'status' => 'published',
            'position' => $m,
        ]);

        for ($l = 1; $l <= LESSONS; $l++) {
            $lesson = [
                'key' => "{$course}-m{$m}-a{$l}",
                'slug' => "m{$m}-aula-{$l}",
                'title' => "Aula {$m}.{$l}",
                'status' => 'published',
                'position' => $l,
                'estimated_minutes' => 5 + $l,
                'content' => [
                    'version' => 1,
                    'blocks' => [
                        ['type' => 'markdown', 'text' => $markdown($c, $m, $l)],
                        ['type' => 'image', 'src' => 'grafico.png', 'alt' => "Gráfico da aula {$m}.{$l}"],
                        ['type' => 'callout', 'variant' => 'tip', 'text' => 'Grave um treino curto e compare.'],
                    ],
                ],
            ];

            if ($l === 1) {
                $quiz = "{$course}-m{$m}-quiz";
                $lesson['content']['blocks'][] = ['type' => 'quiz_ref', 'quiz' => $quiz];
                $lesson['quiz'] = [
                    'key' => $quiz,
                    'title' => "Teste do módulo {$m}",
                    'max_attempts' => 3,
                    'pass_percent' => 70,
                    'questions' => array_map(fn (int $q) => [
                        'key' => "q{$q}",
                        'kind' => $q === 2 ? 'multiple_choice' : 'single_choice',
                        'prompt' => "Pergunta {$q} do módulo {$m}?",
                        'explanation' => "Explicação da pergunta {$q}.",
                        'options' => [
                            ['key' => 'certa', 'text' => 'CERTA', 'correct' => true],
                            ['key' => 'errada', 'text' => 'ERRADA', 'correct' => false],
                            ['key' => 'outra', 'text' => $q === 2 ? 'CERTA TAMBEM' : 'OUTRA', 'correct' => $q === 2],
                        ],
                    ], [1, 2]),
                ];
            }

            $write(sprintf('%s/%02d-aula-%d.yaml', $moduleDir, $l, $l), $lesson);
        }
    }
}

echo 'Cenário ampliado gerado em '.$dir.': '.COURSES.' cursos, '.(COURSES * MODULES).' módulos, '
    .(COURSES * MODULES * LESSONS).' aulas, '.(COURSES * MODULES).' quizzes.'.PHP_EOL;
