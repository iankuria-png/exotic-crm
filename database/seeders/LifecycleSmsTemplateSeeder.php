<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Warm, human, link-bearing lifecycle SMS copy (global defaults — every market
 * can override with its own template from Settings → Templates).
 *
 * Copy principles: lead with the client's own win ({{views_hook}} always has a
 * graceful non-numeric fallback), one clear CTA, one link, first name, no
 * "Dear valued customer" robo-filler.
 */
class LifecycleSmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'title' => 'Lifecycle — Welcome & activate (SMS)',
                'category' => 'new_signup',
                'body' => "Hi {{first_name}}! Your profile is ready to go live — clients are searching right now. Activate {{plan_name}} ({{currency}} {{amount}}) and get seen today: {{payment_link}}",
                'variables' => ['first_name', 'plan_name', 'currency', 'amount', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Payment recovery (SMS)',
                'category' => 'payment',
                'body' => "Hi {{first_name}}, your payment didn't go through — it happens! Your spot is still saved. Finish in one tap: {{payment_link}}",
                'variables' => ['first_name', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Win-back (SMS)',
                'category' => 'win_back',
                'body' => "{{first_name}}, we miss you! While you were away, {{views_hook}} 👀 Don't stay invisible — get back in front of them: {{payment_link}}",
                'variables' => ['first_name', 'views_hook', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Renewal with link (SMS)',
                'category' => 'renewal',
                'body' => "Hey {{first_name}}, {{views_hook}} 👀 Keep the momentum — renew {{plan_name}} now: {{payment_link}}",
                'variables' => ['first_name', 'views_hook', 'plan_name', 'payment_link'],
            ],
        ];

        foreach ($templates as $tpl) {
            Template::updateOrCreate(
                [
                    'title' => $tpl['title'],
                    'platform_id' => null,
                ],
                [
                    'category' => $tpl['category'],
                    'channel' => 'sms',
                    'subject' => null,
                    'body' => $tpl['body'],
                    'variables' => $tpl['variables'],
                    'status' => 'active',
                ]
            );
        }
    }
}
