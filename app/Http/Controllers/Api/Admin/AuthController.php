<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Credit\CreditRuleProposalService;
use App\Services\Security\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($credentials['email'])))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'As credenciais informadas são inválidas.',
            ]);
        }

        if ($user->two_factor_confirmed_at && $user->two_factor_secret) {
            $challenge = Str::random(64);
            Cache::put('admin-2fa:'.$challenge, [
                'user_id' => $user->id,
                'device_name' => $credentials['device_name'] ?? 'mpmm-painel',
            ], now()->addMinutes(5));

            return response()->json([
                'two_factor_required' => true,
                'challenge' => $challenge,
                'expires_in' => 300,
            ], 202);
        }

        return $this->authenticated($user, $credentials['device_name'] ?? 'mpmm-painel');
    }

    public function twoFactorChallenge(Request $request, TotpService $totp): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        $cacheKey = 'admin-2fa:'.$data['challenge'];
        $challenge = Cache::get($cacheKey);
        $user = $challenge ? User::find($challenge['user_id']) : null;

        if (! $user || ! $user->two_factor_confirmed_at || ! $totp->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'O código de autenticação é inválido ou expirou.']);
        }

        Cache::forget($cacheKey);

        return $this->authenticated($user, $challenge['device_name']);
    }

    private function authenticated(User $user, string $deviceName): JsonResponse
    {

        $token = $user->createToken(
            $deviceName,
            ['admin'],
            now()->addHours(12),
        );

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'credit_rules_preview_enabled' => app(CreditRuleProposalService::class)->visibleTo((string) $user->phone),
            'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
        ];
    }
}
