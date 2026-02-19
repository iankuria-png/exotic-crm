<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Helpers\LogHelper;
use Illuminate\Support\Facades\Log;

class SmsLogController extends Controller
{
    public function messages(Request $request)
    {
        try {
            // Get paginated SMS logs (default 50 per page)
            $smsLogs = SmsLog::orderBy('created_at', 'desc')->paginate($request->get('per_page', 50));
            
            // Log successful retrieval
            LogHelper::record(
                $request->user(),
                'sms_logs_retrieved',
                $request,
                [
                    'total' => $smsLogs->total(),
                    'per_page' => $smsLogs->perPage(),
                    'current_page' => $smsLogs->currentPage()
                ]
            );

            return response()->json([
                'status' => 'success',
                'sms_logs' => $smsLogs->items(),
                'pagination' => [
                    'total' => $smsLogs->total(),
                    'per_page' => $smsLogs->perPage(),
                    'current_page' => $smsLogs->currentPage(),
                    'last_page' => $smsLogs->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            // Log retrieval failure
            LogHelper::record(
                $request->user(),
                'sms_logs_retrieval_failed',
                $request,
                ['error' => $e->getMessage()]
            );
            
            Log::error('Failed to retrieve SMS logs: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SMS logs',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}