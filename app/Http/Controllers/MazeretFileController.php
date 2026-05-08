<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\MazeretRequest;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MazeretFileController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/student/mazerets/{mazeret}/file
     * Auth: öğrenci, sadece kendi mazeret kaydının dosyasını indirebilir.
     */
    public function downloadAsStudent(Request $request, MazeretRequest $mazeret): StreamedResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $allowedIds = Student::where('student_number', $student->student_number)->pluck('id');
        if (! $allowedIds->contains($mazeret->student_id)) {
            abort(403, 'Bu dosyaya erişim yetkiniz yok.');
        }

        return $this->stream($mazeret);
    }

    /**
     * GET /api/mazerets/{mazeret}/file
     * Auth: hoca, sadece kendi sınıflarındaki mazeret dosyasını indirebilir.
     */
    public function downloadAsTeacher(Request $request, MazeretRequest $mazeret): StreamedResponse
    {
        $owns = Classroom::where('id', $mazeret->classroom_id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $owns) {
            abort(403, 'Bu dosyaya erişim yetkiniz yok.');
        }

        return $this->stream($mazeret);
    }

    private function stream(MazeretRequest $mazeret): StreamedResponse
    {
        if (! Storage::disk('local')->exists($mazeret->file_path)) {
            abort(404, 'Dosya bulunamadı.');
        }

        // Inline (browser'da görünür) — PDF/resim için ideal
        return Storage::disk('local')->response(
            $mazeret->file_path,
            $mazeret->file_original_name,
            ['Content-Type' => $mazeret->file_mime]
        );
    }
}
