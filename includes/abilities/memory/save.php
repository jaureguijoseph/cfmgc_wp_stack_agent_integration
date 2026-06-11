<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Memory;

use WP_Error;
use WP_Post;

/**
 * Ability: Create or update a memory.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/memory-save', [
    'label' => __('Save Memory', domain: 'novamira'),
    'description' => __(
        'Creates a new memory or updates an existing one. Use this to build up a persistent understanding of the user, their feedback, ongoing projects, and external references across conversations.',
        domain: 'novamira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'integer',
                'description' => 'Id of an existing memory to update. Omit to create a new one.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Short title for the memory (e.g. "User profile", "No Bricks-specific framing").',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'One-line hook describing what this memory contains. Used in the index to decide relevance — be specific.',
            ],
            'type' => [
                'type' => 'string',
                'enum' => ['user', 'feedback', 'project', 'reference'],
                'description' => 'Which kind of memory this is. See the ability instructions for definitions.',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'The memory body. For feedback/project, structure as: rule/fact, then **Why:** and **How to apply:** lines.',
            ],
        ],
        'required' => ['name', 'description', 'type', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'created' => ['type' => 'boolean'],
            'name' => ['type' => 'string'],
            'type' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'Novamira\Memory\memory_save',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => <<<'TXT'
                You have a persistent, database-backed memory system. Build it up over time so that future conversations can start with a complete picture of who the user is, how they collaborate with you, what to avoid or repeat, and the context behind the work.

                If the user explicitly asks you to remember something, save it immediately as whichever type fits best. If they ask you to forget something, call `novamira/memory-delete`.

                ## Types of memory

                **user** — Role, goals, responsibilities, knowledge, preferences. Save when you learn any details about who the user is. Use to tailor explanations to their background (e.g. a senior engineer vs. a first-time coder). Avoid negative judgments.

                **feedback** — Guidance the user has given about how to approach work. Save from BOTH correction ("no, not that", "stop doing X") AND confirmation ("yes exactly", "keep doing that", accepting an unusual choice without pushback). Corrections are easy to notice; confirmations are quieter — watch for them. Structure: lead with the rule, then a `**Why:**` line (the reason the user gave — often a past incident) and a `**How to apply:**` line (when/where the rule kicks in). Knowing *why* lets you judge edge cases.

                **project** — Ongoing work, goals, bugs, initiatives, decisions that aren't derivable from code or git history. Save who is doing what, why, or by when. Always convert relative dates to absolute dates before saving (e.g. "Thursday" → "2026-04-09"). Structure: fact/decision, then `**Why:**` and `**How to apply:**`.

                **reference** — Pointers to where information lives in external systems (Linear project X tracks Y bugs; Grafana board Z is the oncall dashboard). Save when you learn about external resources and their purpose.

                ## What NOT to save
                - Code patterns, conventions, architecture, file paths, project structure — derivable from the live codebase.
                - Git history, recent changes, who-changed-what — `git log`/`git blame` are authoritative.
                - Debugging solutions or fix recipes — the fix is in the code, the commit message has the context.
                - Anything already documented in CLAUDE.md.
                - Ephemeral task state: in-progress work, current conversation context.

                These exclusions apply even when the user explicitly asks you to save. If they ask you to save a PR list or activity summary, ask what was *surprising* or *non-obvious* about it — that's the part worth keeping.

                ## How to save
                1. Check the memory index (either in your discover-abilities instructions or by calling `novamira/memory-list`) first to check if an existing memory covers the same topic — prefer UPDATING (pass `id`) over creating duplicates.
                2. Call this ability with:
                   - `name`: short title
                   - `description`: specific one-line hook (this shows up in the index and is how future-you decides relevance)
                   - `type`: user | feedback | project | reference
                   - `content`: the body, structured per the type above
                3. Organize memories semantically by topic, not chronologically.
                4. If a memory turns out to be wrong or outdated, update or delete it — don't accumulate stale entries.
                TXT,
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array{id?: int, name: string, description: string, type: string, content: string} $input
 * @return array{id: int, created: bool, name: string, type: string}|WP_Error
 */
function memory_save(array $input): array|WP_Error
{
    $type = $input['type'];
    if (!in_array($type, NOVAMIRA_MEMORY_TYPES, strict: true)) {
        return new WP_Error('invalid_type', sprintf('Unknown memory type "%s".', $type));
    }

    $postarr = [
        'post_type' => NOVAMIRA_MEMORY_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $input['name'],
        'post_excerpt' => $input['description'],
        'post_content' => $input['content'],
    ];

    $id = (int) ($input['id'] ?? 0);
    $created = $id <= 0;

    if (!$created) {
        /** @var WP_Post|null $existing */
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== NOVAMIRA_MEMORY_POST_TYPE) {
            return new WP_Error('not_found', sprintf('Memory %d not found.', $id));
        }
        $postarr['ID'] = $id;
    }

    $result = $created ? wp_insert_post($postarr, wp_error: true) : wp_update_post($postarr, wp_error: true);

    if (is_wp_error($result)) {
        return $result;
    }

    $saved_id = (int) $result;
    update_post_meta($saved_id, NOVAMIRA_MEMORY_META_TYPE, $type);

    return [
        'id' => $saved_id,
        'created' => $created,
        'name' => $input['name'],
        'type' => $type,
    ];
}
