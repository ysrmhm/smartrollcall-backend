<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * POST /api/login
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $result = $this->authService->authenticate(
            $credentials['username'],
            $credentials['password']
        );

        if ($result === null) {
            return $this->unauthorizedResponse('Kullanıcı adı veya şifre hatalı!');
        }

        return $this->successResponse($result, 'Giriş başarılı!');
    }

    /**
     * POST /api/forgot-password
     * Username ile sıfırlama kodu üretir + e-posta gönderir.
     * Güvenlik için kullanıcı varlığını sızdırmaz.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:100',
        ]);

        $sent = $this->authService->sendPasswordResetCode($data['username']);

        if (! $sent) {
            return $this->errorResponse(
                'Bu hesapta kayıtlı bir e-posta adresi yok. Lütfen sistem yöneticisine başvurun.',
                422
            );
        }

        return $this->successResponse(
            null,
            'Eğer bu kullanıcı adı sistemde kayıtlı ise, hesaba bağlı e-posta adresine 6 haneli sıfırlama kodu gönderildi.'
        );
    }

    /**
     * POST /api/reset-password
     * Kod ile şifreyi günceller.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'code'     => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $result = $this->authService->resetPasswordWithCode(
            $data['username'],
            $data['code'],
            $data['password']
        );

        return match ($result) {
            'ok'        => $this->successResponse(null, 'Şifreniz başarıyla güncellendi! Giriş yapabilirsiniz.'),
            'expired'   => $this->errorResponse('Kodun süresi dolmuş. Lütfen yeni bir kod talep edin.', 422),
            'invalid'   => $this->errorResponse('Kod yanlış. Lütfen e-postanızı kontrol edin.', 422),
            'not_found' => $this->errorResponse('Bu kullanıcı için aktif bir sıfırlama kodu yok. Lütfen yeni kod talep edin.', 422),
        };
    }

    /**
     * POST /api/logout (auth:sanctum)
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Çıkış başarılı!');
    }
}
