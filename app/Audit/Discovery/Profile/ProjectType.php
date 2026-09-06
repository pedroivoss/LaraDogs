<?php

namespace App\Audit\Discovery\Profile;

enum ProjectType: string
{
    case Laravel = 'laravel';
    case PlainPhpComposer = 'plain_php_composer';
    case NodeOnly = 'node_only';
    case EmptyProject = 'empty';
    case Unknown = 'unknown';
}
