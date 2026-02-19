<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    // Redirect to Google
    public function redirectToGoogle()
    {
        try {
            return Socialite::driver('google')
                ->stateless() // Use stateless if using API token
                ->redirect();
        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . '/login?error=Unable to initiate Google login');
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



    // Handle callback from Google
   public function handleGoogleCallback()
{
    try {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::where('email', $googleUser->email)->first();

        if (!$user) {
            return redirect()->away('https://testing.exotic-ads.com/login?error=Email%20not%20allowed');
        }


        $user->update(['name' => $googleUser->name]);


        $token = $user->createToken('google-auth')->plainTextToken;


        if ($user->role === 'sales') {
            $user->load('platforms');
        }


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




}
