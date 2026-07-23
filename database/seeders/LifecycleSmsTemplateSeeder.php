<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Warm, human, link-bearing lifecycle SMS copy (global defaults — every market
 * can override with its own template from Settings → Templates).
 *
 * Copy rules for this set:
 *  - Every message carries a tappable {{payment_link}}. NO "reply to renew /
 *    activate / reactivate" — that's misleading now that the link does the work.
 *  - Lead with the client's own win ({{views_hook}} always has a graceful
 *    non-numeric fallback, never "0 views").
 *  - First name, one clear CTA, one link. No "Dear valued customer" filler.
 *
 * These templates all contain {{payment_link}}, so they only resolve for markets
 * with a tokenized PSP — which is exactly where the lifecycle flows run (the
 * onboarding/recovery/reactivation flows are PSP-gated) and where an admin opts a
 * market into renewal links. They never become the generic manual-reminder
 * fallback (RenewalService::resolveDefaultRenewalTemplate excludes link copy).
 */
class LifecycleSmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // ---- Onboarding (category new_signup) ----
            [
                'title' => 'Lifecycle — Welcome & activate (SMS)',
                'category' => 'new_signup',
                'body' => "Hi {{first_name}}! Your profile is ready — clients are searching right now. Activate {{plan_name}} ({{currency}} {{amount}}) and get seen today 👉 {{payment_link}}",
                'variables' => ['first_name', 'plan_name', 'currency', 'amount', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Welcome nudge (SMS)',
                'category' => 'new_signup',
                'body' => "Welcome {{first_name}} 🎉 One tap and you're live — activate {{plan_name}} and start getting views today 👉 {{payment_link}}",
                'variables' => ['first_name', 'plan_name', 'payment_link'],
            ],

            // ---- Failed-payment recovery (category payment) ----
            [
                'title' => 'Lifecycle — Payment recovery (SMS)',
                'category' => 'payment',
                'body' => "Hi {{first_name}}, your payment didn't go through — no stress, it happens. Your spot is saved. Finish in one tap 👉 {{payment_link}}",
                'variables' => ['first_name', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Payment recovery nudge (SMS)',
                'category' => 'payment',
                'body' => "{{first_name}}, almost there! Your payment didn't complete. Tap to try again and go live 👉 {{payment_link}}",
                'variables' => ['first_name', 'payment_link'],
            ],

            // ---- Reactivation / win-back (category win_back) ----
            [
                'title' => 'Lifecycle — Win-back (SMS)',
                'category' => 'win_back',
                'body' => "{{first_name}}, we miss you! While you were away, {{views_hook}} 👀 Don't stay invisible — tap to get back in front of them 👉 {{payment_link}}",
                'variables' => ['first_name', 'views_hook', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Win-back reminder (SMS)',
                'category' => 'win_back',
                'body' => "Hi {{first_name}}, your profile's been offline for a while and clients are still searching. Come back and get seen 👉 {{payment_link}}",
                'variables' => ['first_name', 'payment_link'],
            ],

            // ---- Renewal WITH link (category renewal) ----
            // Assign these to a PSP market's renewal campaign (Campaigns → cadence
            // editor) so its renewal SMS carry the tappable link.
            [
                'title' => 'Lifecycle — Renewal with link (SMS)',
                'category' => 'renewal',
                'body' => "Hey {{first_name}}, {{views_hook}} 👀 Keep the momentum going — renew {{plan_name}} in one tap 👉 {{payment_link}}",
                'variables' => ['first_name', 'views_hook', 'plan_name', 'payment_link'],
            ],
            [
                'title' => 'Lifecycle — Renewal expiring with link (SMS)',
                'category' => 'renewal',
                'body' => "{{first_name}}, your {{plan_name}} expires {{expiry_date}}. Renew now and stay visible — tap here 👉 {{payment_link}}",
                'variables' => ['first_name', 'plan_name', 'expiry_date', 'payment_link'],
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
