<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Steam\SteamOpenId;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/me/steam/link/complete — `{ state, openid: { ns, mode, … } }`.
 *
 * Só os campos `openid.*` conhecidos (SteamOpenId::RESPONSE_FIELDS), todos texto; qualquer outro
 * reprova. O `state` pode faltar: a ação responde `steam_state_invalid`, como para um `state`
 * errado.
 */
class CompleteSteamLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->can('manageSteamLink', $user) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'state' => ['nullable', 'string', 'max:128'],
            'openid' => ['required', 'array:'.implode(',', SteamOpenId::RESPONSE_FIELDS)],
            'openid.ns' => ['required', 'string', 'max:255'],
            'openid.mode' => ['required', 'string', 'max:32'],
        ];
        foreach (array_diff(SteamOpenId::RESPONSE_FIELDS, ['ns', 'mode']) as $field) {
            $rules["openid.{$field}"] = ['sometimes', 'string', 'max:2048'];
        }

        return $rules;
    }

    /** @return array<string, string> campos `openid.*` sem o prefixo */
    public function openIdResponse(): array
    {
        /** @var array<string, string> $response */
        $response = $this->validated('openid');

        return $response;
    }
}
