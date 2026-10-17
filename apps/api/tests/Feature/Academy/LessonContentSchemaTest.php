<?php

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\Blocks\VideoBlock;
use App\Modules\Academy\Content\LessonContentSchema;
use App\Modules\Academy\Content\ValidationErrors;

/*
| Schemas dos blocos, versão 1 (ADR 0016, ADR 0019; docs/academy/conteudo-importavel.md).
*/

function blockContext(?string $quizKey = null): BlockContext
{
    return new class($quizKey) implements BlockContext
    {
        public function __construct(private ?string $quizKey) {}

        public function mediaSha(string $src, string $location, ValidationErrors $errors): ?string
        {
            if ($src !== 'existe.png') {
                $errors->add($location, 'imagem não encontrada');

                return null;
            }

            return str_repeat('a', 64);
        }

        public function lessonQuizKey(): ?string
        {
            return $this->quizKey;
        }
    };
}

/**
 * @param  list<array<string, mixed>>  $blocks
 * @return array{0: array<string, mixed>|null, 1: list<string>}
 */
function normalizeBlocks(array $blocks, ?string $quizKey = null, int $version = 1): array
{
    $errors = new ValidationErrors;
    $content = LessonContentSchema::normalize(['version' => $version, 'blocks' => $blocks], 'aula.yaml: content', blockContext($quizKey), $errors);

    return [$content, $errors->all()];
}

