<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Platform;
use Laravel\Socialite\Facades\Socialite;
use App\Helpers\LogHelper;

class AuthController extends Controller
{
    
    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required',
                'email' => 'required|email|unique:users',
                'password' => 'required|min:6',
                'role' => 'required|in:admin,sub_admin,sales',
                'platform_ids' => 'required_if:role,sales|array',
                'platform_ids.*' => 'exists:platforms,id',
            ]);
            
            
            \DB::beginTransaction();
            
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
            ]);
    
            
            if ($request->role === 'sales' && !empty($request->platform_ids)) {
                $user->platforms()->attach($request->platform_ids);
            }
            
            
            \DB::commit();
            
            
            $user->load('platforms');
            
            LogHelper::record($user, 'register', $request, ['email' => $request->email]);
    
            return response()->json([
                'success' => true,
                'message' => 'User registered successfully', 
                'user' => $user
            ], 201);
    
        } catch (\Illuminate\Validation\ValidationException $e) {
            \DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Registration error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

   // Login
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
        
            $user = User::where('email', $request->email)->first();
        
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid credentials'
                ], 401);
            }
        
            // Create session
            Auth::login($user);
            
            LogHelper::record($user, 'login', $request, ['email' => $request->email]);
        
            // Load platforms for sales users
            if ($user->role === 'sales') {
                $user->load('platforms');
            }
        
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'user' => $user
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Login error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Login failed'
            ], 500);
        }
    }

    // Update user with platform assignment
    public function updateUser(Request $request, $id)
    {
        $user = User::find($id);
    
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
    
        $request->validate([
            'name' => 'sometimes|required|string',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|nullable|min:6',
            'role' => 'sometimes|required|in:admin,sub_admin,sales',
            'platform_ids' => 'sometimes|required_if:role,sales|array',
            'platform_ids.*' => 'exists:platforms,id',
        ]);
    
        $user->name = $request->name ?? $user->name;
        $user->email = $request->email ?? $user->email;
        $user->role = $request->role ?? $user->role;
    
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
    
        $user->save();
        
        // Update platform assignments for sales users
        if ($user->role === 'sales' && $request->has('platform_ids')) {
            $user->platforms()->sync($request->platform_ids);
        } elseif ($user->role !== 'sales') {
            // Remove platform assignments if role is not sales
            $user->platforms()->detach();
        }
        
        LogHelper::record($user, 'update_user', $request, $request->all());
    
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->load('platforms')
        ]);
    }

    // Get users with their platforms
    public function getUsers(Request $request)
    {
        $users = User::with('platforms')
                     ->select('id', 'name', 'email', 'role', 'created_at', 'updated_at')
                     ->orderBy('created_at', 'desc')
                     ->get();
    
        return response()->json([
            'message' => 'User list retrieved successfully',
            'users' => $users
        ]);
    }

    // Get platforms for the authenticated sales user
    public function getMyPlatforms(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'sales') {
            return response()->json(['error' => 'Only sales users can access platforms'], 403);
        }
        
        $platforms = $user->platforms()->where('is_active', true)->get();
        
        return response()->json([
            'message' => 'Platforms retrieved successfully',
            'platforms' => $platforms
        ]);
    }
    
    public function logout(Request $request)
    {
        $user = Auth::user();
        
        LogHelper::record($user, 'logout', $request);
        
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    
        return response()->json(['message' => 'Logged out']);
    }

    
    public function redirectToGoogle()
    {
        try {
            $url = Socialite::driver('google')
                ->stateless()
                ->redirect()
                ->getTargetUrl();
    
            return response()->json(['url' => $url]);
    
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to initiate Google authentication',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    
public function handleGoogleCallback()
{
    try {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::where('email', $googleUser->email)->first();

        if (!$user) {
            return redirect()->away('https://testing.exotic-ads.com/login?error=Email%20not%20allowed');
        }

        // Update the name
        $user->update(['name' => $googleUser->name]);

        // Generate token
        $token = $user->createToken('google-auth')->plainTextToken;

        // Load platforms
        if ($user->role === 'sales') {
            $user->load('platforms');
        }

        // REDIRECT BACK TO FRONTEND
        $redirectUrl = 'https://testing.exotic-ads.com/google-auth-success'
            . '?success=true'
            . '&token=' . urlencode($token)
            . '&id=' . $user->id
            . '&name=' . urlencode($user->name)
            . '&email=' . urlencode($user->email)
            . '&role=' . urlencode($user->role);

        return redirect()->away($redirectUrl);

    } catch (\Exception $e) {
        return redirect()->away('https://testing.exotic-ads.com/login?success=false&error=Google%20login%20failed');
    }
}


public function googleAuthSuccess(Request $request)
{
    // build query params
    $data = $request->query();

    // JSON encode
    $json = json_encode($data);

    return response("
        <script>
            // Send data to the opener window
            window.opener.postMessage($json, '*');
            window.close();
        </script>
    ", 200)->header('Content-Type', 'text/html');
}


    
    // Add this endpoint for direct authentication
    public function directAuth()
    {
        try {
            if (Auth::check()) {
                return response()->json([
                    'authenticated' => true,
                    'user' => Auth::user()
                ]);
            }
            
            return response()->json([
                'authenticated' => false,
                'authUrl' => Socialite::driver('google')->stateless()->redirect()->getTargetUrl()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to initiate authentication',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}