<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgentSpecificationController extends Controller
{
    public function show(Request $request): Response
    {
        $agent = $request->user();
        $abilities = collect($agent->currentAccessToken()?->abilities ?? [])->sort()->values();
        $memberships = $agent->scopeMemberships()
            ->where('is_active', true)
            ->with('scope:id,name,slug')
            ->orderBy('scope_id')
            ->get();
        $language = match ($agent->preferred_language) {
            'en' => ['English', 'Write task comments, durable notes, reports, and user-facing answers in English unless the task explicitly requires another language.'],
            'zh' => ['Chinese', 'Write task comments, durable notes, reports, and user-facing answers in Chinese unless the task explicitly requires another language.'],
            default => ['Russian', 'Write task comments, durable notes, reports, and user-facing answers in Russian unless the task explicitly requires another language.'],
        };

        $scopeLines = $memberships->map(function ($membership): string {
            $scope = $membership->scope;

            return sprintf(
                '- `%s` — %s; role `%s`; project access `%s`',
                $scope->id,
                $scope->name,
                $membership->role,
                $membership->project_access_mode,
            );
        });

        $lines = [
            '# Zuratax Agent API',
            '',
            'Specification version: 2026-09-04.2',
            'Generated at: '.now()->toIso8601String(),
            '',
            '## Identity and access',
            '',
            "You are authenticated as **{$agent->name}** (`{$agent->id}`).",
            "Working language: **{$language[0]}** (`{$agent->preferred_language}`).",
            $language[1],
            'Token abilities: '.($abilities->isEmpty() ? '_none_' : $abilities->map(fn (string $ability): string => "`{$ability}`")->implode(', ')).'.',
            '',
            'Accessible scopes:',
            ...($scopeLines->isEmpty() ? ['- _none_'] : $scopeLines->all()),
            '',
            '## Transport contract',
            '',
            '- Use HTTPS and send the token only as `Authorization: Bearer <token>`.',
            '- Send `Accept: application/json` for API data and `Content-Type: application/json` for POST/PATCH requests.',
            '- Never put the token in a URL, task text, comment, log, commit, or chat message.',
            '- Treat `401` as an invalid/revoked token, `403` as a scope/capability boundary, and `422` as a validation error.',
            '- Follow pagination links/meta where a collection is paginated.',
            '- Use task keys such as `ADM-154` when talking to people; use entity IDs in API paths and payloads.',
            '',
            '## Discovery and work queue',
            '',
            '- `GET /api/agent/spec` — fetch this current specification before starting work.',
            '- `GET /api/agent/me` — verify the account.',
            '- `GET /api/agent/scopes` — list accessible scopes.',
            '- `GET /api/agent/tasks` — personal delegated queue. Add `?include_closed=1` to include done and cancelled tasks.',
            '',
            'The personal queue only contains tasks explicitly delegated to this agent and marked as agent-delegatable. Do not infer access from a task mentioned elsewhere.',
            '',
            '## Scope task API',
            '',
            'Replace `{scope}` and `{task}` with IDs returned by the API:',
            '',
            '- `GET /api/agent/scopes/{scope}/projects`',
            '- `POST /api/agent/scopes/{scope}/projects`',
            '- `GET /api/agent/scopes/{scope}/projects/{project}`',
            '- `PATCH /api/agent/scopes/{scope}/projects/{project}`',
            '- `GET /api/agent/scopes/{scope}/books` — list books allowed by `book_access_mode` and project access.',
            '- `POST /api/agent/scopes/{scope}/books` — create a book when `book.create` is allowed.',
            '- `GET /api/agent/scopes/{scope}/books/{book}`',
            '- `PATCH /api/agent/scopes/{scope}/books/{book}` — update when `book.update` is allowed.',
            '- `DELETE /api/agent/scopes/{scope}/books/{book}` — delete when `book.delete` is explicitly allowed.',
            '- `GET|POST /api/agent/scopes/{scope}/books/{book}/pages`',
            '- `GET|PATCH /api/agent/scopes/{scope}/books/{book}/pages/{page}`',
            '- `GET|POST /api/agent/scopes/{scope}/books/{book}/pages/{page}/blocks`',
            '- `GET|POST /api/agent/scopes/{scope}/books/{book}/pages/{page}/comments`',
            '- `GET /api/agent/scopes/{scope}/contractors/assignable` — safe assignee options.',
            '- `POST /api/agent/scopes/{scope}/facts` — create a Factor fact.',
            '- `GET /api/agent/scopes/{scope}/lore/context` — compact current project memory, ordered for agents. Use `?project_id=...` and optional `?as_of=...`.',
            '- `GET /api/agent/scopes/{scope}/lore` — searchable Lore records; filters include `q`, `project_id`, `area_id`, `tag`, `type`, `importance`, `criticality`, and `as_of`.',
            '- `GET /api/agent/scopes/{scope}/lore/{entry}` — one record with its complete immutable revision timeline.',
            '- `POST /api/agent/scopes/{scope}/lore` — create a Lore chain; requires `task.update`.',
            '- `POST /api/agent/scopes/{scope}/lore/{entry}/revisions` — publish a semantic revision instead of overwriting an earlier decision.',
            '- Lore revision `content` and `reason` are Markdown. Preserve headings, lists, tables, task lists, links, and fenced code blocks; do not flatten them to plain text.',
            '- `POST /api/agent/scopes/{scope}/links` — link two accessible entities.',
            '- `POST /api/agent/scopes/{scope}/imports/tasker` — idempotent Tasker import; send `dry_run: true` first.',
            '- `POST /api/agent/scopes/{scope}/imports/booker` — idempotent full-book import preserving pages, block types, and block versions; send one book per request and use `dry_run: true` first.',
            '- `GET /api/agent/scopes/{scope}/tasks`',
            '- `POST /api/agent/scopes/{scope}/tasks`',
            '- `GET /api/agent/scopes/{scope}/tasks/search`',
            '- `GET /api/agent/scopes/{scope}/tasks/{task}`',
            '- `PATCH /api/agent/scopes/{scope}/tasks/{task}`',
            '- `GET /api/agent/scopes/{scope}/tasks/{task}/checklist`',
            '- `POST /api/agent/scopes/{scope}/tasks/{task}/checklist`',
            '- `PATCH /api/agent/scopes/{scope}/tasks/{task}/checklist/{item}`',
            '- `GET /api/agent/scopes/{scope}/tasks/{task}/comments`',
            '- `POST /api/agent/scopes/{scope}/tasks/{task}/comments`',
            '- `DELETE /api/agent/scopes/{scope}/tasks/{task}/comments/{comment}`',
            '- `GET /api/agent/scopes/{scope}/tasks/{task}/activity`',
            '',
            '## Task documents',
            '',
            '- `description` — Markdown describing the task, its context, requirements, and constraints.',
            '- `result` — Markdown describing the verified result of completed work.',
            '- `agent_notes` — Markdown for durable AI findings, important answers, investigation notes, and handoff context. It is a separate task document, not a chat transcript.',
            '- `agent_notes` is accepted by task POST/PATCH endpoints. Send `null` to clear it.',
            '- Keep operational progress, questions, and blockers in task comments. Promote only durable information that should remain visible in the full task editor to `agent_notes`.',
            '',
            'Example:',
            '',
            '```json',
            '{"agent_notes":"## Investigation\\n\\nThe API is healthy. Verified with the production smoke test."}',
            '```',
            '',
            'Only call endpoints allowed by both the token abilities and the membership capabilities. Project restrictions remain in force inside a scope.',
            'Load Lore context before substantial project work. Treat foundational and critical records as mandatory constraints. By default use `current_revision`; request history or `as_of` only when investigating an earlier state. Never rewrite a published semantic decision: create a new revision with its effective date and reason.',
            'Book routes additionally enforce `book_access_mode`: `none`, `projects`, or `all`. Never assume that `book.*` token abilities alone grant access to a book.',
            'Creating facts requires `task.create`; creating links requires `task.update`. A link payload contains `source_type`, `source_id`, `target_type`, `target_id`, and optional `relation`, `note`, and `meta`.',
            'Tasker imports require both `task.create` and `task.update`, preserve external ULIDs for idempotency, never delete records, and require all-project access when creating projects.',
            'Booker imports require both `book.create` and `book.update`, full book access in the scope, and preserve external ULIDs. They never delete or overwrite an earlier imported entity.',
            '',
            '## Operating rules',
            '',
            '1. Fetch this specification, verify `/api/agent/me`, then load `/api/agent/tasks`.',
            '2. Read the task, its comments and activity before changing it.',
            '3. Make the smallest necessary change and preserve user data.',
            '4. Record meaningful progress or blockers in a task comment. Never claim completion without verification.',
            '5. If access is missing, stop at the boundary and report the exact endpoint and status; do not attempt to bypass it.',
            '6. Do not perform destructive actions unless the assigned task explicitly authorizes them.',
            '',
            'This endpoint is the source of truth. Fetch it again when a workflow or endpoint behaves differently from a cached instruction.',
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
