<?php

namespace App\Services\DbScanner\Surfaces;

/**
 * Versioned secret-name policy, applied to label columns BEFORE any value is
 * selected. A protected cell is excluded whole — even if it might also hold
 * suspicious settings — and is reported as excluded coverage.
 *
 * Serialized/mixed cells from known secret-bearing plugin settings are listed
 * by exact name because their option name alone does not look secret.
 */
class SecretPolicy
{
    public const VERSION = '2026-10-05.1';

    private const PATTERN = '/((^|[^a-z])pass([^a-z]|$)|password|passwd|passphrase|secret|token|api[_-]?key|apikey|private[_-]?key|auth[_-]?key|salt|smtp|credential|license[_-]?key|nonce_key|logged_in_key|secure_auth|client[_-]?secret|access[_-]?key|signing|webhook[_-]?key|shared[_-]?key|consumer[_-]?(key|secret)|session_tokens|application_passwords|nsl_persistent|id_token|refresh_token)/i';

    /** Known secret-bearing serialized settings (whole cell excluded). */
    private const EXACT = [
        'wp_mail_smtp', 'wp_mail_smtp_debug', 'mailserver_pass', 'mailserver_login', 'post_smtp', 'postman_options',
        'swpsmtp_options', 'easy_wp_smtp', 'smtp_mailer_options', 'fluentmail-settings', 'wpms_options',
        'exotic_crm_sync_settings', 'exotic_crm_sync_shared_key', 'exotic_crm_sync_api_secret', 'exotic_kyc_settings',
        'webpushr_settings', 'support_board_settings', 'sb-settings', 'nextend_social_login_settings', 'nsl_settings',
        'woocommerce_stripe_settings', 'woocommerce_paypal_settings', 'loginizer_security', 'loginizer_2fa',
        'backwpup_cfg_hash', 'backwpup_jobs', 'wordfence_ls_settings',
        'jetpack_private_options', 'updraft_dropbox', 'updraft_s3', 'updraft_googledrive', 'auth_key', 'secure_auth_key',
        'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    ];

    /** Usermeta keys never read on the generic surface. */
    private const USERMETA_PROTECTED = ['session_tokens', '_application_passwords', 'wfls-2fa-secret', 'googleauthenticator_secret', 'two_factor_totp_key'];

    public function isProtected(?string $name): bool
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return false;
        }

        if (in_array($name, self::EXACT, true) || in_array($name, self::USERMETA_PROTECTED, true)) {
            return true;
        }

        // Transient caches of remote API responses can echo tokens.
        if (preg_match('/^_?(site_)?transient_.*(oauth|token|secret)/', $name)) {
            return true;
        }

        return (bool) preg_match(self::PATTERN, $name);
    }
}
