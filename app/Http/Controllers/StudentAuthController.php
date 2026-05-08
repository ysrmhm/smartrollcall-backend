<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetCodeMail;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentAuthController extends Controller
{
    use ApiResponseTrait;

    /**
     * POST /api/student/login
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'student_number' => ['required', 'string', 'max:30'],
            'password'       => ['required', 'string'],
        ]);

        // Aynı student_number birden fazla classroom'da olabilir; ilk eşleşen alınır.
        $student = Student::where('student_number', $credentials['student_number'])->first();

        if (! $student || ! Hash::check($credentials['password'], $student->password ?? '')) {
            return $this->unauthorizedResponse('Öğrenci numarası veya şifre hatalı!');
        }

        $student->forceFill(['last_login_at' => Carbon::now()])->save();

        $token = $student->createToken('student-auth')->plainTextToken;

        return $this->successResponse([
            'access_token'         => $token,
            'token_type'           => 'Bearer',
            'must_change_password' => (bool) $student->must_change_password,
            'student'              => $this->transform($student),
        ], 'Giriş başarılı!');
    }

    /**
     * GET /api/student/me  (auth:student)
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse($this->transform($request->user()));
    }

    /**
     * POST /api/student/change-password  (auth:student)
     * İlk giriş zorunlu değişiklik + sonraki gönüllü değişiklikler için tek endpoint.
     */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:6', 'confirmed', 'different:current_password'],
        ]);

        if (! Hash::check($data['current_password'], $student->password ?? '')) {
            return $this->errorResponse('Mevcut şifre hatalı.', 422);
        }

        $student->forceFill([
            'password'             => $data['password'],
            'must_change_password' => false,
        ])->save();

        $student->tokens()->where('id', '!=', $student->currentAccessToken()->id)->delete();

        return $this->successResponse(null, 'Şifreniz güncellendi.');
    }

    /**
     * POST /api/student/logout  (auth:student)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse(null, 'Çıkış başarılı!');
    }

    // ===========================================================================
    // ŞİFREMİ UNUTTUM AKIŞI (public — auth gerektirmez)
    // ===========================================================================
    // Hocanın AuthService'inde olan akışın öğrenci versiyonu.
    // password_reset_codes tablosu paylaşılır; çakışmasın diye username olarak
    // 'student:{numara}' formatı kullanılır.

    private const RESET_KEY_PREFIX = 'student:';
    private const RESET_CODE_TTL_MINUTES = 15;

    /**
     * POST /api/student/forgot-password
     * Body: { student_number }
     * Öğrenciye 6 haneli kod e-postası gönderir. Kullanıcı varlığını sızdırmaz.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:30'],
        ]);

        $sNum = $data['student_number'];

        // Aynı numaraya sahip öğrencilerden email'i olanı bul (çoklu sınıfta birden fazla satır olabilir)
        $student = Student::where('student_number', $sNum)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();

        if (! $student) {
            // Sızdırma yapma — başka öğrenci numarası varsa "yok" demeyiz
            $sNumExists = Student::where('student_number', $sNum)->exists();
            if (! $sNumExists) {
                usleep(200_000);
                return $this->successResponse(
                    null,
                    'Eğer bu öğrenci numarası sistemde kayıtlı ise, kayıtlı e-postaya 6 haneli sıfırlama kodu gönderildi.'
                );
            }
            return $this->errorResponse(
                'Bu öğrenci numarasında kayıtlı bir e-posta yok. Lütfen hocanıza başvurun.',
                422
            );
        }

        $code = (string) random_int(100000, 999999);
        $resetKey = self::RESET_KEY_PREFIX . $sNum;

        DB::table('password_reset_codes')->updateOrInsert(
            ['username' => $resetKey],
            [
                'code_hash'  => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes(self::RESET_CODE_TTL_MINUTES),
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]
        );

        try {
            Mail::to($student->email)->send(new PasswordResetCodeMail(
                name: $student->name,
                username: 'Öğrenci No: '.$sNum,
                code: $code,
                expiresInMinutes: self::RESET_CODE_TTL_MINUTES,
            ));
        } catch (\Throwable $e) {
            Log::error('Student password reset mail failed', [
                'student_number' => $sNum,
                'error'          => $e->getMessage(),
            ]);
            return $this->errorResponse('E-posta gönderilemedi. Lütfen daha sonra tekrar deneyin.', 500);
        }

        return $this->successResponse(
            null,
            'Eğer bu öğrenci numarası sistemde kayıtlı ise, kayıtlı e-postaya 6 haneli sıfırlama kodu gönderildi.'
        );
    }

    /**
     * POST /api/student/reset-password
     * Body: { student_number, code, password, password_confirmation }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:30'],
            'code'           => ['required', 'string', 'size:6'],
            'password'       => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $sNum = $data['student_number'];
        $resetKey = self::RESET_KEY_PREFIX . $sNum;

        $row = DB::table('password_reset_codes')->where('username', $resetKey)->first();
        $students = Student::where('student_number', $sNum)->get();

        if ($students->isEmpty() || ! $row) {
            return $this->errorResponse('Bu öğrenci için aktif bir sıfırlama kodu yok. Lütfen yeni kod talep edin.', 422);
        }

        if (Carbon::parse($row->expires_at)->isPast()) {
            DB::table('password_reset_codes')->where('username', $resetKey)->delete();
            return $this->errorResponse('Kodun süresi dolmuş. Lütfen yeni bir kod talep edin.', 422);
        }

        if (! Hash::check($data['code'], $row->code_hash)) {
            return $this->errorResponse('Kod yanlış. Lütfen e-postanızı kontrol edin.', 422);
        }

        DB::transaction(function () use ($students, $data, $resetKey) {
            // Aynı student_number'lı tüm Student satırlarına yeni şifreyi yaz + must_change_password=false
            foreach ($students as $s) {
                $s->forceFill([
                    'password'             => $data['password'], // model cast 'hashed' otomatik
                    'must_change_password' => false,
                ])->save();
                // Eski tokenları geçersiz kıl
                $s->tokens()->delete();
            }
            DB::table('password_reset_codes')->where('username', $resetKey)->delete();
        });

        return $this->successResponse(null, 'Şifreniz başarıyla güncellendi! Giriş yapabilirsiniz.');
    }

    private function transform(Student $student): array
    {
        return self::transformStudent($student);
    }

    public static function transformStudent(Student $student): array
    {
        $student->loadMissing('classroom');

        return [
            'id'                   => $student->id,
            'classroom_id'         => $student->classroom_id,
            'student_number'       => $student->student_number,
            'first_name'           => $student->first_name,
            'last_name'            => $student->last_name,
            'name'                 => $student->name,
            'email'                => $student->email,
            'phone'                => $student->phone,
            'avatar'               => $student->avatar,
            'must_change_password' => (bool) $student->must_change_password,
            'last_login_at'        => optional($student->last_login_at)->toIso8601String(),
            'classroom'            => $student->classroom ? [
                'id'         => $student->classroom->id,
                'name'       => $student->classroom->name,
                'code'       => $student->classroom->code,
                'department' => $student->classroom->department,
                'day'        => $student->classroom->day,
                'time'       => $student->classroom->time,
            ] : null,
        ];
    }
}
