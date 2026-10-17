<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Pastas de conteúdo descartáveis para os testes da importação da Academy.
 *
 * A árvore é um array: chave `*.yaml` com array vira YAML; outra chave com string vira arquivo
 * com esse conteúdo; chave com array (sem `.yaml`) vira pasta. `write()` cria a pasta em
 * sys_get_temp_dir() e `cleanup()` apaga todas as criadas.
 */
final class AcademyContent
{
    /** @var list<string> */
    private static array $directories = [];

    /** PNG RGB válido, gerado sem GD. `$seed` muda os pixels (e o sha256). */
    public static function png(int $width = 4, int $height = 4, int $seed = 0): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $raw .= chr(($x * 40 + $seed) % 256).chr(($y * 40 + $seed * 7) % 256).chr($seed % 256);
            }
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw, 9))
            .$chunk('IEND', '');
    }

    /**
     * Um curso publicado com 2 módulos, 3 aulas (uma com quiz) e 2 imagens.
     *
     * @return array<string, mixed>
     */
    public static function tree(): array
    {
        return [
            'fundamentos' => [
                'course.yaml' => [
                    'key' => 'fundamentos', 'slug' => 'fundamentos', 'title' => 'Fundamentos',
                    'summary' => 'Curso de teste.', 'level' => 'beginner', 'status' => 'published',
                    'position' => 1, 'cover_image' => 'capa.png',
                ],
                'media' => [
                    'capa.png' => self::png(8, 4, 1),
                    'grafico.png' => self::png(6, 6, 2),
                ],
                '01-movimentacao' => [
                    'module.yaml' => ['key' => 'movimentacao', 'slug' => 'movimentacao', 'title' => 'Movimentação', 'status' => 'published', 'position' => 1],
                    '01-counter-strafe.yaml' => [
                        'key' => 'counter-strafe', 'slug' => 'counter-strafe', 'title' => 'Counter-strafe',
                        'status' => 'published', 'position' => 1, 'estimated_minutes' => 8,
                        'content' => ['version' => 1, 'blocks' => [
                            ['type' => 'markdown', 'text' => "## Parar antes de atirar\n\nUse a tecla **oposta**."],
                            ['type' => 'image', 'src' => 'grafico.png', 'alt' => 'Velocidade caindo a zero'],
                            ['type' => 'video', 'url' => 'https://youtu.be/AbCdEfGhIjK', 'title' => 'Demonstração'],
                            ['type' => 'quiz_ref', 'quiz' => 'counter-strafe-quiz'],
                        ]],
                        'quiz' => [
                            'key' => 'counter-strafe-quiz', 'title' => 'Teste rápido',
                            'questions' => [
                                ['key' => 'tecla', 'kind' => 'single_choice', 'prompt' => 'Qual tecla para mais rápido indo com A?', 'options' => [
                                    ['key' => 'a', 'text' => 'A', 'correct' => false],
                                    ['key' => 'd', 'text' => 'D', 'correct' => true],
                                ]],
                                ['key' => 'quando', 'kind' => 'multiple_choice', 'prompt' => 'Quando usar?', 'options' => [
                                    ['key' => 'peek', 'text' => 'No peek', 'correct' => true],
                                    ['key' => 'duelo', 'text' => 'No duelo', 'correct' => true],
                                    ['key' => 'rotacao', 'text' => 'Na rotação', 'correct' => false],
                                ]],
                            ],
                        ],
                    ],
                    '02-peek.yaml' => [
                        'key' => 'peek', 'slug' => 'peek', 'title' => 'Peek seguro', 'status' => 'published', 'position' => 2,
                        'content' => ['version' => 1, 'blocks' => [
                            ['type' => 'callout', 'variant' => 'tip', 'text' => 'Mostre só o *necessário*.'],
                        ]],
                    ],
                ],
                '02-mira' => [
                    'module.yaml' => ['key' => 'mira', 'slug' => 'mira', 'title' => 'Mira', 'status' => 'draft', 'position' => 2],
                    '01-posicionamento.yaml' => [
                        'key' => 'posicionamento', 'slug' => 'posicionamento', 'title' => 'Posicionamento', 'status' => 'draft', 'position' => 1,
                        'content' => ['version' => 1, 'blocks' => [['type' => 'markdown', 'text' => 'Mira na altura da cabeça.']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Árvore padrão com alterações por caminho com pontos (`null` remove o item).
     *
     * @param  array<string, mixed>  $changes  ex.: ['fundamentos.course\.yaml.slug' => 'novo']
     * @return array<string, mixed>
     */
    public static function treeWith(array $changes): array
    {
        $tree = self::tree();
        foreach ($changes as $path => $value) {
            $segments = array_map(fn ($segment) => str_replace('\.', '.', $segment), preg_split('/(?<!\\\\)\./', $path) ?: []);
            self::set($tree, $segments, $value);
        }

        return $tree;
    }

    /** @param  array<string, mixed>  $tree */
    public static function write(array $tree): string
    {
        $root = sys_get_temp_dir().'/academy-content-'.Str::lower(Str::random(12));
        self::$directories[] = $root;
        self::writeTree($root, $tree);

        return $root;
    }

    /** Apaga as pastas de conteúdo e o disco falso `academy` (Storage::fake): nada sobra da suíte. */
    public static function cleanup(): void
    {
        foreach (self::$directories as $directory) {
            File::deleteDirectory($directory);
        }
        self::$directories = [];
        File::deleteDirectory(storage_path('framework/testing/disks/academy'));
    }

    /** @param  array<string, mixed>  $tree */
    private static function writeTree(string $directory, array $tree): void
    {
        File::ensureDirectoryExists($directory);
        foreach ($tree as $name => $value) {
            $path = "{$directory}/{$name}";
            if (is_array($value) && str_ends_with($name, '.yaml')) {
                File::put($path, Yaml::dump($value, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            } elseif (is_array($value)) {
                self::writeTree($path, $value);
            } else {
                File::put($path, (string) $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $segments
     */
    private static function set(array &$tree, array $segments, mixed $value): void
    {
        $key = array_shift($segments);
        if ($segments === []) {
            if ($value === null) {
                unset($tree[$key]);
            } else {
                $tree[$key] = $value;
            }

            return;
        }
        if (! isset($tree[$key]) || ! is_array($tree[$key])) {
            $tree[$key] = [];
        }
        self::set($tree[$key], $segments, $value);
    }
}
