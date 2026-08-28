<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A phone's User-Agent, sent on every test request by default.
     *
     * Attendance check-in and device registration are refused from laptops and desktops
     * (App\Services\DeviceFormFactorDetector), and a test request carries no User-Agent at all
     * — which that detector correctly classifies as "not a handheld". Defaulting the suite to
     * a phone makes the ordinary test express the ordinary case; a test that wants a desktop
     * says so with `$this->withHeader('User-Agent', ...)`, which overrides this.
     */
    public const PHONE_USER_AGENT = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';

    public const DESKTOP_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('User-Agent', self::PHONE_USER_AGENT);
    }
}
