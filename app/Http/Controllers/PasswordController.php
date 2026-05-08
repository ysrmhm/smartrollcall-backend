<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    use ApiResponseTrait;

    /**
     * PUT /api/password
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current'              => ['required', 'string'],
            'new'                  => ['required', 'string', 'min:6', 'different:current'],
            'new_confirmation'     => ['required', 'same:new'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current'], $user->password)) {
            throw ValidationException::withMessages([
                'current' => ['Mevcut şifreniz hatalı.'],
            ]);
        }

        $user->password = $data['new'];
        $user->save();

        // Tüm eski token'ları geçersiz kıl, mevcut token'ı koru
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return $this->successResponse(null, 'Şifreniz başarıyla güncellendi!');
    }
}
