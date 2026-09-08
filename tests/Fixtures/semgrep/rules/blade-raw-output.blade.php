{{-- Fixture for laradogs.security.blade.raw-output-tainted. --}}
{{-- Positive cases below must be flagged; negative/safe cases must not. --}}

{{-- POSITIVE: raw output of ->input() directly. --}}
<div>{!! $request->input('bio') !!}</div>

{{-- POSITIVE: raw output of request()->get() directly. --}}
<div>{!! request()->get('bio') !!}</div>

{{-- POSITIVE: raw output of a superglobal. --}}
<div>{!! $_GET['bio'] !!}</div>

{{-- NEGATIVE: raw output of a plain variable — this is the exact case this
     rule deliberately does NOT flag (a textual heuristic, not real
     dataflow): a variable named $comment could still be tainted upstream,
     but its name alone gives no textual signal either way. --}}
<div>{!! $comment->body !!}</div>

{{-- NEGATIVE: escaped output is never raw in the first place. --}}
<p>{{ $safeValue }}</p>

{{-- SAFE: the value is explicitly sanitized before being assigned, and the
     raw-output block only ever echoes the already-sanitized variable name
     — this rule cannot see the assignment above the current line, but
     since the raw-output expression itself does not textually contain any
     of the recognized input-accessor substrings, it is correctly not
     flagged. --}}
<div>{!! $sanitizedBio !!}</div>
