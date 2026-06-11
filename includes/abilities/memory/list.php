<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Memory;

use WP_Post;

/**
 * Ability: List all stored memories (the index — equivalent to MEMORY.md).
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/memory-list', [
    'label' => __('List Memories', domain: 'novamira'),
    'description' => __(
        'Returns the concise memory index (id, name, description, type) for every stored memory. Note: the memory index is also automatically prepended to your tool discovery instructions, so you only need to call this if you need to fetch the index again during the session.',
        domain: 'novamira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'type' => [
                'type' => 'string',
                'enum' => ['user', 'feedback', 'project', 'reference'],
                'description' => 'Optional filter: only return memories of this type.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'memories' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'updated_at' => ['type' => 'string'],
                    ],
                ],
            ],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'Novamira\Memory\memory_list',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => <<<'TXT'
                You have a persistent, database-backed memory system. The index of all stored memories is automatically included in your initial tool-discovery instructions. This `novamira/memory-list` ability also returns the concise memory index (id, name, description, type) for every stored memory, but you typically do not need to call it if you can read the index from the server instructions.

                This system behaves exactly like a file-based memory system: this ability is the index (equivalent to MEMORY.md), while `novamira/memory-get` returns the full body of a single memory, `novamira/memory-save` writes or updates a memory, and `novamira/memory-delete` removes one.

                ## When to access memories
                - When memories seem relevant, or the user references prior-conversation work.
                - You MUST check memories when the user explicitly asks you to check, recall, or remember something.
                - If the user tells you to IGNORE or NOT USE memory, skip this entirely — do not apply, cite, or mention remembered facts.
                - Memory records can become stale. Before acting on a recalled memory that names a specific file, function, option, or fact about current state, verify it against the live system (read the file, grep the code, query the DB). If reality conflicts with the memory, trust reality and update or delete the stale memory rather than acting on it.
                - A memory that summarizes state (activity logs, architecture snapshots) is frozen in time. If the user asks about *recent* or *current* state, prefer live queries over recall.

                ## Workflow
                1. Read the memory index (either from your discover-abilities instructions or by calling `novamira/memory-list`).
                2. Call `novamira/memory-get` for any entry whose description looks relevant to the current task.
                3. Use the content to tailor your behavior.
                TXT,
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array{type?: string} $input
 * @return array{memories: list<array<string, mixed>>, total: int}
 */
function memory_list(array $input): array
{
    $args = [
        'post_type' => NOVAMIRA_MEMORY_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ];

    $type_filter = $input['type'] ?? null;
    if ($type_filter !== null && in_array($type_filter, NOVAMIRA_MEMORY_TYPES, strict: true)) {
        $args['meta_query'] = [
            [
                'key' => NOVAMIRA_MEMORY_META_TYPE,
                'value' => $type_filter,
            ],
        ];
    }

    /** @var list<WP_Post> $posts */
    $posts = get_posts($args);

    $memories = [];
    foreach ($posts as $post) {
        $shaped = memory_shape($post);
        unset($shaped['content']);
        $memories[] = $shaped;
    }

    return [
        'memories' => $memories,
        'total' => count($memories),
    ];
}
