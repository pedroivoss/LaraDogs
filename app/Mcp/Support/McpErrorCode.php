<?php

namespace App\Mcp\Support;

/**
 * The stable, typed error vocabulary of the MCP tool contract (schema
 * version 1). Values are part of the external contract — never rename.
 */
enum McpErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case InvalidArguments = 'invalid_arguments';
    case ProjectNotFound = 'project_not_found';
    case ScanNotFound = 'scan_not_found';
    case FindingNotFound = 'finding_not_found';
    case AuditAlreadyRunning = 'audit_already_running';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case InternalError = 'internal_error';
}
