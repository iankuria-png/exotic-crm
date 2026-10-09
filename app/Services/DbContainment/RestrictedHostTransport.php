<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentMarket;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class RestrictedHostTransport
{
    private function canonical(array $value): string
    {
        $sort = function ($data) use (&$sort) {
            if (! is_array($data)) {
                return $data;
            }
            if (! array_is_list($data)) {
                ksort($data);
            }

            return array_map($sort, $data);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function call(DbContainmentMarket $market, array $request): array
    {
        $cfg = $market->configuration['ssh'] ?? [];
        foreach (['host', 'user', 'key_path', 'known_hosts', 'signing_secret', 'root_id', 'site_identity'] as $field) {
            if (empty($cfg[$field])) {
                throw new ContainmentException('restricted_ssh_not_provisioned');
            }
        }
        if (! preg_match('/^[a-zA-Z0-9.-]+$/D', $cfg['host']) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $cfg['user']) || ! is_file($cfg['key_path']) || ! is_file($cfg['known_hosts'])) {
            throw new ContainmentException('invalid_pinned_ssh_configuration');
        }
        $request['root_id'] = $cfg['root_id'];
        $request['site_identity'] = $cfg['site_identity'];
        $time = time();
        $uuid = (string) Str::uuid();
        $sig = hash_hmac('sha256', $time."\n".$uuid."\n".$this->canonical($request), $cfg['signing_secret']);
        $body = json_encode(['request' => $request, 'timestamp' => $time, 'request_id' => $uuid, 'signature' => $sig], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $process = new Process(['ssh', '-F', '/dev/null', '-T', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile='.$cfg['known_hosts'], '-o', 'ConnectTimeout=5', '-o', 'ClearAllForwardings=yes', '-i', $cfg['key_path'], '-p', (string) ($cfg['port'] ?? 22), $cfg['user'].'@'.$cfg['host'], 'containment-v1']);
        $process->setInput($body);
        $process->setTimeout(60);
        try {
            $process->run();
            $raw = $process->getOutput();
            if (! $process->isSuccessful() || strlen($raw) > 262144) {
                throw new ContainmentException('host_transport_failed');
            }
            $result = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! ($result['ok'] ?? false)) {
                throw new ContainmentException('host_'.preg_replace('/[^a-z0-9_]/', '', $result['reason'] ?? 'refused'));
            }

            return $result;
        } catch (ContainmentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ContainmentException('host_transport_failed');
        }
    }
}
