<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferencesController extends Controller
{
    use ApiResponseTrait;

    /**
     * PUT /api/preferences
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'defaultAbsenceLimit'   => ['required', 'integer', 'min:1', 'max:20'],
            'emailNotifications'    => ['required', 'boolean'],
            'weeklyReports'         => ['required', 'boolean'],
            'showArchivedInReports' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->preferences = array_merge(AuthService::defaultPreferences(), $data);
        $user->save();

        return $this->successResponse(
            $user->preferences,
            'Tercihler kaydedildi!'
        );
    }
}
