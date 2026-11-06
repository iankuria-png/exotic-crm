<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Collections\CollectionEnrollmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class EnrollCollectionDevice extends Command
{
    protected $signature = 'collections:enroll {private_config} {--actor=} {--apply}';

    protected $description = 'Preview a private verified device/SIM/account mapping; explicit apply creates shadow capture only';

    public function handle(CollectionEnrollmentService $enrollment): int
    {
        $path = realpath($this->argument('private_config'));
        if (! $path || ! is_readable($path)) {
            $this->error('Readable private JSON configuration required.');

            return self::FAILURE;
        }
        if ((fileperms($path) & 0077) !== 0) {
            $this->error('Configuration contains a device key. Restrict file permissions to its owner.');

            return self::FAILURE;
        }
        $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        Validator::make($input, [
            'account_label' => 'required|string|max:128', 'country' => 'required|in:TZ,GH', 'provider_key' => 'required|string|max:64',
            'account_kind' => 'required|in:wallet,merchant,till,paybill,bank', 'account_identifier' => 'required|string|max:128', 'currency' => 'required|string|size:3',
            'timezone' => 'required|timezone', 'ownership' => 'required|in:company,collector', 'ownership_evidence_reference' => 'required|string|max:191',
            'device_label' => 'required|string|max:128', 'external_device_id' => 'required|string|max:128', 'signing_secret' => 'required|string|min:32|max:512',
            'inbox_cursor' => 'required|string|max:4096', 'sim_number' => 'required|integer|min:0|max:16', 'recipient' => 'required|string|max:128',
            'iccid' => 'required_unless:sim_identity_mode,android_subscription_v1|nullable|string|max:128',
            'sim_identity_mode' => 'nullable|in:iccid_v1,android_subscription_v1',
            'android_subscription_id' => 'required_if:sim_identity_mode,android_subscription_v1|nullable|integer|min:1|max:2147483647',
            'filter_version' => 'required_if:sim_identity_mode,android_subscription_v1|nullable|in:exotic-tz-v1',
            'carrier_name' => 'required|string|max:128', 'apk_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/', 'apk_version' => 'required|string|max:64',
            'platform_bindings' => 'required|array|min:1|max:10', 'platform_bindings.*.platform_id' => 'required|integer|exists:platforms,id',
            'platform_bindings.*.destination_snapshot' => 'required|array', 'platform_bindings.*.manual_method_id' => 'nullable|integer',
        ])->validate();
        $actor = User::findOrFail((int) $this->option('actor'));
        app(\App\Services\MarketAuthorizationService::class)->ensureManager($actor);
        foreach ($input['platform_bindings'] as $binding) {
            app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, (int) $binding['platform_id']);
        }
        $this->line('One device; '.count($input['platform_bindings']).' market binding(s); mode shadow; no payments or entitlements.');
        if (! $this->option('apply')) {
            $this->info('Preview only. No records changed.');

            return self::SUCCESS;
        }
        $result = $enrollment->enroll($actor, $input);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
