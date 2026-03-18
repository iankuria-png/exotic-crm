<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class WelcomeTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'title' => 'Welcome — SMS',
                'category' => 'welcome',
                'channel' => 'sms',
                'subject' => null,
                'body' => 'Welcome {{client_name}}! Your {{plan_name}} subscription is now active. View your profile: {{profile_url}} Need help? Chat with us: {{support_chat_url}}',
                'variables' => ['client_name', 'plan_name', 'profile_url', 'support_chat_url'],
            ],
            [
                'title' => 'Welcome — Email',
                'category' => 'welcome',
                'channel' => 'email',
                'subject' => 'Welcome to Exotic — your {{plan_name}} is active',
                'body' => "Hi {{client_name}},\n\nWelcome! Your {{plan_name}} subscription is now active.\n\nYour profile: {{profile_url}}\nSupport chat: {{support_chat_url}}\n\nTo get the most out of your subscription, make sure your profile is complete with photos, services, and rates.\n\nThank you for choosing Exotic!",
                'variables' => ['client_name', 'plan_name', 'profile_url', 'support_chat_url'],
            ],
        ];

        foreach ($templates as $tpl) {
            Template::updateOrCreate(
                [
                    'category' => $tpl['category'],
                    'channel' => $tpl['channel'],
                    'platform_id' => null,
                ],
                [
                    'title' => $tpl['title'],
                    'subject' => $tpl['subject'],
                    'body' => $tpl['body'],
                    'variables' => $tpl['variables'],
                    'status' => 'active',
                ]
            );
        }
    }
}
