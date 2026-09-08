<?php

namespace Fixture\CommandExec;

// Fixture for laradogs.security.command.tainted-exec.
// Positive cases below must be flagged; negative/safe cases must not.

class Controller
{
    // POSITIVE: direct pass-through into exec().
    public function positiveDirect($request)
    {
        $cmd = $request->input('cmd');
        exec($cmd);
    }

    // POSITIVE: tainted value reaches shell_exec() through concatenation.
    public function positiveConcat($request)
    {
        $file = $request->get('file');
        shell_exec('cat '.$file);
    }

    // POSITIVE: tainted value reaches system() through interpolation.
    public function positiveInterpolated($request)
    {
        $arg = request()->input('arg');
        system("ls $arg");
    }

    // POSITIVE: passthru() with a superglobal source.
    public function positiveSuperglobal()
    {
        passthru($_GET['cmd']);
    }

    // NEGATIVE: constant command, no tainted input anywhere.
    public function negativeConstant()
    {
        system('ls -la /tmp');
    }

    // NEGATIVE: exec() with a hardcoded argument built from config only.
    public function negativeFromConfig()
    {
        exec('convert '.config('app.image_tool_path'));
    }

    // SAFE: request input cast to int before reaching the sink.
    public function safeCastToInt($request)
    {
        $n = (int) $request->input('count');
        exec('repeat '.$n);
    }

    // SAFE: request input escaped via escapeshellarg() before the sink.
    public function safeEscaped($request)
    {
        $file = escapeshellarg($request->get('file'));
        shell_exec('cat '.$file);
    }
}
