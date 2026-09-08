<?php

namespace Fixture\OpenRedirect;

// Fixture for laradogs.security.redirect.tainted-open-redirect.
// Positive cases below must be flagged; negative/safe cases must not.

class Controller
{
    // POSITIVE: direct pass-through into redirect().
    public function positiveDirect($request)
    {
        $next = $request->input('next');

        return redirect($next);
    }

    // POSITIVE: tainted value reaches redirect()->to() through concatenation.
    public function positiveConcat($request)
    {
        $path = $request->get('return_to');

        return redirect()->to('/app/'.$path);
    }

    // POSITIVE: tainted value reaches redirect()->to() directly.
    public function positiveDirectTo($request)
    {
        $url = request()->query('url');

        return redirect()->to($url);
    }

    // NEGATIVE: constant internal redirect, no tainted input anywhere.
    public function negativeConstant()
    {
        return redirect('/dashboard');
    }

    // NEGATIVE: named route redirect — the destination is never user input.
    public function negativeNamedRoute()
    {
        return redirect()->route('home');
    }

    // SAFE: request input cast to int before being used to build the
    // redirect target — still reaches the redirect() sink, but the taint
    // is neutralized by the numeric cast first.
    public function safeCastToInt($request)
    {
        $id = (int) $request->input('id');

        return redirect('/posts/'.$id);
    }
}
