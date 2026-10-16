<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\UpdateProfile;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;

/** PATCH /api/v1/me/profile — autorização pela ProfilePolicy (no Form Request). */
class UpdateProfileController
{
    public function __invoke(UpdateProfileRequest $request, UpdateProfile $updateProfile): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($updateProfile->handle($user, $request->validated()));
    }
}