/*
| Casos compartilhados com o espelho Zod do frontend (etapa 2.3): content/academy/_fixtures.
*/
function sharedBlockFixtures(): array
{
    return json_decode((string) file_get_contents(config('academy.content_path').'/_fixtures/blocks-v1.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('passes the shared valid block fixtures', function () {
    foreach (sharedBlockFixtures()['valid'] as $case) {
        $quizKey = $case['block']['type'] === 'quiz_ref' ? sharedBlockFixtures()['context']['quiz'] : null;
        [$content, $errors] = normalizeBlocks([$case['block']], $quizKey);

        expect($errors)->toBe([], $case['name'])
            ->and($content['blocks'][0])->toBe(['type' => $case['block']['type'], 'data' => $case['data']], $case['name']);
    }
});

it('fails the shared invalid block fixtures with the expected message', function () {
    foreach (sharedBlockFixtures()['invalid'] as $case) {
        $quizKey = $case['block']['type'] === 'quiz_ref' ? sharedBlockFixtures()['context']['quiz'] : null;
        [$content, $errors] = normalizeBlocks([$case['block']], $quizKey);

        expect($content)->toBeNull($case['name'])
            ->and(implode("\n", $errors))->toContain($case['error']);
    }
});

it('normalizes every block type to the canonical data stored in the database', function () {
    [$content, $errors] = normalizeBlocks([
        ['type' => 'markdown', 'text' => "## Título\n\nTexto com **negrito**, [link](https://example.com) e [interno](/academy)."],
        ['type' => 'callout', 'variant' => 'warning', 'text' => 'Cuidado com o `spray`.'],
        ['type' => 'video', 'url' => 'https://www.youtube.com/watch?v=AbCdEfGhIjK&t=10', 'title' => 'Vídeo', 'start_seconds' => 12],
        ['type' => 'image', 'src' => 'existe.png', 'alt' => 'Gráfico', 'caption' => 'Legenda'],
        ['type' => 'image', 'src' => 'existe.png', 'decorative' => true],
        ['type' => 'quiz_ref', 'quiz' => 'meu-quiz'],
    ], quizKey: 'meu-quiz');

    expect($errors)->toBe([])
        ->and($content['version'])->toBe(1)
        ->and(array_column($content['blocks'], 'type'))->toBe(['markdown', 'callout', 'video', 'image', 'image', 'quiz_ref'])
        ->and($content['blocks'][1]['data'])->toBe(['variant' => 'warning', 'title' => null, 'text' => 'Cuidado com o `spray`.'])
        ->and($content['blocks'][2]['data'])->toBe(['provider' => 'youtube', 'video_id' => 'AbCdEfGhIjK', 'title' => 'Vídeo', 'start_seconds' => 12])
        ->and($content['blocks'][3]['data'])->toBe(['media' => str_repeat('a', 64), 'alt' => 'Gráfico', 'decorative' => false, 'caption' => 'Legenda'])
        ->and($content['blocks'][4]['data'])->toBe(['media' => str_repeat('a', 64), 'alt' => '', 'decorative' => true, 'caption' => null])
        ->and($content['blocks'][5]['data'])->toBe(['quiz' => 'meu-quiz']);
});

it('rejects HTML and unsafe Markdown in markdown blocks', function (string $text, string $message) {
    [$content, $errors] = normalizeBlocks([['type' => 'markdown', 'text' => $text]]);

    expect($content)->toBeNull()
        ->and(implode("\n", $errors))->toContain('aula.yaml: content.blocks[0].text')->toContain($message);
})->with([
    'script' => ['Olá <script>alert(1)</script>', 'HTML'],
    'fechamento' => ['texto </div>', 'HTML'],
    'comentário' => ['<!-- escondido -->', 'HTML'],
    'imagem markdown' => ['![x](https://example.com/x.png)', 'imagens'],
    'link javascript' => ['[clique](javascript:alert(1))', 'link'],
    'link data' => ['[x](data:text/html,oi)', 'link'],
    'link http' => ['[x](http://example.com)', 'link'],
    'link protocolo relativo' => ['[x](//evil.example)', 'link'],
    'link barra invertida' => ['[x](/\\evil.example)', 'link'],
    'definição de referência' => ["[x][r]\n\n[r]: javascript:alert(1)", 'link'],
    'h1 atx' => ['# Título principal', 'título'],
    'h1 setext' => ["Título\n===", 'título'],
]);

it('accepts Markdown that only looks like HTML or headings', function (string $text) {
    [$content, $errors] = normalizeBlocks([['type' => 'markdown', 'text' => $text]]);

    expect($errors)->toBe([])->and($content)->not->toBeNull();
})->with([
    'comparação' => ['Se a < b, use a.'],
    'menor que seguido de espaço (não é tag para o CommonMark nem para o navegador)' => ['< img src=x>'],
    'mailto' => ['[fale conosco](mailto:contato@example.com)'],
    'âncora' => ['[topo](#topo)'],
    'h1 dentro de código' => ["```\n# comentário de shell\n```"],
]);

it('limits callouts to inline Markdown', function (string $text) {
    [$content, $errors] = normalizeBlocks([['type' => 'callout', 'variant' => 'info', 'text' => $text]]);

    expect($content)->toBeNull()->and($errors)->not->toBe([]);
})->with([
    'lista' => ["- item\n- item"],
    'título' => ['## Título'],
    'citação' => ['> citação'],
    'HTML' => ['<b>negrito</b>'],
]);

it('accepts only YouTube URLs and stores the video ID', function (string $url, ?string $id) {
    expect(VideoBlock::youtubeId($url))->toBe($id);
})->with([
    'watch' => ['https://www.youtube.com/watch?v=AbCdEfGhIjK', 'AbCdEfGhIjK'],
    'sem www' => ['https://youtube.com/watch?v=AbCdEfGhIjK', 'AbCdEfGhIjK'],
    'mobile' => ['https://m.youtube.com/watch?v=AbCdEfGhIjK', 'AbCdEfGhIjK'],
    'curto' => ['https://youtu.be/AbCdEfGhIjK', 'AbCdEfGhIjK'],
    'embed' => ['https://www.youtube.com/embed/AbCdEfGhIjK', 'AbCdEfGhIjK'],
    'http' => ['http://www.youtube.com/watch?v=AbCdEfGhIjK', null],
    'outro domínio' => ['https://vimeo.com/123456', null],
    'domínio parecido' => ['https://www.youtube.com.evil.example/watch?v=AbCdEfGhIjK', null],
    'com usuário' => ['https://evil@www.youtube.com/watch?v=AbCdEfGhIjK', null],
    'com porta' => ['https://www.youtube.com:8443/watch?v=AbCdEfGhIjK', null],
    'ID curto' => ['https://youtu.be/AbCdEf', null],
    'ID com caractere inválido' => ['https://youtu.be/AbCdEfGhIj$', null],
    'nocookie (não aceito no arquivo)' => ['https://www.youtube-nocookie.com/embed/AbCdEfGhIjK', null],
]);

it('requires alt text unless the image is decorative', function (array $fields, string $message) {
    [$content, $errors] = normalizeBlocks([['type' => 'image', ...$fields]]);

    expect($content)->toBeNull()->and(implode("\n", $errors))->toContain($message);
})->with([
    'sem alt' => [['src' => 'existe.png'], 'content.blocks[0].alt: obrigatório'],
    'alt vazio' => [['src' => 'existe.png', 'alt' => '  '], 'content.blocks[0].alt'],
    'decorativa com alt' => [['src' => 'existe.png', 'decorative' => true, 'alt' => 'x'], 'alt vazio'],
    'imagem inexistente' => [['src' => 'nao-existe.png', 'alt' => 'x'], 'content.blocks[0].src: imagem não encontrada'],
]);

it('ties quiz_ref to the quiz declared by the lesson', function () {
    expect(normalizeBlocks([['type' => 'quiz_ref', 'quiz' => 'outro']], quizKey: 'meu-quiz')[1])
        ->toContain('aula.yaml: content.blocks[0].quiz: precisa ser a key do quiz declarado nesta aula')
        ->and(normalizeBlocks([['type' => 'quiz_ref', 'quiz' => 'meu-quiz']])[1])
        ->toContain('aula.yaml: content.blocks[0].quiz: precisa ser a key do quiz declarado nesta aula')
        ->and(normalizeBlocks([['type' => 'markdown', 'text' => 'oi']], quizKey: 'meu-quiz')[1][0])
        ->toContain('inclua um bloco quiz_ref')
        ->and(normalizeBlocks([['type' => 'quiz_ref', 'quiz' => 'q'], ['type' => 'quiz_ref', 'quiz' => 'q']], quizKey: 'q')[1])
        ->toContain('aula.yaml: content.blocks: no máximo um bloco quiz_ref por aula');
});

it('rejects unknown types, unknown fields, other versions and oversized content', function () {
    expect(normalizeBlocks([['type' => 'html', 'html' => '<b>x</b>']])[1][0])->toContain('content.blocks[0].type: tipo de bloco desconhecido')
        ->and(normalizeBlocks([['type' => 'markdown', 'text' => 'x', 'style' => 'red']])[1])->toContain('aula.yaml: content.blocks[0].style: campo desconhecido')
        ->and(normalizeBlocks([['type' => 'markdown', 'text' => 'x']], version: 2)[1][0])->toContain('content.version: versão de conteúdo não suportada')
        ->and(normalizeBlocks([])[1][0])->toContain('content.blocks: precisa ter de 1 a 100 itens')
        ->and(normalizeBlocks(array_fill(0, 101, ['type' => 'markdown', 'text' => 'x']))[1][0])->toContain('de 1 a 100 itens')
        ->and(normalizeBlocks(array_fill(0, 20, ['type' => 'markdown', 'text' => str_repeat('palavra ', 2000)]))[1])->toContain('aula.yaml: content: conteúdo maior que 256 KB');

    $errors = new ValidationErrors;
    expect(LessonContentSchema::normalize(['version' => 1, 'blocks' => [], 'extra' => 1], 'aula.yaml: content', blockContext(), $errors))->toBeNull()
        ->and($errors->all())->toContain('aula.yaml: content.extra: campo desconhecido');
});
