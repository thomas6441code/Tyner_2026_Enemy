<?php

namespace App\Enums;

/**
 * What kind of hardware a request came from.
 *
 * The mobile attendance channel is a *phone-or-tablet* channel: check-in, check-out and device
 * registration are all refused from laptops and desktops. The reason is not preference, it is
 * that the rest of the gate chain assumes a handset — a GPS fix good to 100m, a platform
 * authenticator with a fingerprint or face check, and one device physically carried by one
 * person. A desktop satisfies none of those honestly: its "location" is Wi-Fi triangulation
 * from the building's router, and its authenticator is shared with everyone who can unlock the
 * machine.
 *
 * `Unknown` exists so the detector never has to guess. It is grouped with Desktop by
 * `isHandheld()` — a request whose form factor cannot be established is refused, in keeping
 * with the fail-closed posture of every other gate in this channel.
 */
enum DeviceFormFactor: string
{
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Desktop = 'desktop';
    case Unknown = 'unknown';

    /**
     * Small, carried, personal — the only shapes this channel accepts.
     */
    public function isHandheld(): bool
    {
        return $this === self::Phone || $this === self::Tablet;
    }

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Desktop => 'Computer',
            self::Unknown => 'Unrecognised device',
        };
    }
}
