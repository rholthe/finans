<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Sjekk om gjeldende økt er innlogget.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => (bool) $request->session()->get('authenticated', false),
        ]);
    }

    /**
     * Logg inn med appens enkeltpassord. Setter en innlogget økt
     * som varer i ett år (se SESSION_LIFETIME i .env).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $hash = config('auth.app_password_hash');

        if (! $hash || ! Hash::check($validated['password'], $hash)) {
            throw ValidationException::withMessages([
                'password' => ['Feil passord.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('authenticated', true);

        return response()->json(['authenticated' => true]);
    }

    /**
     * Logg ut og forkast økten.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['authenticated' => false]);
    }
}
