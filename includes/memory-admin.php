<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Memory;

use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin page for browsing, creating, editing, and deleting memories stored in
 * the `novamira_memory` custom post type. Registered as a submenu under the
 * shared `novamira-connect` menu.
 *
 * Relies on constants and helpers from includes/abilities/memory/bootstrap.php
 * which is loaded together with this file when memory is enabled.
 */

const MEMORY_ENABLED_OPTION = 'novamira_memory_enabled';

const LEGACY_PRO_MEMORY_ENABLED_OPTION = 'nvp_memory_enabled';

const OPTION_MISSING = '__novamira_option_missing__';

function memory_page_slug(): string
{
    return 'novamira-memory';
}

function memory_option_name(): string
{
    return MEMORY_ENABLED_OPTION;
}

function memory_legacy_option_name(): string
{
    return LEGACY_PRO_MEMORY_ENABLED_OPTION;
}

function memory_read_option_with_legacy(string $option_name, string $legacy_option_name, mixed $default): mixed
{
    /** @var mixed $value */
    $value = get_option($option_name, default_value: OPTION_MISSING);
    if ($value !== OPTION_MISSING) {
        return $value;
    }

    /** @var mixed $legacy_value */
    $legacy_value = get_option($legacy_option_name, default_value: OPTION_MISSING);
    if ($legacy_value !== OPTION_MISSING) {
        return $legacy_value;
    }

    return $default;
}

function memory_update_enabled_value(string $value): void
{
    update_option(memory_option_name(), $value);
    update_option(memory_legacy_option_name(), $value);
}

function legacy_pro_owns_memory(): bool
{
    return (
        function_exists('\\Novamira\\Pro\\memory_fetch_all') && function_exists('\\Novamira\\Pro\\memory_is_enabled')
    );
}

/**
 * Whether the memory abilities are exposed to agents. Users toggle this from the
 * memory admin page. The admin page itself remains reachable either way, so the
 * user can turn the system back on after disabling it.
 */
function memory_is_enabled(): bool
{
    return filter_var(
        memory_read_option_with_legacy(
            option_name: memory_option_name(),
            legacy_option_name: memory_legacy_option_name(),
            default: true,
        ),
        FILTER_VALIDATE_BOOLEAN,
    );
}

/** @return list<string> */
function memory_types(): array
{
    return \Novamira\Memory\NOVAMIRA_MEMORY_TYPES;
}

function memory_type_label(string $type): string
{
    return match ($type) {
        'user' => __('User', domain: 'novamira'),
        'feedback' => __('Feedback', domain: 'novamira'),
        'project' => __('Project', domain: 'novamira'),
        'reference' => __('Reference', domain: 'novamira'),
        default => $type,
    };
}

/** @param array<string, scalar> $query */
function memory_page_url(array $query = []): string
{
    return add_query_arg(array_merge(['page' => memory_page_slug()], $query), admin_url('admin.php'));
}

function register_memory_menu(): void
{
    if (!defined('NOVAMIRA_VERSION') || legacy_pro_owns_memory()) {
        return;
    }

    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Novamira Memory', domain: 'novamira'),
        menu_title: __('Memory', domain: 'novamira'),
        capability: \novamira_manage_capability(),
        menu_slug: memory_page_slug(),
        callback: __NAMESPACE__ . '\\render_memory_page',
    );
}

function redirect_legacy_memory_page(): void
{
    if (legacy_pro_owns_memory() || !\novamira_current_user_can_manage()) {
        return;
    }

    $page = $_GET['page'] ?? null;
    if ($page !== 'novamira-pro-memory') {
        return;
    }

    $query = [];
    foreach (['action', 'type'] as $key) {
        $value = $_GET[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $query[$key] = sanitize_key($value);
        }
    }

    $id = $_GET['id'] ?? null;
    if (is_scalar($id) && (int) $id > 0) {
        $query['id'] = (int) $id;
    }

    wp_safe_redirect(memory_page_url($query));
    exit();
}

