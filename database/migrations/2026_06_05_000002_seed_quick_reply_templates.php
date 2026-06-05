<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $templates = [
            [
                'title' => 'Quick Reply — Win-back',
                'category' => 'win_back',
                'body' => "Hey {{client_name}} 💕, I noticed your listing expired {{days_since_expiry}} days ago. Is there a reason you haven't been able to renew? I'm here to help you get back online.",
                'variables' => ['client_name', 'days_since_expiry'],
            ],
            [
                'title' => 'Quick Reply — Expiring Soon',
                'category' => 'renewal',
                'body' => 'Hi {{client_name}}, your subscription expires in {{days_left}} days ({{expiry_date}}). Want me to help you renew so your profile stays visible?',
                'variables' => ['client_name', 'days_left', 'expiry_date'],
            ],
            [
                'title' => 'Quick Reply — Never Paid',
                'category' => 'payment',
                'body' => "Hi {{client_name}}, I saw you signed up but haven't completed payment yet. Can I help you activate your profile today?",
                'variables' => ['client_name'],
            ],
            [
                'title' => 'Quick Reply — Check-in',
                'category' => 'welcome',
                'body' => 'Hi {{client_name}}, just checking in from the {{platform_name}} team — how is everything going with your profile? Happy to help with anything you need.',
                'variables' => ['client_name', 'platform_name'],
            ],
        ];

        foreach ($templates as $template) {
            DB::table('templates')->updateOrInsert(
                [
                    'title' => $template['title'],
                    'channel' => 'whatsapp',
                    'platform_id' => null,
                ],
                [
                    'category' => $template['category'],
                    'subject' => null,
                    'body' => $template['body'],
                    'variables' => json_encode($template['variables']),
                    'status' => 'active',
                    'is_quick_reply' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('templates')
            ->whereNull('platform_id')
            ->where('channel', 'whatsapp')
            ->whereIn('title', [
                'Quick Reply — Win-back',
                'Quick Reply — Expiring Soon',
                'Quick Reply — Never Paid',
                'Quick Reply — Check-in',
            ])
            ->delete();
    }
};
