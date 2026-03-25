<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\TeamActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function __construct(
        private readonly TeamActivityService $teamActivityService
    ) {
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (($user->status ?? 'active') !== 'active') {
            return response()->json(['message' => 'Account is inactive. Contact your administrator.'], 403);
        }

        $token = $user->createToken('crm-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status ?? 'active',
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status ?? 'active',
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $validated = $request->validate([
            'session_token' => 'nullable|string|max:36',
        ]);

        if (!empty($validated['session_token'])) {
            $this->teamActivityService->closeUserSession(
                $request->user(),
                (string) $validated['session_token']
            );
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
