<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class FollowUpTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'title' => 'Follow-up After Contact — SMS',
                'category' => 'follow_up',
                'channel' => 'sms',
                'subject' => null,
                'body' => 'Hi {{client_name}}, this is {{agent_name}} following up on our conversation about {{plan_name}}. Let me know if you have any questions or are ready to proceed.',
                'variables' => ['client_name', 'agent_name', 'plan_name'],
            ],
            [
                'title' => 'Follow-up Reminder — SMS',
                'category' => 'follow_up',
                'channel' => 'sms',
                'subject' => null,
                'body' => 'Hi {{client_name}}, just a friendly reminder about the {{plan_name}} package we discussed. Reply to this message if you would like to get started.',
                'variables' => ['client_name', 'plan_name'],
            ],
        ];

        foreach ($templates as $tpl) {
            Template::updateOrCreate(
                [
                    'title' => $tpl['title'],
                    'channel' => $tpl['channel'],
                    'platform_id' => null,
                ],
                [
                    'category' => $tpl['category'],
                    'subject' => $tpl['subject'],
                    'body' => $tpl['body'],
                    'variables' => $tpl['variables'],
                    'status' => 'active',
                ]
            );
        }
    }
}
