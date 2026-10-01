<?php

namespace App\Services\DbScanner\Surfaces;

/**
 * Every place phase 1 reads, with its keyset, caps and profile.
 *
 * Order matters: high-risk stores (options, snippet tables) come first so a
 * budget-limited Quick run always reaches them; the rest are rotated so every
 * surface advances before any returns to the front.
 */
class SurfaceRegistry
{
    public const EXCLUDED_POST_TYPES = [
        'revision', 'attachment', 'nav_menu_item', 'oembed_cache', 'od_url_metrics',
        'customize_changeset', 'wp_global_styles', 'acf-field', 'acf-field-group', 'user_request',
    ];

    /** Post types created by our own plugins with no author by design. */
    public const SYSTEM_POST_TYPES = ['engagement_invite', 'campaign', 'advanced_ads', 'telegram-accounts', 'whatsapp-accounts'];

    /** Postmeta keys that carry SEO text, builder layouts or stored code. */
    public const CODE_META_KEYS = [
        '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', 'rank_math_title', 'rank_math_description',
        '_elementor_data', '_elementor_page_settings', '_elementor_custom_code', '_wpcode_header_scripts', '_wpcode_footer_scripts',
        '_wpcode_body_scripts', '_ihaf_header', '_ihaf_footer', '_custom_css', 'custom_css', '_header_code', '_footer_code',
        '_et_pb_custom_css', '_wp_page_template',
    ];

    public const SNIPPET_POST_TYPES = ['wpcode', 'elementor_snippet', 'custom_css', 'code-snippets', 'ihaf_snippet', 'wp_custom_css'];

    /** @var array<string, Surface>|null */
    private ?array $surfaces = null;

    /**
     * @return array<string, Surface>
     */
    public function all(): array
    {
        return $this->surfaces ??= $this->build();
    }

    public function get(string $key): ?Surface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, Surface>
     */
    public function forProfile(string $profile): array
    {
        return array_filter($this->all(), fn (Surface $s) => $s->inProfile($profile));
    }

    /**
     * @return array<string, Surface>
     */
    public function rowSurfaces(string $profile): array
    {
        return array_filter($this->forProfile($profile), fn (Surface $s) => $s->isRows());
    }

    /**
     * @return array<string, Surface>
     */
    public function inventorySurfaces(string $profile): array
    {
        return array_filter($this->forProfile($profile), fn (Surface $s) => ! $s->isRows());
    }

    /**
     * Required physical tables for an inventory surface.
     *
     * @return array<int, string>
     */
    public function inventoryTables(string $key): array
    {
        return match ($key) {
            'options.core', 'options.autoload', 'options.transients_expired' => ['options'],
            'users.privileged' => ['usermeta', 'users'],
            'usermeta.app_passwords' => ['usermeta'],
            'posts.daily_volume', 'posts.revision_counts' => ['posts'],
            'posts.orphan_authors' => ['posts', 'users'],
            'postmeta.slug_aliases', 'postmeta.orphans' => ['postmeta', 'posts'],
            'actionscheduler.summary' => ['actionscheduler_actions'],
            default => [],
        };
    }

