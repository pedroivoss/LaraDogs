<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Support\Detection;
use JsonSerializable;

final readonly class DatabaseProfile implements JsonSerializable
{
    /**
     * @param  array<string,Detection>  $drivers  Keyed by {@see DatabaseDriver}
     *                                            value.
     */
    public function __construct(
        public array $drivers,
    ) {}

    /**
     * @return list<string>
     */
    public function detectedDrivers(): array
    {
        return array_keys(array_filter(
            $this->drivers,
            fn (Detection $detection): bool => $detection->isDetected(),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'drivers' => $this->drivers,
            'drivers_detected' => $this->detectedDrivers(),
        ];
    }
}
