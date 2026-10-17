<?php

namespace App\Modules\Academy\Enums;

/** Nível de um curso. Valores também em `courses_level_check`. */
enum CourseLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $level) => $level->value, self::cases());
    }
}
