<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\InboxMessage;
use App\Models\MazeretRequest;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentMazeretController extends Controller
{
    use ApiResponseTrait;

    private const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    private const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5MB

    /**
     * GET /api/student/mazerets
     */
    public function index(Request $request): JsonResponse
    {
        $studentIds = $this->ownedStudentIds($request);

        $mazerets = MazeretRequest::with(['classroom:id,name,code', 'reviewer:id,first_name,last_name,name'])
            ->whereIn('student_id', $studentIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MazeretRequest $m) => $this->transform($m));

        return $this->successResponse($mazerets);
    }

    /**
     * POST /api/student/mazerets  (multipart/form-data)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'date'         => ['required', 'date'],
            'reason'       => ['nullable', 'string', 'max:500'],
            'file'         => ['required', 'file', 'max:'.(self::MAX_FILE_BYTES / 1024)], // KB
        ]);

        $file = $request->file('file');

        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return $this->validationErrorResponse(
                ['file' => ['Sadece PDF veya resim (JPEG/PNG/WEBP) yükleyebilirsiniz.']]
            );
        }

        // Öğrencinin bu sınıfta gerçekten kaydı var mı?
        /** @var Student $student */
        $student = $request->user();
        $studentRow = Student::where('student_number', $student->student_number)
            ->where('classroom_id', $data['classroom_id'])
            ->first();

        if (! $studentRow) {
            return $this->forbiddenResponse('Bu sınıfa kayıtlı değilsiniz.');
        }

        $date = Carbon::parse($data['date'])->toDateString();

        // Aynı (öğrenci, sınıf, tarih) için zaten pending/approved kayıt var mı?
        $existing = MazeretRequest::where('student_id', $studentRow->id)
            ->where('classroom_id', $data['classroom_id'])
            ->where('date', $date)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            $statusTr = $existing->status === 'pending' ? 'beklemede' : 'onaylanmış';
            return $this->errorResponse(
                "Bu tarih için zaten {$statusTr} bir mazeretiniz var.",
                422
            );
        }

        // Dosyayı private disk'e kaydet
        $ext = $file->getClientOriginalExtension() ?: $this->extFromMime($file->getMimeType());
        $relPath = "mazeret/{$data['classroom_id']}/".Str::uuid().'.'.$ext;
        Storage::disk('local')->putFileAs(
            'mazeret/'.$data['classroom_id'],
            $file,
            basename($relPath)
        );

        $mazeret = MazeretRequest::create([
            'student_id'         => $studentRow->id,
            'classroom_id'       => $data['classroom_id'],
            'date'               => $date,
            'reason'             => $data['reason'] ?? null,
            'file_path'          => $relPath,
            'file_original_name' => $file->getClientOriginalName(),
            'file_mime'          => $file->getMimeType(),
            'file_size'          => $file->getSize(),
            'status'             => 'pending',
        ]);

        // Hocaya bildirim
        $classroom = Classroom::find($data['classroom_id']);
        if ($classroom) {
            InboxMessage::create([
                'user_id' => $classroom->user_id,
                'type'    => 'info',
                'title'   => 'Yeni Mazeret Talebi',
                'body'    => "{$studentRow->name} ({$classroom->name}) {$date} için mazeret yükledi.",
                'link'    => '/dashboard/mazeret-approvals',
            ]);
        }

        return $this->createdResponse($this->transform($mazeret->fresh(['classroom', 'reviewer'])));
    }

    /**
     * GET /api/student/mazerets/{mazeret}
     */
    public function show(Request $request, MazeretRequest $mazeret): JsonResponse
    {
        $this->authorize($request, $mazeret);
        return $this->successResponse($this->transform($mazeret->load(['classroom', 'reviewer'])));
    }

    /**
     * DELETE /api/student/mazerets/{mazeret}
     * Sadece pending durumda silinebilir.
     */
    public function destroy(Request $request, MazeretRequest $mazeret): JsonResponse
    {
        $this->authorize($request, $mazeret);

        if (! $mazeret->isPending()) {
            return $this->errorResponse('Yalnızca beklemedeki mazeretler silinebilir.', 422);
        }

        Storage::disk('local')->delete($mazeret->file_path);
        $mazeret->delete();

        return $this->successResponse(null, 'Mazeret silindi.');
    }

    private function ownedStudentIds(Request $request): array
    {
        /** @var Student $student */
        $student = $request->user();
        return Student::where('student_number', $student->student_number)
            ->pluck('id')
            ->all();
    }

    private function authorize(Request $request, MazeretRequest $mazeret): void
    {
        if (! in_array($mazeret->student_id, $this->ownedStudentIds($request), true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu kayda erişim yetkiniz yok.',
            ], 403));
        }
    }

    private function extFromMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            default           => 'bin',
        };
    }

    private function transform(MazeretRequest $m): array
    {
        return [
            'id'                 => $m->id,
            'student_id'         => $m->student_id,
            'classroom_id'       => $m->classroom_id,
            'classroom_name'     => $m->classroom?->name,
            'classroom_code'     => $m->classroom?->code,
            'date'               => $m->date?->toDateString(),
            'reason'             => $m->reason,
            'file_original_name' => $m->file_original_name,
            'file_mime'          => $m->file_mime,
            'file_size'          => $m->file_size,
            'status'             => $m->status,
            'reviewer_name'      => $m->reviewer?->name
                ?? ($m->reviewer ? trim(($m->reviewer->first_name ?? '').' '.($m->reviewer->last_name ?? '')) : null),
            'reviewed_at'        => $m->reviewed_at?->toIso8601String(),
            'review_note'        => $m->review_note,
            'created_at'         => $m->created_at?->toIso8601String(),
        ];
    }
}
