<?php

namespace Database\Seeders;

use App\Models\RenewalCampaign;
use App\Models\Template;
use Illuminate\Database\Seeder;

class SprintThreeTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            -7 => Template::updateOrCreate(
                ['title' => 'Renewal SMS Day -7', 'channel' => 'sms'],
                [
                    'platform_id' => null,
                    'category' => 'renewal',
                    'subject' => null,
                    'body' => 'Hi {{client_name}}, your {{plan_name}} package expires on {{expiry_date}}. Renew early to stay visible and avoid downtime.',
                    'variables' => ['client_name', 'plan_name', 'expiry_date'],
                    'status' => 'active',
                ]
            ),
            -3 => Template::updateOrCreate(
                ['title' => 'Renewal SMS Day -3', 'channel' => 'sms'],
                [
                    'platform_id' => null,
                    'category' => 'renewal',
                    'subject' => null,
                    'body' => 'Reminder: {{plan_name}} expires in 3 days on {{expiry_date}}. Reply to renew today and keep your profile active.',
                    'variables' => ['plan_name', 'expiry_date'],
                    'status' => 'active',
                ]
            ),
            0 => Template::updateOrCreate(
                ['title' => 'Renewal SMS Day 0', 'channel' => 'sms'],
                [
                    'platform_id' => null,
                    'category' => 'renewal',
                    'subject' => null,
                    'body' => 'Action needed: your {{plan_name}} package expires today ({{expiry_date}}). Reply now for immediate extension.',
                    'variables' => ['plan_name', 'expiry_date'],
                    'status' => 'active',
                ]
            ),
            3 => Template::updateOrCreate(
                ['title' => 'Renewal SMS Day +3', 'channel' => 'sms'],
                [
                    'platform_id' => null,
                    'category' => 'win_back',
                    'subject' => null,
                    'body' => 'Hi {{client_name}}, your listing expired {{days_since_expiry}} days ago. Reply to reactivate your {{plan_name}} package today.',
                    'variables' => ['client_name', 'days_since_expiry', 'plan_name'],
                    'status' => 'active',
                ]
            ),
        ];

        // Before-expiry reminders (-7, -3) ship OFF by default globally to keep
        // short-cycle markets from being spammed; markets opt in per-market. The
        // on-expiry (0) and post-expiry (+3) reminders stay on. `enabled` is only
        // set on first creation so an admin's later toggle is never overwritten.
        $earlyReminderTriggers = [-7, -3];

        foreach ($templates as $triggerDays => $template) {
            $campaign = RenewalCampaign::firstOrNew(
                ['product_id' => null, 'platform_id' => null, 'trigger_days' => $triggerDays, 'channel' => 'sms']
            );
            $campaign->template_id = $template->id;
            if (!$campaign->exists) {
                $campaign->enabled = !in_array($triggerDays, $earlyReminderTriggers, true);
            }
            $campaign->save();
        }

        foreach ($templates as $triggerDays => $template) {
            $whatsAppTemplate = Template::updateOrCreate(
                ['title' => str_replace('Renewal SMS', 'Renewal WhatsApp', $template->title), 'channel' => 'whatsapp'],
                [
                    'platform_id' => null,
                    'category' => $template->category,
                    'subject' => null,
                    'body' => $template->body . ' Reply STOP to opt out.',
                    'variables' => $template->variables,
                    'status' => 'active',
                ]
            );

            $campaign = RenewalCampaign::firstOrNew(
                ['product_id' => null, 'platform_id' => null, 'trigger_days' => $triggerDays, 'channel' => 'whatsapp']
            );
            $campaign->template_id = $whatsAppTemplate->id;
            if (!$campaign->exists) {
                $campaign->enabled = false;
            }
            $campaign->save();
        }
    }
}
