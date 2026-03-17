<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class CredentialTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'title' => 'Credential Setup Link — Email',
                'category' => 'credential_setup_link',
                'channel' => 'email',
                'subject' => 'Set up your Exotic profile access',
                'body' => "Hi {clientName},\n\nYour profile onboarding is complete.\nUse the links below to set your password and access your profile.\nPassword setup: {setupUrl}\nLogin URL: {loginUrl}\nProfile URL: {profileUrl}\nSupport chat: {supportChatUrl}\n\nIf you did not request this, contact support immediately.",
                'variables' => ['clientName', 'setupUrl', 'loginUrl', 'profileUrl', 'supportChatUrl'],
            ],
            [
                'title' => 'Credential Setup Link — SMS',
                'category' => 'credential_setup_link',
                'channel' => 'sms',
                'subject' => null,
                'body' => "Hi {clientName},\nYour profile is ready. Set your password and sign in:\nSet password: {setupUrl}\nLogin: {loginUrl}\nProfile: {profileUrl}\nSupport chat: {supportChatUrl}",
                'variables' => ['clientName', 'setupUrl', 'loginUrl', 'profileUrl', 'supportChatUrl'],
            ],
            [
                'title' => 'Credential Temp Password — Email',
                'category' => 'credential_temp_password',
                'channel' => 'email',
                'subject' => 'Your Exotic profile temporary credentials',
                'body' => "Hi {clientName},\n\nYour profile onboarding is complete and your login credentials are ready.\nUsername: {wpUsername}\nTemporary password: {temporaryPassword}\nLogin URL: {loginUrl}\nProfile URL: {profileUrl}\nSupport chat: {supportChatUrl}\n\nFor security, please sign in and change this password immediately.",
                'variables' => ['clientName', 'wpUsername', 'temporaryPassword', 'loginUrl', 'profileUrl', 'supportChatUrl'],
            ],
            [
                'title' => 'Credential Temp Password — SMS',
                'category' => 'credential_temp_password',
                'channel' => 'sms',
                'subject' => null,
                'body' => "Hi {clientName},\nYour CRM onboarding is complete.\nUsername: {wpUsername}\nTemporary password: {temporaryPassword}\nLogin: {loginUrl}\nSupport chat: {supportChatUrl}\nPlease change your password after login.",
                'variables' => ['clientName', 'wpUsername', 'temporaryPassword', 'loginUrl', 'supportChatUrl'],
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
