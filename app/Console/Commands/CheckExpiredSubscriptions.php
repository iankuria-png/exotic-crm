<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Services\DynamicDatabaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:check';
    protected $description = 'Check and deactivate expired subscriptions';

    public function handle()
    {
        Log::info('Starting subscription expiration check');
        $now = Carbon::now();
        
        try {
            $this->info("Checking at: {$now->toDateTimeString()}");
            
            // Get active payments that have expired
            $expiredPayments = Payment::where('status', 'completed')
                ->where('end_date', '<=', $now)
                ->with(['platform', 'product']) 
                ->get();

            $this->info("Found {$expiredPayments->count()} potentially expired payments");

            $processedCount = 0;
            $failedCount = 0;

            foreach ($expiredPayments as $payment) {
                try {
                    $this->info("Processing payment ID: {$payment->id} for user {$payment->user_id}");
                    
                    $result = $this->deactivateUserServices($payment->user_id, $payment);
                    
                    $payment->update(['status' => 'expired']);
                    $processedCount++;
                    
                    $this->info("Successfully processed payment ID: {$payment->id}. Deactivated {$result} posts");
                    
                    // Send expiration SMS
                    $this->sendExpirationSMS($payment);
                    
                    Log::info('Successfully deactivated subscription', [
                        'payment_id' => $payment->id,
                        'user_id' => $payment->user_id,
                        'end_date' => $payment->end_date
                    ]);
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("Failed processing payment {$payment->id}: " . $e->getMessage());
                    
                    Log::error('Failed to deactivate subscription', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $this->info("Processed {$processedCount} expired subscriptions");
            if ($failedCount > 0) {
                $this->error("Failed to process {$failedCount} subscriptions");
            }

            return $processedCount > 0 ? 0 : 1;
            
        } catch (\Exception $e) {
            $this->error('Command failed completely: ' . $e->getMessage());
            Log::error('Subscription check command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    protected function deactivateUserServices($userId, Payment $payment)
    {
        if (!$payment->platform) {
            Log::error('Platform not found for payment', ['payment_id' => $payment->id]);
            return 0;
        }

        // Use dynamic connection
        $connectionName = 'platform_' . $payment->platform->id;
        
        try {
            // Switch to platform's database connection
            app(DynamicDatabaseService::class)->switchConnection(
                $connectionName, 
                $payment->platform->getConnectionConfig()
            );

            // Get all escort posts for this user
            $posts = DB::connection($connectionName)
                ->table('posts')
                ->where('post_author', $userId)
                ->where('post_type', 'escort')
                ->get(['ID']);
            
            if ($posts->isEmpty()) {
                Log::warning('No posts found for user during deactivation', [
                    'user_id' => $userId,
                    'payment_id' => $payment->id,
                    'platform_id' => $payment->platform_id
                ]);
                return 0;
            }

            $postIds = $posts->pluck('ID')->toArray();

            // Start transaction
            DB::connection($connectionName)->beginTransaction();
            
            try {
                // Update post status to private
                $updatedPosts = DB::connection($connectionName)
                    ->table('posts')
                    ->whereIn('ID', $postIds)
                    ->update(['post_status' => 'private']);

                // Update notactive flag
                DB::connection($connectionName)
                    ->table('postmeta')
                    ->whereIn('post_id', $postIds)
                    ->where('meta_key', 'notactive')
                    ->update(['meta_value' => '1']);

                // Remove premium/featured status
                DB::connection($connectionName)
                    ->table('postmeta')
                    ->whereIn('post_id', $postIds)
                    ->whereIn('meta_key', ['premium', 'featured'])
                    ->update(['meta_value' => '0']);

                DB::connection($connectionName)->commit();

                return $updatedPosts;
                
            } catch (\Exception $e) {
                DB::connection($connectionName)->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to deactivate user services', [
                'user_id' => $userId,
                'payment_id' => $payment->id,
                'platform_id' => $payment->platform_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function sendExpirationSMS(Payment $payment)
    {
        try {
            $platform = $payment->platform;
            $product = $payment->product;
            
            $message = "Your {$product->name} subscription on {$platform->name} has expired. " .
                      "To continue enjoying our services, please renew your subscription.";
            
            // Send SMS
            $smsResponse = $this->sendSMS($payment->phone, $message, $payment);
            
            // Save to SMS log
            DB::table('sms_logs')->insert([
                'payment_id' => $payment->id,
                'phone' => $payment->phone,
                'message' => $message,
                'status' => $smsResponse['success'] ? 'sent' : 'failed',
                'response' => $smsResponse['message'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            Log::info('Expiration SMS sent', [
                'payment_id' => $payment->id,
                'phone' => $payment->phone,
                'success' => $smsResponse['success']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send expiration SMS', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function sendSMS($phone, $message, $payment = null)
    {
        try {
            $phoneNumberToUse = $phone;
            
            if ($payment && $payment->platform_id) {
                $platform = Platform::find($payment->platform_id);
                if ($platform) {
                    $connectionName = 'platform_' . $platform->id;
                    DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
                    
                    // Try to get escort phone number
                    $escortPost = DB::connection($connectionName)
                        ->table('posts')
                        ->where('post_author', $payment->user_id)
                        ->where('post_type', 'escort')
                        ->first();
                    
                    if ($escortPost) {
                        $phoneMeta = DB::connection($connectionName)
                            ->table('postmeta')
                            ->where('post_id', $escortPost->ID)
                            ->where('meta_key', 'phone')
                            ->first();
                        
                        if ($phoneMeta && $phoneMeta->meta_value) {
                            $escortPhone = $this->normalizePhone($phoneMeta->meta_value);
                            if (preg_match('/^254[0-9]{9}$/', $escortPhone)) {
                                $phoneNumberToUse = $escortPhone;
                            }
                        }
                    }
                }
            }
            
            // Send SMS using your gateway
            $smsResponse = Http::timeout(15)
                ->retry(2, 500)
                ->post('http://138.201.58.10:8093/SendMessageFON', [
                    'Phonenumber' => $phoneNumberToUse,
                    'OrgCode' => '58',
                    'Message' => $message
                ]);
            
            return [
                'success' => $smsResponse->successful(),
                'message' => $smsResponse->successful() ? 'SMS sent successfully' : 'SMS gateway error',
                'response' => $smsResponse->body()
            ];
            
        } catch (\Exception $e) {
            Log::error('SMS sending failed in command', [
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    protected function normalizePhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '254')) {
            $phone = '254' . ltrim($phone, '254');
        }
        
        return $phone;
    }
}