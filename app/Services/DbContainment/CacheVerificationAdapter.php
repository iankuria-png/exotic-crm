<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentMarket;
use App\Models\DbContainmentOperation;
use App\Models\Platform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CacheVerificationAdapter
{
    public function call(Platform $platform, DbContainmentMarket $market, string $operation, string $digest, array $targets, array $state = [], bool $invalidate = false): array
    {
        $cfg = $market->configuration ?? [];
        $secret = $cfg['cache_secret'] ?? '';
        if (strlen($secret) < 32 || empty($cfg['cache_runtime_trusted'])) {
            throw new ContainmentException('trusted_cache_adapter_not_provisioned');
        }
        if (($targets['session_check'] ?? false) && (empty($cfg['session_canary_verified_at']) || strtotime($cfg['session_canary_verified_at']) < time() - 86400)) {
            throw new ContainmentException('recent_synthetic_session_canary_required');
        }
        $version = (string) ($cfg['cache_key_version'] ?? '1');
        $expected = [];
        foreach ($state['users'] ?? [] as $user) {
            $id = (int) $user['ID'];
            $tags = [];
            foreach (['session_tokens', '_application_passwords', $platform->db_prefix.'user_level'] as $key) {
                $values = [];
                foreach ($state['usermeta'] ?? [] as $row) {
                    if ((int) $row['user_id'] === $id && $row['meta_key'] === $key) {
                        $values[] = hash_hmac('sha256', $row['meta_value'], $secret);
                    }
                }
                // Restore snapshots include only affected metadata keys.
                if (array_key_exists('user_pass', $user) || $values || in_array($key, $targets['meta_keys'] ?? [], true)) {
                    $tags[$key] = $values;
                }
            }
            $item = ['id' => $id, 'meta_tags' => $tags];
            foreach (['user_pass' => 'password_tag', 'user_activation_key' => 'activation_tag'] as $field => $tag) {
                if (array_key_exists($field, $user)) {
                    $item[$tag] = hash_hmac('sha256', $user[$field], $secret);
                }
            }
            $expected[] = $item;
        }
        $optionTags = [];
        foreach ($state['options'] ?? [] as $option) {
            if (in_array($option['option_name'], $targets['option_names'] ?? [], true)) {
                $optionTags[$option['option_name']] = hash_hmac('sha256', $option['option_value'], $secret);
            }
        }
        $payload = ['platform_id' => (int) $platform->id, 'operation_id' => $operation, 'approval_digest' => $digest, 'key_version' => $version, 'invalidate' => $invalidate, 'targets' => $targets, 'expected_users' => $expected, 'expected_options' => $optionTags];
        if (isset($cfg['synthetic_session_canary']) && in_array((int) $cfg['synthetic_session_canary']['user_id'], $targets['user_ids'] ?? [], true)) {
            $payload['synthetic_canary'] = ['user_id' => (int) $cfg['synthetic_session_canary']['user_id'], 'token' => $cfg['synthetic_session_canary']['token'], 'expected_valid' => ! $invalidate];
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        $uuid = (string) Str::uuid();
        $path = '/exotic-crm-sync/v1/containment/cache';
        $op = DbContainmentOperation::query()->find($operation);
        $phase = hash('sha256', $body);
        $requests = $op?->cache_requests ?? [];
        if (! empty($requests[$phase]['complete'])) {
            // A known response can be checked again, but cache mutations must not repeat.
            $payload['invalidate'] = false;
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $phase = hash('sha256', $phase.'|verification');
            if (! empty($requests[$phase]['complete'])) {
                unset($requests[$phase]);
            }
        }
        if (isset($requests[$phase])) {
            $body = $requests[$phase]['body'];
            $time = $requests[$phase]['time'];
            $uuid = $requests[$phase]['uuid'];
        } elseif ($op) {
            $requests[$phase] = ['body' => $body, 'time' => $time, 'uuid' => $uuid];
            $op->update(['cache_requests' => $requests]);
        }
        $signature = hash_hmac('sha256', implode("\n", ['POST', $path, hash('sha256', $body), (string) $platform->id, $time, $uuid, $operation, $digest, $version]), $secret);
        $base = rtrim($platform->wp_api_url ?: 'https://'.$platform->domain.'/wp-json', '/');
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])->withHeaders(['X-Containment-Time' => $time, 'X-Containment-Request' => $uuid, 'X-Containment-Signature' => $signature])->withBody($body, 'application/json')->post($base.'/exotic-crm-sync/v1/containment/cache');
            $result = $response->json();
            if (! $response->successful() || ! is_array($result) || ($result['operation_id'] ?? '') !== $operation || ($result['key_version'] ?? '') !== $version || ($result['manager'] ?? '') !== 'WP_User_Meta_Session_Tokens') {
                throw new ContainmentException('cache_adapter_unverified');
            }
            if ($op) {
                $requests[$phase]['complete'] = true;
                $op->update(['cache_requests' => $requests]);
            }
            if ($invalidate && empty($result['verified'])) {
                throw new ContainmentException('cache_verification_pending');
            }
            if ($invalidate && ($targets['content_check'] ?? false) && empty($result['content_verified'])) {
                throw new ContainmentException('public_cache_verification_pending');
            }

            return $result;
        } catch (ContainmentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ContainmentException('cache_adapter_unreachable');
        }
    }
}