    /**
     * @return array<string, Surface>
     */
    private function build(): array
    {
        $all = ['quick', 'standard', 'deep'];
        $standard = ['standard', 'deep'];
        $deep = ['deep'];

        $list = [
            // Inventory — quick high-risk metadata first.
            new Surface('schema.tables', 'inventory', 'Base tables and storage', profiles: $all),
            new Surface('schema.triggers', 'inventory', 'Database triggers', profiles: $all),
            new Surface('schema.events_routines', 'inventory', 'Scheduled events and stored routines', profiles: $all),
            new Surface('options.core', 'inventory', 'Core and security options', table: 'options', profiles: $all),
            new Surface('users.privileged', 'inventory', 'Privileged accounts and capability rows', table: 'usermeta', profiles: $all),
            new Surface('usermeta.app_passwords', 'inventory', 'Administrator application-password metadata', table: 'usermeta', profiles: $all),
            new Surface('options.autoload', 'inventory', 'Autoloaded option totals', table: 'options', profiles: $standard),
            new Surface('posts.daily_volume', 'inventory', 'Published posts per day', table: 'posts', profiles: $standard),
            new Surface('posts.orphan_authors', 'inventory', 'Posts without an author account', table: 'posts', profiles: $standard),
            new Surface('options.transients_expired', 'inventory', 'Expired transients', table: 'options', profiles: $deep),
            new Surface('posts.revision_counts', 'inventory', 'Revisions per post', table: 'posts', profiles: $deep),
            new Surface('postmeta.slug_aliases', 'inventory', 'Shared old profile slugs', table: 'postmeta', profiles: $deep),
            new Surface('postmeta.orphans', 'inventory', 'Postmeta without a post', table: 'postmeta', profiles: $deep),
            new Surface('actionscheduler.summary', 'inventory', 'Action Scheduler backlog', table: 'actionscheduler_actions', profiles: $deep, core: false),

            // Row surfaces — keyset traversal.
            new Surface(
                'options.values', 'rows', 'All options, including autoload, widgets, transients and snippet settings',
                table: 'options', pk: 'option_id', labels: ['option_name', 'autoload'], values: ['option_value'],
                predicates: [
                    ['option_name', 'not_like', '!_transient!_timeout!_%'],
                    ['option_name', 'not_like', '!_site!_transient!_timeout!_%'],
                ],
                profiles: $all, secretLabel: 'option_name', objectType: 'option', weight: 3,
            ),
            new Surface(
                'snippets.code', 'rows', 'Code Snippets plugin table',
                table: 'snippets', pk: 'id', labels: ['name', 'active'], values: ['code'],
                profiles: $all, core: false, objectType: 'snippet', weight: 2,
            ),
            new Surface(
                'posts.content', 'rows', 'Posts, pages, profiles and snippet post types (all statuses except auto-draft)',
                table: 'posts', pk: 'ID', labels: ['post_type', 'post_status'], values: ['post_title', 'post_content', 'post_excerpt'],
                predicates: [
                    ['post_type', 'not_in', self::EXCLUDED_POST_TYPES],
                    ['post_status', '!=', 'auto-draft'],
                ],
                profiles: $standard, objectType: 'post', weight: 2,
            ),
            new Surface(
                'postmeta.code', 'rows', 'SEO, builder and code postmeta',
                table: 'postmeta', pk: 'meta_id', labels: ['meta_key', 'post_id'], values: ['meta_value'],
                predicates: [['meta_key', 'in', self::CODE_META_KEYS]],
                profiles: $standard, secretLabel: 'meta_key', objectType: 'postmeta',
            ),
            new Surface(
                'usermeta.values', 'rows', 'User metadata (protected keys excluded)',
                table: 'usermeta', pk: 'umeta_id', labels: ['meta_key', 'user_id'], values: ['meta_value'],
                predicates: [['meta_key', 'not_in', ['session_tokens', '_application_passwords']]],
                profiles: $standard, secretLabel: 'meta_key', objectType: 'usermeta',
            ),
            new Surface(
                'terms.descriptions', 'rows', 'Term descriptions',
                table: 'term_taxonomy', pk: 'term_taxonomy_id', labels: ['taxonomy'], values: ['description'],
                predicates: [['description', '!=', '']],
                profiles: $standard, objectType: 'term',
            ),
            new Surface(
                'users.names', 'rows', 'User display names and URLs',
                table: 'users', pk: 'ID', labels: ['user_login'], values: ['display_name', 'user_url'],
                profiles: $standard, objectType: 'user',
            ),
            new Surface(
                'posts.revisions', 'rows', 'Post revisions',
                table: 'posts', pk: 'ID', labels: ['post_type', 'post_status', 'post_parent'], values: ['post_content'],
                predicates: [['post_type', '=', 'revision']],
                profiles: $deep, objectType: 'revision',
            ),
            new Surface(
                'postmeta.values', 'rows', 'Remaining postmeta',
                table: 'postmeta', pk: 'meta_id', labels: ['meta_key', 'post_id'], values: ['meta_value'],
                predicates: [
                    ['meta_key', 'not_in', array_merge(self::CODE_META_KEYS, ['_edit_lock', '_edit_last'])],
                    ['meta_key', 'not_like', '!_oembed!_%'],
                ],
                profiles: $deep, secretLabel: 'meta_key', objectType: 'postmeta',
            ),
            new Surface(
                'comments.approved', 'rows', 'Approved comments',
                table: 'comments', pk: 'comment_ID', labels: ['comment_post_ID'], values: ['comment_content', 'comment_author_url'],
                predicates: [['comment_approved', '=', '1']],
                profiles: $deep, core: false, objectType: 'comment',
            ),
        ];

        $out = [];
        foreach ($list as $surface) {
            $out[$surface->key] = $surface;
        }

        return $out;
    }
}
