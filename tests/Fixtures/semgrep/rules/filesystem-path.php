<?php

namespace Fixture\FilesystemPath;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    // Phase 6.1 real-world regression (allimaPanel, 2026-09-08): a
    // server-generated, uuid-based path with TAINTED CONTENT must NOT be
    // flagged — the path itself is safe; only file_put_contents()'s second
    // (content) argument is tainted. Before the fix, the sink pattern's
    // trailing `...` let taint anywhere in the arguments trigger the match,
    // confusing "tainted content" with "tainted path".
    public function safeUuidPathTaintedContent($request)
    {
        $path = storage_path('app/temp/'.Str::uuid().'.jpg');

        file_put_contents($path, $request->input('image'));
    }

    // Phase 6.1 real-world regression: a tainted value is passed as an
    // ARGUMENT to a helper/service method, and the method's RETURN VALUE
    // (not the argument itself) reaches the sink. The real service here
    // regenerates a fresh, server-side uuid path — it never builds the
    // returned path from its arguments — so this must NOT be flagged.
    // Documents the accepted trade-off: `taint_assume_safe_functions` also
    // means a genuinely TRANSPARENT wrapper (see
    // knownLimitationTransparentWrapper below) is no longer caught either.
    public function safeServiceRegeneratesPath($request, $service)
    {
        $croppedPath = $service->cropImage($request->input('x'), $request->input('y'));

        unlink($croppedPath);
    }

    // Known, accepted limitation (documented, not fixed this phase): a
    // helper that is a genuine TRANSPARENT wrapper — returns its tainted
    // argument completely unchanged — is no longer flagged either, now
    // that cross-call return values are assumed safe by default. No
    // interprocedural taint analysis is being built to distinguish this
    // from safeServiceRegeneratesPath above; see
    // docs/auditing/rules/security-rules.md for the accepted trade-off.
    public function knownLimitationTransparentWrapper($request)
    {
        $path = $this->identity($request->input('path'));

        return file_get_contents($path);
    }

    private function identity($value)
    {
        return $value;
    }
}
