<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Template;
use Carbon\Carbon;

class TemplateService
{
    public function renderTemplate(Template $template, array $variables): array
    {
        $subject = $template->subject
            ? $this->renderString($template->subject, $variables)
            : ['content' => null, 'missing' => []];

        $body = $this->renderString($template->body, $variables);

        return [
            'subject' => $subject['content'],
            'body' => $body['content'],
            'missing' => array_values(array_unique(array_merge($subject['missing'], $body['missing']))),
            'variables' => $variables,
        ];
    }

    public function renderString(string $template, array $variables): array
    {
        $missing = [];

        $content = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($matches) use ($variables, &$missing) {
            $key = trim($matches[1]);

            if (!array_key_exists($key, $variables) || $variables[$key] === null) {
                $missing[] = $key;
                return $matches[0];
            }

            return (string) $variables[$key];
        }, $template);

        return [
            'content' => $content,
            'missing' => array_values(array_unique($missing)),
        ];
    }

    public function extractVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', $template, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    public function buildClientVariables(Client $client, ?Deal $deal = null, array $extra = []): array
    {
        $expiresAt = $deal?->expires_at;
        if ($expiresAt && !($expiresAt instanceof Carbon)) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        $base = [
            'client_name' => $client->name ?: 'Client',
            'name' => $client->name ?: 'Client',
            'phone' => $client->phone_normalized ?: '',
            'city' => $client->city ?: '',
            'platform_name' => optional($client->platform)->name ?: '',
            'plan_name' => $deal?->product?->name ?: ($deal?->plan_type ?: ''),
            'package' => $deal?->product?->name ?: ($deal?->plan_type ?: ''),
            'expiry_date' => $expiresAt ? $expiresAt->format('Y-m-d') : '',
            'expiry_datetime' => $expiresAt ? $expiresAt->toDateTimeString() : '',
            'days_left' => $expiresAt ? (string) now()->diffInDays($expiresAt, false) : '',
            'days_since_expiry' => $expiresAt ? (string) max(0, now()->diffInDays($expiresAt, false) * -1) : '',
        ];

        foreach ($extra as $key => $value) {
            $base[$key] = $value;
        }

        return $base;
    }
}
