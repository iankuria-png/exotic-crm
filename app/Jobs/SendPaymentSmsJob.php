<?php

namespace App\Jobs;
use App\Models\Payment;
use App\Models\SmsLog;
use App\Models\WordpressPost;
use App\Models\WordpressPostMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendPaymentSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function handle()
    {
        try {
            $status = $this->payment->status;
            $amount = $this->payment->amount;
            $currency = $this->payment->currency ?? 'KES';

            $escortPost = WordpressPost::where('post_author', $this->payment->user_id)
                ->where('post_type', 'escort')
                ->first();

            if (!$escortPost) {
                Log::warning("No escort post found for user ID {$this->payment->user_id}");
                return;
            }

            $phoneMeta = WordpressPostMeta::where('post_id', $escortPost->ID)
                ->where('meta_key', 'phone')
                ->first();

            if (!$phoneMeta) {
                Log::warning("No phone meta found for post ID {$escortPost->ID}");
                return;
            }

            $phone = $phoneMeta->meta_value;

            switch ($status) {
                case 'success':
                    $message = "Payment of {$currency} {$amount} confirmed. Transaction ID: {$this->payment->transaction_reference}. Your profile is now active.";
                    break;
                case 'failed':
                    $message = "Your payment of {$currency} {$amount} failed. Please try again or contact support.";
                    break;
                default:
                    $message = "Your payment is still being processed. Please wait for confirmation.";
                    break;
            }

            $smsPayload = [
                'Phonenumber' => $phone,
                'OrgCode' => '58',
                'Message' => $message,
            ];

            $smsResponse = Http::post('http://138.201.58.10:8093/SendMessageFON', $smsPayload);

            SmsLog::create([
                'phone' => $phone,
                'message' => $message,
                'payment_id' => $this->payment->id,
                'status' => $smsResponse->successful() ? 'success' : 'failed',
                'response' => $smsResponse->body()
            ]);

            if ($smsResponse->successful()) {
                Log::info("SMS sent to {$phone}: {$message}");
            } else {
                Log::error("SMS failed for {$phone}: " . $smsResponse->body());
            }

        } catch (\Exception $e) {
            Log::error('Failed to send payment SMS: ' . $e->getMessage());
        }
    }
}
