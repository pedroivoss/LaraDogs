<?php

namespace Fixture\FilesystemPath;

use Illuminate\Support\Facades\Storage;

// Fixture for laradogs.security.filesystem.tainted-path.
// Positive cases below must be flagged; negative/safe cases must not.

class Controller
{
    // POSITIVE: direct pass-through into file_get_contents().
    public function positiveDirect($request)
    {
        $path = $request->input('path');

        return file_get_contents($path);
    }

    // POSITIVE: tainted value reaches unlink() through concatenation.
    public function positiveConcat($request)
    {
        $name = $request->get('file');
        unlink('/uploads/'.$name);
    }

    // POSITIVE: tainted value reaches Storage::get() through interpolation.
    public function positiveInterpolated($request)
    {
        $file = request()->input('file');

        return Storage::get("uploads/$file");
    }

    // POSITIVE: fopen() with a superglobal source.
    public function positiveSuperglobal()
    {
        return fopen($_GET['path'], 'r');
    }

    // NEGATIVE: constant path, no tainted input anywhere.
    public function negativeConstant()
    {
        return file_get_contents(storage_path('app/fixed-report.txt'));
    }

    // NEGATIVE: Storage::get() with a hardcoded key.
    public function negativeFixedStorage()
    {
        return Storage::get('exports/latest.csv');
    }

    // SAFE: request input passed through basename() before the sink,
    // stripping any directory traversal segments.
    public function safeBasename($request)
    {
        $name = basename($request->input('file'));

        return Storage::get('uploads/'.$name);
    }

    // SAFE: request input cast to int (a numeric id, not a path fragment)
    // before being used to build the path.
    public function safeCastToInt($request)
    {
        $id = (int) $request->get('id');

        return file_get_contents(storage_path('reports/'.$id.'.pdf'));
    }
}
