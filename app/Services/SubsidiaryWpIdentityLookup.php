<?php

namespace App\Services;

use App\Models\Platform;
use App\Support\PhoneNormalizer;
use App\Support\WordPressSiteConnection;
use Illuminate\Support\Facades\DB;

/** Complete bounded authoritative lookup; an incomplete read can never authorize creation. */
class SubsidiaryWpIdentityLookup
{
    public function connection(Platform $target): \Illuminate\Database\Connection
    {
        $site = WordPressSiteConnection::fromPlatform($target);
        $name = 'subsidiary_lookup_'.$target->id;
        DynamicDatabaseService::switchConnection($name, $site->connectionConfig());

        return DB::connection($name);
    }

    public function find(Platform $target, array $seed): ?array
    {
        $db = $this->connection($target);
        $deadline = microtime(true) + config('subsidiary_trials.lookup_seconds', 10);
        $limit = config('subsidiary_trials.lookup_limit', 50000);
        $phone = $seed['phone_normalized'];
        abort_unless($phone, 409, 'Select an existing profile when the source phone is missing.');
        $helper = app(SubsidiaryActivationPreviewService::class);
        $email = $helper->email($seed['email'] ?? null);
        // Query caps are connection-local; no application setting is changed.
        $version = (string) $db->selectOne('SELECT VERSION() AS version')->version;
        $db->statement(str_contains($version, 'MariaDB') ? 'SET SESSION max_statement_time=10' : 'SET SESSION MAX_EXECUTION_TIME=10000');
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->statement('SET TRANSACTION READ ONLY');

        return $db->transaction(function () use ($db, $target, $deadline, $limit, $phone, $email, $helper) {
            $siteurl = (string) $db->table('options')->where('option_name', 'siteurl')->value('option_value');
            // CRM API settings may use the bare host while WordPress stores its www alias.
            // Keep the market boundary: no other subdomain or hostname is interchangeable.
            $siteHost = preg_replace('/^www\./', '', strtolower((string) parse_url($siteurl, PHP_URL_HOST)));
            $apiHost = preg_replace('/^www\./', '', strtolower((string) parse_url($target->wp_api_url, PHP_URL_HOST)));
            abort_unless($siteHost !== '' && $apiHost !== '' && $siteHost === $apiHost, 409, 'The target database belongs to a different site.');
            $type = trim((string) $db->table('options')->where('option_name', 'taxonomy_profile_url')->value('option_value')) ?: 'escort';
            abort_unless(preg_match('/^[a-zA-Z0-9_-]+$/D', $type), 409, 'Profile type is unavailable.');
            $last = 0;
            $seen = 0;
            $matches = [];
            do {
                abort_if(microtime(true) > $deadline, 409, 'Profile lookup timed out. Creation is held for review.');
                $rows = $db->table('posts')->whereIn('post_type', array_unique([$type, 'escort']))->where('ID', '>', $last)->orderBy('ID')->limit(500)->get(['ID', 'post_author', 'post_status']);
                $seen += $rows->count();
                abort_if($seen > $limit, 409, 'Profile lookup limit reached. Creation is held for review.');
                $phones = $db->table('postmeta')->whereIn('post_id', $rows->pluck('ID'))->where('meta_key', 'phone')->get()->groupBy('post_id');
                $emails = $db->table('users')->whereIn('ID', $rows->pluck('post_author'))->pluck('user_email', 'ID');
                foreach ($rows as $row) {
                    $last = (int) $row->ID;
                    $numbers = collect($phones[$row->ID] ?? [])->map(fn ($m) => PhoneNormalizer::normalize($m->meta_value, $target->phone_prefix));
                    $targetEmail = $helper->email($emails[$row->post_author] ?? null);
                    if ($numbers->contains($phone) || ($email && $targetEmail === $email)) {
                        abort_unless($numbers->contains($phone) && (! $email || ! $targetEmail || $email === $targetEmail) && in_array($row->post_status, ['publish', 'private', 'draft', 'pending']), 409, 'Conflicting site profile found. Review its identity before creation.');
                        $matches[] = ['wp_post_id' => (int) $row->ID, 'wp_user_id' => (int) $row->post_author];
                    }
                }
            } while ($rows->count() === 500);
            abort_if(count($matches) > 1, 409, 'Multiple matching site profiles found. Select the correct profile.');

            return $matches[0] ?? null;
        });
    }

    public function provisionRecord(Platform $target, string $requestId): ?array
    {
        $row = $this->connection($target)->table('exotic_crm_provisions')->where('request_id', $requestId)->first();

        return $row ? (array) $row : null;
    }
}
