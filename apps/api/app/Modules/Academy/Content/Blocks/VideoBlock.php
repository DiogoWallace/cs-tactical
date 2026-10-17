<?php

namespace App\Modules\Academy\Content\Blocks;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\ValidationErrors;

/**
 * `video`: só YouTube na Fase 2 (ADR 0019, P8). O arquivo traz a URL; o banco guarda provedor +
 * ID validados, nunca a URL (o player usa `youtube-nocookie.com`).
 */
final class VideoBlock implements BlockSchema
{
    private const ID = '[A-Za-z0-9_-]{11}';

    public function type(): string
    {
        return 'video';
    }

    public function normalize(array $fields, string $location, BlockContext $context, ValidationErrors $errors): ?array
    {
        $before = $errors->count();
        Fields::rejectUnknown($fields, ['url', 'title', 'start_seconds'], $location, $errors);
        $url = Fields::string($fields, 'url', $location, $errors, 1, 255);
        $title = Fields::string($fields, 'title', $location, $errors, 1, 160);
        $start = Fields::int($fields, 'start_seconds', $location, $errors, 0, 86400, required: false);

        $id = $url === null ? null : self::youtubeId($url);
        if ($url !== null && $id === null) {
            $errors->add(Fields::at($location, 'url'), 'só vídeos do YouTube (https://www.youtube.com/watch?v=..., https://youtu.be/... ou /embed/...)');
        }

        if ($errors->count() !== $before) {
            return null;
        }

        return ['provider' => 'youtube', 'video_id' => $id, 'title' => $title, 'start_seconds' => $start];
    }

    public static function youtubeId(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be' && preg_match('#^/('.self::ID.')$#', $path, $match) === 1) {
            return $match[1];
        }
        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return null;
        }
        if (preg_match('#^/embed/('.self::ID.')$#', $path, $match) === 1) {
            return $match[1];
        }
        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;

            return is_string($id) && preg_match('/^'.self::ID.'$/', $id) === 1 ? $id : null;
        }

        return null;
    }
}