/** @return list<array{id:int,name:string,description:string,type:string,content:string,updated_at:string}> */
function memory_fetch_all(): array
{
    $args = [
        'post_type' => \Novamira\Memory\NOVAMIRA_MEMORY_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ];

    /** @var list<WP_Post> $posts */
    $posts = get_posts($args);

    $memories = [];
    foreach ($posts as $post) {
        $memories[] = \Novamira\Memory\memory_shape($post);
    }

    return $memories;
}

/** @return array{id:int,name:string,description:string,type:string,content:string,updated_at:string}|null */
function memory_fetch_one(int $id): ?array
{
    /** @var WP_Post|null $post */
    $post = get_post($id);
    if (!$post instanceof WP_Post || $post->post_type !== \Novamira\Memory\NOVAMIRA_MEMORY_POST_TYPE) {
        return null;
    }

    return \Novamira\Memory\memory_shape($post);
}

function memory_post_string(string $key): string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) {
        return '';
    }

    return wp_unslash($raw);
}

/**
 * @return array{
 *     notice: array{type:string,message:string}|null,
 *     view: 'list'|'new'|'edit'|null,
 *     id: int,
 *     form_values: array{name:string,description:string,type:string,content:string}|null
 * }|null
 */
function memory_handle_post(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    check_admin_referer('novamira_memory');

    if (!\novamira_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage memories.', domain: 'novamira'));
    }

    $action = memory_post_string('novamira_memory_action');

    if ($action === 'save') {
        return memory_handle_save();
    }

    if ($action === 'delete') {
        return memory_handle_delete();
    }

    if ($action === 'toggle') {
        return memory_handle_toggle();
    }

    return null;
}

/**
 * @return array{
 *     notice: array{type:string,message:string},
 *     view: 'list',
 *     id: int,
 *     form_values: null
 * }
 */
function memory_handle_toggle(): array
{
    $enable = memory_post_string('memory_enabled') === '1';
    // Store as string to avoid WordPress's update_option short-circuit when the
    // option doesn't yet exist: get_option returns false as the implicit old
    // value, and update_option(name, false) bails out before add_option runs.
    memory_update_enabled_value($enable ? '1' : '0');

    return [
        'notice' => [
            'type' => 'success',
            'message' => $enable
                ? __('Memory abilities enabled for agents.', domain: 'novamira')
                : __('Memory abilities disabled for agents. Existing memories are kept.', domain: 'novamira'),
        ],
        'view' => 'list',
        'id' => 0,
        'form_values' => null,
    ];
}

function memory_post_int(string $key): int
{
    $raw = $_POST[$key] ?? null;

    return is_scalar($raw) ? (int) $raw : 0;
}

/**
 * @param array{name:string,description:string,type:string,content:string} $values
 * @return int|WP_Error
 */
function memory_persist(int $id, array $values): int|WP_Error
{
    $postarr = [
        'post_type' => \Novamira\Memory\NOVAMIRA_MEMORY_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $values['name'],
        'post_excerpt' => $values['description'],
        'post_content' => $values['content'],
    ];

    if ($id <= 0) {
        $result = wp_insert_post($postarr, wp_error: true);
        return memory_finalize_persist($result, $values['type']);
    }

    /** @var WP_Post|null $existing */
    $existing = get_post($id);
    if (!$existing instanceof WP_Post || $existing->post_type !== \Novamira\Memory\NOVAMIRA_MEMORY_POST_TYPE) {
        return new WP_Error('not_found', __('Memory not found.', domain: 'novamira'));
    }
    $postarr['ID'] = $id;
    $result = wp_update_post($postarr, wp_error: true);

    return memory_finalize_persist($result, $values['type']);
}

/**
 * @param int|WP_Error $result
 * @return int|WP_Error
 */
