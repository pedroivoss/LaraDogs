<?php

namespace Fixture\MassAssignment;

// Fixture for laradogs.security.mass-assignment.request-all.
// Positive cases below must be flagged; negative/safe cases must not.

class User {}

class Controller
{
    // POSITIVE: the entire request payload passed directly to create().
    public function positiveCreate($request)
    {
        return User::create($request->all());
    }

    // POSITIVE: the entire request payload passed directly to update().
    public function positiveUpdate($request, $user)
    {
        $user->update($request->all());

        return $user;
    }

    // NEGATIVE: only explicitly-selected fields are passed.
    public function negativeOnly($request)
    {
        return User::create($request->only(['name', 'email']));
    }

    // NEGATIVE: an explicit array literal, not the request at all.
    public function negativeExplicitArray($request)
    {
        return User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ]);
    }

    // SAFE: validated data is used instead of the raw request payload.
    public function safeValidated($request)
    {
        $data = $request->validate(['name' => 'required', 'email' => 'required|email']);

        return User::create($data);
    }
}
