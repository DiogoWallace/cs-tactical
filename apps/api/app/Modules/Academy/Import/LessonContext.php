<?php

namespace App\Modules\Academy\Import;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\ValidationErrors;

final readonly class LessonContext implements BlockContext
{
    public function __construct(
        private CourseMedia $media,
        private ?string $quizKey,
    ) {}

    public function mediaSha(string $src, string $location, ValidationErrors $errors): ?string
    {
        return $this->media->sha($src, $location, $errors);
    }

    public function lessonQuizKey(): ?string
    {
        return $this->quizKey;
    }
}
