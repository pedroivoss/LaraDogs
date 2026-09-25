<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpError;
use App\Mcp\Support\McpErrorCode;
use App\Mcp\Support\McpPayloads;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base of every LaraDogs MCP tool (Phase 11 — tool contract schema version
 * {@see McpPayloads::SCHEMA_VERSION}).
 *
 * The template is fixed and identical for all tools: authenticate/authorize
 * through the ONE {@see McpAccess}, validate arguments (typed, bounded,
 * unknown arguments rejected), run, return a structured result — or a typed,
 * client-safe {@see McpError}. Nothing else can escape: an unexpected
 * failure becomes `internal_error` (never a message, SQL error, path or
 * stack trace) and is only reported to LaraDogs' own log.
 *
 * Tool names, titles and descriptions are STATIC strings written by
 * LaraDogs — no target-controlled text ever reaches the tool surface.
 */
abstract class LaraDogsTool extends Tool
{
    /** Public ids are ULIDs: 26 alphanumeric characters. */
    protected const string ID_PATTERN = '/^[0-9A-Za-z]{26}$/';

    public function handle(Request $request, McpAccess $access): ResponseFactory|Response
    {
        $started = hrtime(true);
        $outcome = 'ok';

        try {
            $result = $this->run($request->all(), $access);

            return Response::structured(['schema_version' => McpPayloads::SCHEMA_VERSION, ...$result]);
        } catch (McpError $error) {
            $outcome = $error->errorCode->value;

            return $this->errorResponse($error);
        } catch (Throwable $throwable) {
            $outcome = McpErrorCode::InternalError->value;
            report($throwable);

            return $this->errorResponse(new McpError(McpErrorCode::InternalError, 'The request could not be completed.'));
        } finally {
            // Bounded diagnostics only: tool, outcome, duration — never
            // arguments, evidence, a token or a snippet.
            Log::info('mcp.tool', ['tool' => $this->name(), 'outcome' => $outcome, 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
        }
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed> a non-empty result object
     */
    abstract protected function run(array $arguments, McpAccess $access): array;

    private function errorResponse(McpError $error): ResponseFactory
    {
        $envelope = ['schema_version' => McpPayloads::SCHEMA_VERSION, ...$error->toEnvelope()];

        return Response::make(Response::error((string) json_encode($envelope, JSON_UNESCAPED_SLASHES)))
            ->withStructuredContent($envelope);
    }

    /**
     * Rejects unknown arguments and enforces typed/bounded rules.
     *
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    protected function validated(array $arguments, array $rules): array
    {
        $unknown = array_diff(array_keys($arguments), array_keys($rules));

        if ($unknown !== []) {
            $names = array_map(fn ($k) => substr((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $k), 0, 40), array_slice($unknown, 0, 5));

            throw new McpError(McpErrorCode::InvalidArguments, 'Unknown argument(s): '.implode(', ', $names).'.');
        }

        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            throw new McpError(McpErrorCode::InvalidArguments, implode(' ', array_slice($validator->errors()->all(), 0, 3)));
        }

        return $validator->validated();
    }

    protected function project(string $publicId): Project
    {
        return Project::query()->where('public_id', strtolower($publicId))->first()
            ?? throw new McpError(McpErrorCode::ProjectNotFound, 'No project with that id.');
    }

    protected function scan(string $publicId): Scan
    {
        return Scan::query()->where('public_id', strtolower($publicId))->with('project')->first()
            ?? throw new McpError(McpErrorCode::ScanNotFound, 'No scan with that id.');
    }

    protected function finding(string $publicId): Finding
    {
        return Finding::query()->where('public_id', strtolower($publicId))->with('project')->first()
            ?? throw new McpError(McpErrorCode::FindingNotFound, 'No finding with that id.');
    }

    /** @return list<string> */
    protected function idRule(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:'.self::ID_PATTERN];
    }
}
