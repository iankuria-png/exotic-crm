<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadConversionService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    /**
     * Check if a strong duplicate client already exists for this lead's contact data.
     *
     * @return array{match: bool, client: ?Client, matched_by: ?string}
     */
    public function checkDuplicate(Lead $lead, int $platformId, ?string $phone, ?string $email): array
    {
        if ($phone) {
            $clients = Client::query()
                ->where('platform_id', $platformId)
                ->where('phone_normalized', $phone)
                ->limit(2)
                ->get();

            if ($clients->count() === 1) {
                return ['match' => true, 'client' => $clients->first(), 'matched_by' => 'phone'];
            }
        }

        if ($email) {
            $client = Client::query()
                ->where('platform_id', $platformId)
                ->where('email', strtolower(trim($email)))
                ->first();

            if ($client) {
                return ['match' => true, 'client' => $client, 'matched_by' => 'email'];
            }
        }

        return ['match' => false, 'client' => null, 'matched_by' => null];
    }

    /**
     * Convert a lead into a new client via WordPress provisioning.
     *
     * @return array{client: Client, lead: Lead, provisioning: array}
     */
    public function convert(Request $request, Lead $lead, array $payload): array
    {
        $platformId = (int) $lead->platform_id;
        $platform = Platform::query()->findOrFail($platformId);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this lead market.'
        );

        if ($lead->status === 'converted' && $lead->converted_client_id) {
            throw new \InvalidArgumentException('This lead has already been converted.');
        }

        if (!$this->platformHasWpDatabaseCredentials($platform)) {
            throw new \InvalidArgumentException('WordPress database credentials are incomplete for this market.');
        }

        $phonePrefix = (string) ($platform->phone_prefix ?: '254');
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }

        $phone = PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix);
        $email = !empty($payload['email']) ? strtolower(trim((string) $payload['email'])) : null;

        $profileStatus = strtolower(trim((string) ($payload['profile_status'] ?? 'private')));
        if (!in_array($profileStatus, ['publish', 'private', 'draft', 'pending'], true)) {
            $profileStatus = 'private';
        }

        // Duplicate check
        $dupeCheck = $this->checkDuplicate($lead, $platformId, $phone, $email);
        if ($dupeCheck['match']) {
            $existingClient = $dupeCheck['client'];
            return [
                'duplicate' => true,
                'matched_by' => $dupeCheck['matched_by'],
                'existing_client' => [
                    'id' => $existingClient->id,
                    'name' => $existingClient->name,
                    'phone_normalized' => $existingClient->phone_normalized,
                    'email' => $existingClient->email,
                    'profile_status' => $existingClient->profile_status,
                ],
            ];
        }

        // Provision WP profile
        $provisioningResult = (new WpDirectProvisioningService($platform))->provisionEscort([
            'name' => $name,
            'email' => $email ?? '',
            'phone' => $phone ?? '',
            'city' => !empty($payload['city']) ? trim((string) $payload['city']) : '',
            'post_status' => $profileStatus,
        ]);

        $wpPostId = (int) ($provisioningResult['wp_post_id'] ?? 0);
        $wpUserId = (int) ($provisioningResult['wp_user_id'] ?? 0);
        if ($wpPostId <= 0 || $wpUserId <= 0) {
            throw new \RuntimeException('WordPress provisioning did not return valid profile IDs.');
        }

        $assignedTo = !empty($payload['assigned_to'])
            ? (int) $payload['assigned_to']
            : $lead->assigned_to;

        // Wrap CRM writes in a transaction
        $client = DB::transaction(function () use (
            $request, $lead, $platform, $platformId,
            $name, $phone, $email, $profileStatus,
            $assignedTo, $wpPostId, $wpUserId,
            $provisioningResult, $payload
        ) {
            $client = Client::updateOrCreate(
                [
                    'platform_id' => $platformId,
                    'wp_post_id' => $wpPostId,
                ],
                [
                    'wp_user_id' => $wpUserId,
                    'client_type' => 'escort',
                    'name' => $name,
                    'phone_normalized' => $phone,
                    'email' => $email,
                    'city' => !empty($payload['city']) ? trim((string) $payload['city']) : null,
                    'profile_status' => (string) ($provisioningResult['wp_post_status'] ?? $profileStatus),
                    'assigned_to' => $assignedTo,
                    'premium' => false,
                    'featured' => false,
                    'verified' => false,
                    'last_synced_at' => now(),
                ]
            );

            // Try to sync from WP to fill in additional fields
            $syncStatus = 'skipped';
            try {
                $syncedClient = (new ClientSyncService($platform))->syncOne($wpPostId);
                if ($assignedTo && (int) ($syncedClient->assigned_to ?? 0) !== $assignedTo) {
                    $syncedClient->assigned_to = $assignedTo;
                    $syncedClient->save();
                }
                $client = $syncedClient;
                $syncStatus = 'success';
            } catch (\Throwable $e) {
                $syncStatus = 'failed';
                Log::warning('Lead conversion: syncOne failed after provisioning', [
                    'platform_id' => $platformId,
                    'wp_post_id' => $wpPostId,
                    'error' => $e->getMessage(),
                ]);
            }

            $beforeState = [
                'status' => $lead->status,
                'converted_client_id' => $lead->converted_client_id,
            ];

            $lead->update([
                'status' => 'converted',
                'converted_client_id' => (int) $client->id,
            ]);

            // Timeline: lead side
            TimelineEvent::create([
                'platform_id' => $platformId,
                'entity_type' => 'lead',
                'entity_id' => $lead->id,
                'event_type' => 'lead_converted_to_client',
                'actor_id' => $request->user()->id,
                'content' => [
                    'client_id' => (int) $client->id,
                    'method' => 'wp_provision',
                    'wp_post_id' => $wpPostId,
                ],
                'created_at' => now(),
            ]);

            // Timeline: client side
            TimelineEvent::create([
                'platform_id' => $platformId,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'client_created_from_lead',
                'actor_id' => $request->user()->id,
                'content' => [
                    'lead_id' => (int) $lead->id,
                    'source' => $lead->source,
                    'sync_status' => $syncStatus,
                ],
                'created_at' => now(),
            ]);

            // Audit
            $this->auditService->fromRequest(
                $request,
                $platformId,
                CrmAuditAction::LEAD_CONVERT_TO_CLIENT,
                'lead',
                (int) $lead->id,
                $beforeState,
                [
                    'status' => $lead->status,
                    'converted_client_id' => $lead->converted_client_id,
                    'client_name' => $client->name,
                    'wp_post_id' => $wpPostId,
                    'wp_user_id' => $wpUserId,
                    'sync_status' => $syncStatus,
                ],
                (string) ($payload['reason'] ?? 'Lead converted to client')
            );

            return $client;
        });

        $client->load(['platform', 'assignedAgent']);

        return [
            'duplicate' => false,
            'client' => $client,
            'lead' => $lead->fresh(['platform', 'assignedAgent', 'convertedClient']),
            'provisioning' => $provisioningResult,
        ];
    }

    private function platformHasWpDatabaseCredentials(Platform $platform): bool
    {
        return !empty($platform->db_host)
            && !empty($platform->db_name)
            && !empty($platform->db_user);
    }
}
