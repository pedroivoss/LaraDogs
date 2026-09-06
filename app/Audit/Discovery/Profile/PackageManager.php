<?php

namespace App\Audit\Discovery\Profile;

enum PackageManager: string
{
    case Npm = 'npm';
    case Yarn = 'yarn';
    case Pnpm = 'pnpm';
}
