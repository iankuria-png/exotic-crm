<?php
namespace App\Helpers;
use Illuminate\Database\Eloquent\Factories\HasFactory;


use App\Models\ActivityLog;


class LogHelper
{
    public static function record($user, $action, $request, $payload = [])
    {
        ActivityLog::create([
            'user_id' => $user ? $user->id : null,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $payload,
        ]);
    }
}
