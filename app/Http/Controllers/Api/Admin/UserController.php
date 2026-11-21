<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $users = User::query()
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'two_factor_confirmed_at', 'created_at'])
            ->map(fn (User $user) => $this->payload($user));

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(['admin', 'finance', 'tic'])],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $data['phone'] = $this->phone($data['phone'] ?? null);

        return response()->json(['data' => $this->payload(User::create($data))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'finance', 'tic'])],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ]);
        if (array_key_exists('phone', $data)) {
            $data['phone'] = $this->phone($data['phone']);
        }
        $user->update($data);

        return response()->json(['data' => $this->payload($user)]);
    }

    public function setupTwoFactor(Request $request, User $user, TotpService $totp): JsonResponse
    {
        abort_unless($request->user()->is($user), 403, 'Cada usuário deve configurar o próprio autenticador.');
        $secret = $totp->secret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_uri' => $totp->uri($secret, $user->email),
        ]]);
    }

    public function confirmTwoFactor(Request $request, User $user, TotpService $totp): JsonResponse
    {
        abort_unless($request->user()->is($user), 403, 'Cada usuário deve configurar o próprio autenticador.');
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        if (! $user->two_factor_secret || ! $totp->verify($user->two_factor_secret, $data['code'])) {
            return response()->json(['message' => 'Código inválido. Confira o relógio do aparelho e tente novamente.'], 422);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json(['data' => $this->payload($user)]);
    }

    public function disableTwoFactor(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->is($user) || $request->user()->role === 'admin', 403);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $user->tokens()->delete();

        return response()->json(['data' => $this->payload($user)]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'Você não pode excluir o próprio usuário.');
        abort_if(User::query()->count() <= 1, 422, 'O painel precisa manter ao menos um administrador.');
        $user->tokens()->delete();
        $user->delete();

        return response()->json(status: 204);
    }

    private function payload(User $user): array
    {
        return [
            ...$user->only(['id', 'name', 'email', 'phone', 'role', 'created_at']),
            'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
        ];
    }

    private function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: null;

        return $digits;
    }
}
