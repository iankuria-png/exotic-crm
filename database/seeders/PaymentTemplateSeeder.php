<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class PaymentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'title' => 'Payment Confirmation — SMS',
                'category' => 'payment',
                'channel' => 'sms',
                'subject' => null,
                'body' => 'Hi {{client_name}}, we have received your payment of {{amount}} for {{plan_name}}. Ref: {{transaction_reference}}. Your profile is now active. Thank you!',
                'variables' => ['client_name', 'amount', 'plan_name', 'transaction_reference'],
            ],
            [
                'title' => 'Payment Confirmation — Email',
                'category' => 'payment',
                'channel' => 'email',
                'subject' => 'Payment received for {{plan_name}}',
                'body' => "Hi {{client_name}},\n\nThank you for your payment of {{amount}} for {{plan_name}}.\nTransaction reference: {{transaction_reference}}\n\nYour profile is now active and visible to visitors.\n\nIf you have any questions, contact our support team.",
                'variables' => ['client_name', 'amount', 'plan_name', 'transaction_reference'],
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
