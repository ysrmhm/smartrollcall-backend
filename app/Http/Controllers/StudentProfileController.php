<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    use ApiResponseTrait;

    /**
     * PUT /api/student/profile  (auth:student)
     */
    public function update(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'email'      => ['nullable', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:20'],
            'avatar'     => ['nullable', 'string'],
        ]);

        if (isset($data['avatar']) && strlen($data['avatar']) > 2_000_000) {
            return $this->validationErrorResponse(
                ['avatar' => ['Profil fotoğrafı çok büyük. Lütfen 1.5MB altında bir resim seçin.']]
            );
        }

        $student->fill($data)->save();

        return $this->successResponse(
            StudentAuthController::transformStudent($student->fresh()),
            'Profil güncellendi.'
        );
    }
}
