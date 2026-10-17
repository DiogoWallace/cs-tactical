<?php

namespace App\Modules\Academy\Import;

/** Resultado de uma importação (ou simulação), no formato de docs/academy/conteudo-importavel.md. */
final class ImportReport
{
    /** @var array<string, array{created: int, updated: int, unchanged: int}> */
    public array $counts = [
        'courses' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
        'modules' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
        'lessons' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
        'quizzes' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
    ];

    public int $quizRevisions = 0;

    /** Quizzes, perguntas e opções aposentados (tinham tentativa) ou removidos (não tinham). */
    public int $quizItemsRetired = 0;

    public int $quizItemsDeleted = 0;

    public int $mediaCreated = 0;

    /** Arquivos gravados no disco `academy` nesta execução (fase b). */
    public int $filesWritten = 0;

    /** @var list<string> itens no banco que não estão nos arquivos */
    public array $absent = [];

    /** @var list<string> */
    public array $archived = [];

    public function __construct(public readonly bool $dryRun) {}

    /** @param  'courses'|'modules'|'lessons'|'quizzes'  $entity */
    public function count(string $entity, bool $created, bool $changed): void
    {
        $this->counts[$entity][$created ? 'created' : ($changed ? 'updated' : 'unchanged')]++;
    }

    public function changedAnything(): bool
    {
        foreach ($this->counts as $count) {
            if ($count['created'] + $count['updated'] > 0) {
                return true;
            }
        }

        return $this->mediaCreated + $this->quizItemsRetired + $this->quizItemsDeleted + count($this->archived) > 0;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $line = fn (string $label, string $entity, string $created, string $updated) => sprintf('  %s %s %d · %s %d · sem mudança %d',
            mb_str_pad($label, 8), $created, $this->counts[$entity]['created'], $updated, $this->counts[$entity]['updated'], $this->counts[$entity]['unchanged']);

        $absentLabel = $this->dryRun ? 'ausentes (não serão alterados)' : 'ausentes (não alterados)';

        return [
            $line('cursos', 'courses', 'criados', 'alterados'),
            $line('módulos', 'modules', 'criados', 'alterados'),
            $line('aulas', 'lessons', 'criadas', 'alteradas'),
            $line('quizzes', 'quizzes', 'criados', 'alterados'),
            "  quizzes  revisão +{$this->quizRevisions} · itens aposentados {$this->quizItemsRetired} · itens removidos {$this->quizItemsDeleted}",
            "  mídias   registradas {$this->mediaCreated} · arquivos gravados {$this->filesWritten}",
            '  '.$absentLabel.': '.($this->absent === [] ? 'nenhum' : implode(', ', $this->absent)),
            '  arquivados: '.($this->archived === [] ? 'nenhum' : implode(', ', $this->archived)),
        ];
    }
}