function memory_finalize_persist(int|WP_Error $result, string $type): int|WP_Error
{
    if (is_wp_error($result)) {
        return $result;
    }

    update_post_meta($result, \Novamira\Memory\NOVAMIRA_MEMORY_META_TYPE, $type);

    return $result;
}

/**
 * @return array{
 *     notice: array{type:string,message:string},
 *     view: 'new'|'edit',
 *     id: int,
 *     form_values: array{name:string,description:string,type:string,content:string}|null
 * }
 */
function memory_handle_save(): array
{
    $id = memory_post_int('memory_id');
    $type = memory_post_string('memory_type');
    $values = [
        'name' => sanitize_text_field(memory_post_string('memory_name')),
        'description' => sanitize_text_field(memory_post_string('memory_description')),
        'type' => in_array($type, memory_types(), strict: true) ? $type : 'project',
        'content' => memory_post_string('memory_content'),
    ];

    if (
        $values['name'] === ''
        || $values['description'] === ''
        || $values['content'] === ''
        || !in_array($type, memory_types(), strict: true)
    ) {
        return [
            'notice' => [
                'type' => 'error',
                'message' => __('Please fill in all fields and pick a valid type.', domain: 'novamira'),
            ],
            'view' => $id > 0 ? 'edit' : 'new',
            'id' => $id,
            'form_values' => $values,
        ];
    }

    $result = memory_persist($id, $values);

    if (is_wp_error($result)) {
        return [
            'notice' => ['type' => 'error', 'message' => $result->get_error_message()],
            'view' => $id > 0 ? 'edit' : 'new',
            'id' => $id,
            'form_values' => $values,
        ];
    }

    return [
        'notice' => [
            'type' => 'success',
            'message' => $id > 0
                ? __('Memory updated.', domain: 'novamira')
                : __('Memory created.', domain: 'novamira'),
        ],
        'view' => 'edit',
        'id' => $result,
        'form_values' => null,
    ];
}

/**
 * @return array{
 *     notice: array{type:string,message:string},
 *     view: 'list',
 *     id: int,
 *     form_values: null
 * }
 */
function memory_handle_delete(): array
{
    $id = memory_post_int('memory_id');

    if ($id <= 0) {
        return [
            'notice' => [
                'type' => 'error',
                'message' => __('Invalid memory id.', domain: 'novamira'),
            ],
            'view' => 'list',
            'id' => 0,
            'form_values' => null,
        ];
    }

    /** @var WP_Post|null $post */
    $post = get_post($id);
    if (!$post instanceof WP_Post || $post->post_type !== \Novamira\Memory\NOVAMIRA_MEMORY_POST_TYPE) {
        return [
            'notice' => [
                'type' => 'error',
                'message' => __('Memory not found.', domain: 'novamira'),
            ],
            'view' => 'list',
            'id' => 0,
            'form_values' => null,
        ];
    }

    $result = wp_delete_post($id, force_delete: true);

    if ($result === false || $result === null) {
        return [
            'notice' => [
                'type' => 'error',
                'message' => __('Failed to delete memory.', domain: 'novamira'),
            ],
            'view' => 'list',
            'id' => 0,
            'form_values' => null,
        ];
    }

    return [
        'notice' => [
            'type' => 'success',
            'message' => __('Memory deleted.', domain: 'novamira'),
        ],
        'view' => 'list',
        'id' => 0,
        'form_values' => null,
    ];
}

