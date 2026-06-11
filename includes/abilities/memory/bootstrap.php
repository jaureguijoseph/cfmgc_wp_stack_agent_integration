<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Memory;

use WP_Post;

/**
 * Memory system bootstrap.
 *
 * Registers the `novamira_memory` custom post type and the post meta used
 * to classify memories. Memories are backed by posts so we get revisions,
 * capabilities, and REST integration for free — this is the WordPress-native
 * way to store structured, queryable content.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_MEMORY_POST_TYPE = 'novamira_memory';

const NOVAMIRA_MEMORY_META_TYPE = '_novamira_memory_type';

/** @var list<string> */
const NOVAMIRA_MEMORY_TYPES = ['user', 'feedback', 'project', 'reference'];

add_action('init', static function (): void {
    register_post_type(NOVAMIRA_MEMORY_POST_TYPE, [
        'label' => __('Memories', domain: 'novamira'),
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'],
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'has_archive' => false,
        'rewrite' => false,
        'query_var' => false,
    ]);

    register_post_meta(NOVAMIRA_MEMORY_POST_TYPE, NOVAMIRA_MEMORY_META_TYPE, [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => false,
        'sanitize_callback' => static function (mixed $value): string {
            $value = is_string($value) ? $value : '';

            return in_array($value, NOVAMIRA_MEMORY_TYPES, strict: true) ? $value : 'project';
        },
    ]);
});

/**
 * Shape a memory post into the wire format used by the abilities.
 *
 * @return array{id: int, name: string, description: string, type: string, content: string, updated_at: string}
 */
function memory_shape(WP_Post $post): array
{
    /** @var mixed $type */
    $type = get_post_meta($post->ID, NOVAMIRA_MEMORY_META_TYPE, single: true);

    return [
        'id' => $post->ID,
        'name' => $post->post_title,
        'description' => $post->post_excerpt,
        'type' => is_string($type) && $type !== '' ? $type : 'project',
        'content' => $post->post_content,
        'updated_at' => $post->post_modified_gmt,
    ];
}
