<?php

namespace App\Services;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthService
{
    private const RESET_CODE_TTL_MINUTES = 15;

    public function authenticate(string $username, string $password): ?array
    {
        $user = User::where('username', $username)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => self::transform($user),
        ];
    }

    public static function transform(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'username'    => $user->username,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'institution' => $user->institution,
            'avatar'      => $user->avatar,
            'preferences' => $user->preferences ?? self::defaultPreferences(),
            'is_admin'    => (bool) $user->is_admin,
        ];
    }

    public static function defaultPreferences(): array
    {
        return [
            'defaultAbsenceLimit'    => 4,
            'emailNotifications'     => true,
            'weeklyReports'          => true,
            'showArchivedInReports'  => false,
        ];
    }

    /**
     * Kullanıcıya 6 haneli şifre sıfırlama kodu üret + e-posta gönder.
     * Kullanıcı yoksa, varlık bilgisini sızdırmamak için sessizce true döner.
     * Kullanıcının email'i yoksa false döner (UI'da yardım metni gösterilir).
     */
    public function sendPasswordResetCode(string $username): bool
    {
        $user = User::where('username', $username)->first();

        if (! $user) {
            // Sızdırma yapma: gerçekçi bir gecikme ve sessiz başarı
            usleep(200_000);
            return true;
        }

        if (empty($user->email)) {
            return false;
        }

        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_codes')->updateOrInsert(
            ['username' => $user->username],
            [
                'code_hash'  => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes(self::RESET_CODE_TTL_MINUTES),
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]
        );

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail(
                name: $user->name ?: $user->username,
                username: $user->username,
                code: $code,
                expiresInMinutes: self::RESET_CODE_TTL_MINUTES,
            ));
        } catch (\Throwable $e) {
            Log::error('Password reset mail failed', [
                'username' => $user->username,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Kod ile şifreyi günceller. Geri dönüş:
     *  - 'ok'         : başarılı
     *  - 'not_found'  : kullanıcı veya kod yok
     *  - 'expired'    : kodun süresi dolmuş
     *  - 'invalid'    : kod yanlış
     */
    public function resetPasswordWithCode(string $username, string $code, string $newPassword): string
    {
        $user = User::where('username', $username)->first();
        $row  = DB::table('password_reset_codes')->where('username', $username)->first();

        if (! $user || ! $row) {
            return 'not_found';
        }

        if (Carbon::parse($row->expires_at)->isPast()) {
            DB::table('password_reset_codes')->where('username', $username)->delete();
            return 'expired';
        }

        if (! Hash::check($code, $row->code_hash)) {
            return 'invalid';
        }

        $user->password = $newPassword; // model cast 'hashed' otomatik hashler
        $user->save();

        DB::table('password_reset_codes')->where('username', $username)->delete();
        $user->tokens()->delete(); // tüm aktif oturumlardan çık

        return 'ok';
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
