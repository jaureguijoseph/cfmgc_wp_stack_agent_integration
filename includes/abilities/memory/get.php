<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Memory;

use WP_Error;
use WP_Post;

/**
 * Ability: Fetch a single memory's full body by id.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/memory-get', [
    'label' => __('Get Memory', domain: 'novamira'),
    'description' => __('Returns the full content of a single memory by id.', domain: 'novamira'),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'integer',
                'description' => 'The memory id returned in the discover-abilities instructions or novamira/memory-list.',
            ],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'updated_at' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'Novamira\Memory\memory_get',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call this after reading the memory index (either from the discover-abilities instructions or by calling novamira/memory-list) to read the full body of a memory whose description looks relevant. Feedback and project memories typically include **Why:** and **How to apply:** lines you should respect.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array{id: int} $input
 * @return array<string, mixed>|WP_Error
 */
function memory_get(array $input): array|WP_Error
{
    $id = (int) $input['id'];
    /** @var WP_Post|null $post */
    $post = get_post($id);

    if (!$post || $post->post_type !== NOVAMIRA_MEMORY_POST_TYPE) {
        return new WP_Error('not_found', sprintf('Memory %d not found.', $id));
    }

    return memory_shape($post);
}
