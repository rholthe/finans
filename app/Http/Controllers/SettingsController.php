<?php

namespace App\Http\Controllers;

use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => AppSettings::all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            AppSettings::MANUAL_SYNC_DAYS => ['sometimes', 'integer', 'min:1', 'max:'.AppSettings::MAX[AppSettings::MANUAL_SYNC_DAYS]],
            AppSettings::AUTO_SYNC_DAYS => ['sometimes', 'integer', 'min:1', 'max:'.AppSettings::MAX[AppSettings::AUTO_SYNC_DAYS]],
            AppSettings::REPORT_EMAIL => ['sometimes', 'nullable', 'email'],
        ]);

        foreach ($validated as $key => $value) {
            // En tom e-post nullstiller innstillingen (faller tilbake til config).
            AppSettings::set($key, $value ?? '');
        }

        return response()->json(['data' => AppSettings::all()]);
    }
}