function render_memory_page(): void
{
    if (!\novamira_current_user_can_manage()) {
        return;
    }

    $context = memory_handle_post();
    $notice = $context['notice'] ?? null;
    $forced_view = $context['view'] ?? null;
    $forced_id = $context['id'] ?? 0;
    $form_values = $context['form_values'] ?? null;

    $view = $forced_view;
    $view_id = $forced_id;
    if ($view === null) {
        $url_action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        $view = match ($url_action) {
            'new' => 'new',
            'edit' => 'edit',
            default => 'list',
        };
        $view_id = (int) ($_GET['id'] ?? 0);
    }

    if (function_exists('novamira_render_admin_header')) {
        novamira_render_admin_header();
    }

    if ($view === 'new') {
        render_memory_edit_view(memory: null, form_values: $form_values, notice: $notice);
        return;
    }

    if ($view === 'edit') {
        $memory = memory_fetch_one($view_id);
        if ($memory === null) {
            $notice ??= [
                'type' => 'error',
                'message' => __('Memory not found.', domain: 'novamira'),
            ];
            render_memory_list_view($notice);
            return;
        }
        render_memory_edit_view(memory: $memory, form_values: $form_values, notice: $notice);
        return;
    }

    render_memory_list_view($notice);
}

/** @param array{type:string,message:string}|null $notice */
function render_memory_list_view(?array $notice): void
{
    $type_filter = null;
    $requested_type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
    if (in_array($requested_type, memory_types(), strict: true)) {
        $type_filter = $requested_type;
    }

    $all_memories = memory_fetch_all();

    $counts = ['__total' => count($all_memories)];
    foreach (memory_types() as $t) {
        $counts[$t] = 0;
    }
    foreach ($all_memories as $memory) {
        if (!array_key_exists($memory['type'], $counts)) {
            continue;
        }
        ++$counts[$memory['type']];
    }

    $memories = $type_filter === null
        ? $all_memories
        : array_values(array_filter($all_memories, static fn(array $m): bool => $m['type'] === $type_filter));

    $new_url = memory_page_url(['action' => 'new']);
    $all_url = memory_page_url();

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Memory', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end">

        <p class="description" style="margin-top:8px;max-width:800px;"><?php esc_html_e(
            'Persistent, database-backed memory shared with agents across conversations. Store user profile details, feedback rules, project context, and external references here.',
            domain: 'novamira',
        ); ?></p>

        <?php render_memory_notice($notice); ?>

        <style>
            .novamira-section-title { margin-top:24px; margin-bottom:4px; font-size:16px; }
            .novamira-memory-status { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; background:#fff; border:1px solid #dcdcde; border-radius:12px; padding:16px 20px; margin:12px 0; max-width:1100px; }
            .novamira-memory-status .status-text { display:flex; align-items:center; gap:10px; }
            .novamira-memory-status .status-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; font-weight:600; font-size:12px; }
            .novamira-memory-status .status-pill.is-on { background:#edf7ed; color:#0a5c1b; }
            .novamira-memory-status .status-pill.is-off { background:#fcf0f1; color:#8a2424; }
            .novamira-memory-status .status-hint { color:#50575e; margin:0; }
            .novamira-memory-heading { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
            .novamira-memory-filters { margin:12px 0 16px; }
            .novamira-memory-filters a { margin-right:8px; text-decoration:none; }
            .novamira-memory-filters a.is-active { font-weight:600; }
            .novamira-memory-table td { vertical-align:top; }
            .novamira-memory-type { display:inline-block; padding:2px 10px; border-radius:999px; background:#f0f0f1; font-size:12px; font-weight:500; }
            .novamira-memory-type.is-user { background:#e0f2fe; color:#075985; }
            .novamira-memory-type.is-feedback { background:#fef3c7; color:#92400e; }
            .novamira-memory-type.is-project { background:#ede9fe; color:#5b21b6; }
            .novamira-memory-type.is-reference { background:#dcfce7; color:#166534; }
            .novamira-memory-description { color:#50575e; margin-top:2px; }
            .novamira-memory-row-actions { white-space:nowrap; }
            .novamira-memory-row-actions .delete-form { display:inline; }
        </style>

        <div class="novamira-memory-heading">
            <h2 class="novamira-section-title"><?php esc_html_e('Memory', domain: 'novamira'); ?></h2>
            <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php esc_html_e(
                'Add new',
                domain: 'novamira',
            ); ?></a>
        </div>
        <p class="description" style="margin-top:4px;max-width:800px;"><?php esc_html_e(
            'Persistent, database-backed memory shared with agents via the Novamira memory abilities. Add user profile, feedback rules, project context, and external references here to carry them across conversations.',
            domain: 'novamira',
        ); ?></p>

        <?php render_memory_toggle(); ?>

        <div class="novamira-memory-filters">
            <a href="<?php echo esc_url($all_url); ?>" class="<?php echo
                esc_attr($type_filter === null ? 'is-active' : '')
            ; ?>"><?php esc_html_e('All', domain: 'novamira'); ?> (<?php echo (int) $counts['__total']; ?>)</a>
            <?php foreach (memory_types() as $t) {
                $url = memory_page_url(['type' => $t]);
                ?>
                <a href="<?php echo esc_url($url); ?>" class="<?php echo
                    esc_attr($type_filter === $t ? 'is-active' : '')
                ; ?>"><?php echo esc_html(memory_type_label($t)); ?> (<?php echo (int) $counts[$t]; ?>)</a>
            <?php
            } ?>
        </div>

        <?php if ($memories === []) { ?>
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;max-width:800px;">
                <p style="margin-top:0;"><?php esc_html_e(
                    'No memories yet. Add one to start building persistent context for agents.',
                    domain: 'novamira',
                ); ?></p>
                <p style="margin-bottom:0;"><a href="<?php echo
                    esc_url($new_url)
                ; ?>" class="button button-primary"><?php esc_html_e(
                    'Add your first memory',
                    domain: 'novamira',
                ); ?></a></p>
            </div>
        <?php } ?>
        <?php if ($memories !== []) { ?>
            <table class="widefat striped novamira-memory-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Name', domain: 'novamira'); ?></th>
                        <th scope="col" style="width:140px;"><?php esc_html_e('Type', domain: 'novamira'); ?></th>
                        <th scope="col" style="width:180px;"><?php esc_html_e('Updated', domain: 'novamira'); ?></th>
                        <th scope="col" style="width:160px;"><?php esc_html_e('Actions', domain: 'novamira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($memories as $memory) {
                        $edit_url = memory_page_url(['action' => 'edit', 'id' => $memory['id']]);
                        ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo
                                    esc_html($memory['name'])
                                ; ?></a></strong>
                                <?php if ($memory['description'] !== '') { ?>
                                    <div class="novamira-memory-description"><?php echo
                                        esc_html($memory['description'])
                                    ; ?></div>
                                <?php } ?>
                            </td>
                            <td><span class="novamira-memory-type is-<?php echo esc_attr($memory['type']); ?>"><?php

                            echo esc_html(memory_type_label($memory['type'])); ?></span></td>
                            <td><?php echo esc_html(memory_format_date($memory['updated_at'])); ?></td>
                            <td class="novamira-memory-row-actions">
                                <a href="<?php echo
                                    esc_url($edit_url)
                                ; ?>" class="button button-small"><?php esc_html_e('Edit', domain: 'novamira'); ?></a>
                                <form method="post" action="<?php echo
                                    esc_url(memory_page_url())
                                ; ?>" class="delete-form" onsubmit="return confirm('<?php echo
                                    esc_attr__('Delete this memory? This cannot be undone.', domain: 'novamira')
                                ; ?>');">
                                    <?php wp_nonce_field('novamira_memory'); ?>
                                    <input type="hidden" name="novamira_memory_action" value="delete">
                                    <input type="hidden" name="memory_id" value="<?php echo
                                        esc_attr((string) $memory['id'])
                                    ; ?>">
                                    <button type="submit" class="button button-small button-link-delete"><?php esc_html_e(
                                        'Delete',
                                        domain: 'novamira',
                                    ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php
                    } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
    <?php
}

/**
 * @param array{id:int,name:string,description:string,type:string,content:string,updated_at:string}|null $memory
 * @param array{name:string,description:string,type:string,content:string}|null $form_values
 * @param array{type:string,message:string}|null $notice
 */
function render_memory_edit_view(?array $memory, ?array $form_values, ?array $notice): void
{
    $is_edit = $memory !== null;
    $back_url = memory_page_url();
    $form_url = memory_page_url();

    $name = $form_values['name'] ?? $memory['name'] ?? '';
    $description = $form_values['description'] ?? $memory['description'] ?? '';
    $type = $form_values['type'] ?? $memory['type'] ?? 'project';
    $content = $form_values['content'] ?? $memory['content'] ?? '';

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo
            $is_edit ? esc_html__('Edit memory', domain: 'novamira') : esc_html__('Add new memory', domain: 'novamira')
        ; ?></h1>
        <a href="<?php echo esc_url($back_url); ?>" class="page-title-action"><?php esc_html_e(
            'Back to list',
            domain: 'novamira',
        ); ?></a>
        <hr class="wp-header-end">

        <?php render_memory_notice($notice); ?>

        <style>
            .novamira-memory-form { max-width:860px; background:#fff; border:1px solid #dcdcde; border-radius:12px; padding:24px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
            .novamira-memory-form .form-table th { width:180px; }
            .novamira-memory-form textarea { font-family:monospace; font-size:13px; }
            .novamira-memory-form .help { color:#50575e; margin-top:4px; max-width:560px; }
            .novamira-memory-form .actions { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:16px; flex-wrap:wrap; }
            .novamira-memory-form .actions-primary { display:flex; gap:8px; align-items:center; }
        </style>

        <form method="post" action="<?php echo esc_url($form_url); ?>" class="novamira-memory-form">
            <?php wp_nonce_field('novamira_memory'); ?>
            <?php if ($is_edit) { ?>
                <input type="hidden" name="memory_id" value="<?php echo esc_attr((string) $memory['id']); ?>">
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="memory_name"><?php esc_html_e(
                        'Name',
                        domain: 'novamira',
                    ); ?></label></th>
                    <td>
                        <input
                            type="text"
                            id="memory_name"
                            name="memory_name"
                            class="regular-text"
                            required
                            value="<?php echo esc_attr($name); ?>"
                        >
                        <p class="help"><?php esc_html_e(
                            'Short title for this memory (e.g. "User profile", "No Bricks-specific framing").',
                            domain: 'novamira',
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="memory_type"><?php esc_html_e(
                        'Type',
                        domain: 'novamira',
                    ); ?></label></th>
                    <td>
                        <select id="memory_type" name="memory_type" required>
                            <?php foreach (memory_types() as $t) { ?>
                                <option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php

                                echo esc_html(memory_type_label($t)); ?></option>
                            <?php } ?>
                        </select>
                        <p class="help"><?php esc_html_e(
                            'user: who the user is — role, preferences. feedback: how to approach work (with Why/How to apply lines). project: ongoing work & decisions. reference: pointers to external systems.',
                            domain: 'novamira',
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="memory_description"><?php esc_html_e(
                        'Description',
                        domain: 'novamira',
                    ); ?></label></th>
                    <td>
                        <input
                            type="text"
                            id="memory_description"
                            name="memory_description"
                            class="large-text"
                            required
                            value="<?php echo esc_attr($description); ?>"
                        >
                        <p class="help"><?php esc_html_e(
                            'One-line hook used in the index to decide relevance — be specific.',
                            domain: 'novamira',
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="memory_content"><?php esc_html_e(
                        'Content',
                        domain: 'novamira',
                    ); ?></label></th>
                    <td>
                        <textarea
                            id="memory_content"
                            name="memory_content"
                            rows="14"
                            class="large-text code"
                            required
                        ><?php echo esc_textarea($content); ?></textarea>
                        <p class="help"><?php esc_html_e(
                            'The memory body. For feedback/project types, structure as: rule/fact, then **Why:** and **How to apply:** lines.',
                            domain: 'novamira',
                        ); ?></p>
                    </td>
                </tr>
                <?php if ($is_edit) { ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last updated', domain: 'novamira'); ?></th>
                        <td><?php echo esc_html(memory_format_date($memory['updated_at'])); ?></td>
                    </tr>
                <?php } ?>
            </table>

            <div class="actions">
                <div class="actions-primary">
                    <button type="submit" name="novamira_memory_action" value="save" class="button button-primary"><?php echo
                        $is_edit
                            ? esc_html__('Save changes', domain: 'novamira')
                            : esc_html__('Create memory', domain: 'novamira')
                    ; ?></button>
                    <a href="<?php echo esc_url($back_url); ?>" class="button"><?php esc_html_e(
                        'Cancel',
                        domain: 'novamira',
                    ); ?></a>
                </div>
                <?php if ($is_edit) { ?>
                    <button
                        type="submit"
                        name="novamira_memory_action"
                        value="delete"
                        class="button button-link-delete"
                        onclick="return confirm('<?php echo
                            esc_attr__('Delete this memory? This cannot be undone.', domain: 'novamira')
                        ; ?>');"
                    ><?php esc_html_e('Delete', domain: 'novamira'); ?></button>
                <?php } ?>
            </div>
        </form>
    </div>
    <?php
}

function render_memory_toggle(): void
{
    $is_enabled = memory_is_enabled();
    $submit_value = $is_enabled ? '0' : '1';
    $submit_label = $is_enabled
        ? __('Disable memory abilities', domain: 'novamira')
        : __('Enable memory abilities', domain: 'novamira');
    $submit_class = $is_enabled ? 'button button-secondary' : 'button button-primary';
    $status_class = $is_enabled ? 'is-on' : 'is-off';
    $status_label = $is_enabled ? __('On', domain: 'novamira') : __('Off', domain: 'novamira');
    $hint = $is_enabled
        ? __('Agents can list, read, write, and delete memories via the memory abilities.', domain: 'novamira')
        : __(
            'Memory abilities are hidden from agents. Existing memories stay in place and appear again when you re-enable.',
            domain: 'novamira',
        );
    ?>
    <div class="novamira-memory-status">
        <div>
            <div class="status-text">
                <strong><?php esc_html_e('Memory abilities:', domain: 'novamira'); ?></strong>
                <span class="status-pill <?php echo esc_attr($status_class); ?>"><?php

                echo esc_html($status_label); ?></span>
            </div>
            <p class="status-hint" style="margin:6px 0 0;max-width:680px;"><?php echo esc_html($hint); ?></p>
        </div>
        <form method="post" action="<?php echo esc_url(memory_page_url()); ?>">
            <?php wp_nonce_field('novamira_memory'); ?>
            <input type="hidden" name="novamira_memory_action" value="toggle">
            <input type="hidden" name="memory_enabled" value="<?php echo esc_attr($submit_value); ?>">
            <button type="submit" class="<?php echo esc_attr($submit_class); ?>"><?php echo
                esc_html($submit_label)
            ; ?></button>
        </form>
    </div>
    <?php
}

/** @param array{type:string,message:string}|null $notice */
function render_memory_notice(?array $notice): void
{
    if ($notice === null) {
        return;
    }

    $type = in_array($notice['type'], ['success', 'warning', 'error', 'info'], strict: true) ? $notice['type'] : 'info';
    ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo
        esc_html($notice['message'])
    ; ?></p></div>
    <?php
}

function memory_format_date(string $gmt): string
{
    if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
        return '—';
    }

    $timestamp = strtotime($gmt . ' UTC');
    if ($timestamp === false) {
        return $gmt;
    }

    $format = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
    $formatted = wp_date($format, $timestamp);

    return $formatted === false ? $gmt : $formatted;
}

function boot_memory_admin(): void
{
    add_action('admin_menu', callback: __NAMESPACE__ . '\\register_memory_menu', priority: 12);
    add_action('admin_init', callback: __NAMESPACE__ . '\\redirect_legacy_memory_page');
}
