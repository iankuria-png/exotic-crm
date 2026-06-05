<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class QuickReplyTemplateSeeder extends Seeder
{
    public function run(): void
    {
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
            Template::updateOrCreate(
                [
                    'title' => $template['title'],
                    'channel' => 'whatsapp',
                    'platform_id' => null,
                ],
                [
                    'category' => $template['category'],
                    'subject' => null,
                    'body' => $template['body'],
                    'variables' => $template['variables'],
                    'status' => 'active',
                    'is_quick_reply' => true,
                ]
            );
        }
    }
}
