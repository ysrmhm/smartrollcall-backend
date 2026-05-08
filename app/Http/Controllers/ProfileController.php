<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/me
     */
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(AuthService::transform($request->user()));
    }

    /**
     * PUT /api/profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name'  => ['required', 'string', 'max:100'],
            'last_name'   => ['nullable', 'string', 'max:100'],
            'email'       => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone'       => ['nullable', 'string', 'max:20'],
            'institution' => ['nullable', 'string', 'max:255'],
            'avatar'      => ['nullable', 'string'],
        ]);

        if (isset($data['avatar']) && strlen($data['avatar']) > 2_000_000) {
            return $this->validationErrorResponse(
                ['avatar' => ['Profil fotoğrafı çok büyük. Lütfen 1.5MB altında bir resim seçin.']]
            );
        }

        $data['name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        $user->fill($data)->save();

        return $this->successResponse(
            AuthService::transform($user->fresh()),
            'Profil başarıyla güncellendi!'
        );
    }
}
