<?php

namespace App\Modules\Matches\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/matches/uploads — `{ name, size }` (regra I3). O nome só é exibido; nunca vira
 * caminho no disco.
 */
class StartMatchUploadRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/\.dem$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.(int) config('matches.max_size_bytes')],
        ];
    }
}
