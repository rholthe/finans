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
        ]);

        foreach ($validated as $key => $value) {
            AppSettings::set($key, $value);
        }

        return response()->json(['data' => AppSettings::all()]);
    }
}
