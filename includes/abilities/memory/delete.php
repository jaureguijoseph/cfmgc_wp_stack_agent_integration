<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Memory;

use WP_Error;
use WP_Post;

/**
 * Ability: Delete a memory by id.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/memory-delete', [
    'label' => __('Delete Memory', domain: 'novamira'),
    'description' => __(
        'Permanently deletes a memory. Use when a memory is wrong, outdated, or the user asks you to forget it.',
        domain: 'novamira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'integer',
                'description' => 'The memory id to delete.',
            ],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => 'Novamira\Memory\memory_delete',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Delete memories that turn out to be wrong, stale, or that the user has asked you to forget. Prefer updating (via novamira/memory-save with an id) over delete+recreate when the topic still applies but the details have changed.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array{id: int} $input
 * @return array{id: int, deleted: bool}|WP_Error
 */
function memory_delete(array $input): array|WP_Error
{
    $id = (int) $input['id'];
    /** @var WP_Post|null $post */
    $post = get_post($id);

    if (!$post || $post->post_type !== NOVAMIRA_MEMORY_POST_TYPE) {
        return new WP_Error('not_found', sprintf('Memory %d not found.', $id));
    }

    $result = wp_delete_post($id, force_delete: true);

    return [
        'id' => $id,
        'deleted' => $result !== false && $result !== null,
    ];
}
