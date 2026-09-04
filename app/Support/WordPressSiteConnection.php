<?php

namespace App\Support;

use App\Models\PbnSite;
use App\Models\Platform;

final class WordPressSiteConnection
{
    public function __construct(
        public readonly string $siteType,
        public readonly int $siteId,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly ?string $wpApiUrl,
        public readonly ?string $wpApiUser,
        public readonly ?string $wpApiPassword,
        public readonly ?string $dbHost,
        public readonly ?string $dbName,
        public readonly ?string $dbUser,
        public readonly ?string $dbPass,
        public readonly ?string $dbPrefix,
        public readonly string $timezone,
        public readonly bool $sharedKeyEnabled = false,
        public readonly bool $writesLegacySelfUploadSecretOption = false,
    ) {
    }

    public static function fromPlatform(Platform $platform): self
    {
        return new self(
            siteType: 'platform',
            siteId: (int) $platform->id,
            label: (string) $platform->name,
            baseUrl: self::resolveBaseUrl($platform->domain, $platform->wp_api_url),
            wpApiUrl: $platform->wp_api_url,
            wpApiUser: $platform->wp_api_user,
            wpApiPassword: $platform->wp_api_password,
            dbHost: $platform->db_host,
            dbName: $platform->db_name,
            dbUser: $platform->db_user,
            dbPass: $platform->db_pass,
            dbPrefix: $platform->db_prefix,
            timezone: $platform->timezone ?: config('app.timezone', 'UTC'),
            sharedKeyEnabled: (bool) $platform->sync_shared_key_enabled,
            writesLegacySelfUploadSecretOption: $platform->writesLegacySelfUploadSecretOption(),
        );
    }

    public static function fromPbnSite(PbnSite $site): self
    {
        return new self(
            siteType: 'pbn_site',
            siteId: (int) $site->id,
            label: (string) $site->name,
            baseUrl: self::resolveBaseUrl($site->domain, $site->wp_api_url),
            wpApiUrl: $site->wp_api_url,
            wpApiUser: $site->wp_api_user,
            wpApiPassword: $site->wp_api_password,
            dbHost: $site->db_host,
            dbName: $site->db_name,
            dbUser: $site->db_user,
            dbPass: $site->db_pass,
            dbPrefix: $site->db_prefix,
            timezone: $site->timezone ?: config('app.timezone', 'UTC'),
            writesLegacySelfUploadSecretOption: $site->writesLegacySelfUploadSecretOption(),
        );
    }

    public function connectionConfig(): array
    {
        return [
            'driver' => 'mysql',
            'host' => $this->dbHost,
            'port' => 3306,
            'database' => $this->dbName,
            'username' => $this->dbUser,
            'password' => $this->dbPass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $this->dbPrefix ?? '',
        ];
    }

    public function cacheKey(string $suffix): string
    {
        return "wp-sync:{$this->siteType}:{$this->siteId}:{$suffix}";
    }

    private static function resolveBaseUrl(?string $domain, ?string $apiUrl): string
    {
        $fromApi = preg_replace('#/wp-json/.*$#', '', (string) $apiUrl);
        $candidate = trim((string) ($fromApi ?: $domain));
        if ($candidate === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }

        return rtrim($candidate, '/');
    }
}
