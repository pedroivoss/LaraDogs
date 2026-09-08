<?php

namespace Fixture;

// If this file is ever EXECUTED (rather than only read as data by
// Semgrep's pattern matcher), it creates a marker file that must never
// exist after a scan — see SemgrepTargetCodeIsDataTest.
system('touch '.__DIR__.'/SHOULD_NEVER_EXIST');

dd('this line must still be found as a real match');
