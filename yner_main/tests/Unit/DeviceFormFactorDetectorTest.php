<?php

namespace Tests\Unit;

use App\Enums\DeviceFormFactor;
use App\Services\DeviceFormFactorDetector;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The User-Agent classification table.
 *
 * Kept as a data provider rather than as prose assertions because this is exactly the kind of
 * rule that gets "improved" by a regex tweak that quietly reclassifies Android tablets or
 * lets Chrome on Windows through. Every string below is a real one seen in the field.
 */
class DeviceFormFactorDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string, DeviceFormFactor}>
     */
    public static function agents(): array
    {
        return [
            'iPhone Safari' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
                DeviceFormFactor::Phone,
            ],
            'Android phone Chrome' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
                DeviceFormFactor::Phone,
            ],
            'Android tablet Chrome' => [
                'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                DeviceFormFactor::Tablet,
            ],
            'iPad Safari' => [
                'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
                DeviceFormFactor::Tablet, // despite carrying the Mobile token, which is why iPad is matched first
            ],
            'Windows Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                DeviceFormFactor::Desktop,
            ],
            'macOS Safari' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
                DeviceFormFactor::Desktop,
            ],
            'Linux Firefox' => [
                'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
                DeviceFormFactor::Desktop,
            ],
            'ChromeOS' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                DeviceFormFactor::Desktop,
            ],
            'curl' => ['curl/8.4.0', DeviceFormFactor::Unknown],
            'empty' => ['', DeviceFormFactor::Unknown],
        ];
    }

    #[DataProvider('agents')]
    public function test_it_classifies_user_agents(string $agent, DeviceFormFactor $expected): void
    {
        $this->assertSame($expected, $this->classify($agent));
    }

    public function test_a_missing_request_is_unknown(): void
    {
        $this->assertSame(DeviceFormFactor::Unknown, (new DeviceFormFactorDetector)->classify(null));
    }

    public function test_the_client_hint_outranks_the_user_agent(): void
    {
        // Chromium on a phone can send a UA its owner has overridden; the structured hint is
        // the field that exists for this question alone.
        $this->assertSame(
            DeviceFormFactor::Phone,
            $this->classify(self::agents()['Windows Chrome'][0], ['Sec-CH-UA-Mobile' => '?1']),
        );
    }

    public function test_an_ipad_in_desktop_mode_is_a_tablet(): void
    {
        // iPadOS defaults to "Request Desktop Website" and sends a Macintosh UA. Touch points
        // are the only thing separating it from an iMac.
        $this->assertSame(
            DeviceFormFactor::Tablet,
            $this->classify(self::agents()['macOS Safari'][0], [DeviceFormFactorDetector::TOUCH_POINTS_HEADER => '5']),
        );
    }

    public function test_touch_points_do_not_rescue_a_windows_machine(): void
    {
        // A touch-screen laptop is still a laptop, and the rescue above is scoped to Macintosh
        // UAs precisely so this stays refused.
        $this->assertSame(
            DeviceFormFactor::Desktop,
            $this->classify(self::agents()['Windows Chrome'][0], [DeviceFormFactorDetector::TOUCH_POINTS_HEADER => '10']),
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function classify(string $agent, array $headers = []): DeviceFormFactor
    {
        $request = Request::create('/check-in', 'POST');
        $request->headers->set('User-Agent', $agent);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return (new DeviceFormFactorDetector)->classify($request);
    }
}
