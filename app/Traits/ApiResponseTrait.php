<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    /**
     * Başarılı yanıt (200)
     */
    protected function successResponse(mixed $data = null, string $message = 'İşlem başarılı', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Yeni kayıt oluşturuldu (201)
     */
    protected function createdResponse(mixed $data = null, string $message = 'Kayıt başarıyla oluşturuldu'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * İçerik yok (204)
     */
    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Genel hata yanıtı
     */
    protected function errorResponse(string $message = 'Bir hata oluştu', int $code = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    /**
     * Doğrulama hatası (422)
     */
    protected function validationErrorResponse(mixed $errors, string $message = 'Doğrulama hatası'): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Yetkisiz erişim (401)
     */
    protected function unauthorizedResponse(string $message = 'Yetkisiz erişim'): JsonResponse
    {
        return $this->errorResponse($message, 401);
    }

    /**
     * Erişim engellendi (403)
     */
    protected function forbiddenResponse(string $message = 'Bu işleme yetkiniz yok'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Kayıt bulunamadı (404)
     */
    protected function notFoundResponse(string $message = 'Kayıt bulunamadı'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Sunucu hatası (500)
     */
    protected function serverErrorResponse(string $message = 'Sunucu hatası', mixed $errors = null): JsonResponse
    {
        return $this->errorResponse($message, 500, $errors);
    }
}
