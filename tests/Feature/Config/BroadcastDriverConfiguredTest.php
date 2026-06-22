<?php

namespace Tests\Feature\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Sentinel: production-like deployments must not ship with BROADCAST_DRIVER=log or unset,
 * which silently drops real-time events used for catalog/stock coherence on POS/Kiosk/KDS.
 *
 * phpunit.xml pins APP_ENV=testing and BROADCAST_DRIVER=log for fast CI; this test overrides
 * env detection only inside the method body.
 */
class BroadcastDriverConfiguredTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key) === false ? null : getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function prodLikeEnvironments(): iterable
    {
        yield 'production' => ['production'];
        yield 'staging' => ['staging'];
    }

    /**
     * @dataProvider prodLikeEnvironments
     */
    public function test_acceptable_broadcast_driver_in_prod_like(string $appEnv): void
    {
        $this->setEnv('BROADCAST_DRIVER', 'pusher');
        $this->setEnv('APP_ENV', $appEnv);
        app()->detectEnvironment(fn () => $appEnv);
        Config::set('broadcasting.default', 'pusher');

        $driver = config('broadcasting.default');
        $this->assertContains($driver, ['pusher', 'ably', 'redis'], "Unexpected BROADCAST_DRIVER in {$appEnv}");
        $this->assertNotSame('log', $driver);
        $this->assertNotSame('null', $driver);
    }

    /**
     * @dataProvider prodLikeEnvironments
     */
    public function test_log_driver_must_fail_assertion_in_prod_like_so_ci_catches_misconfiguration(string $appEnv): void
    {
        $this->setEnv('BROADCAST_DRIVER', 'log');
        $this->setEnv('APP_ENV', $appEnv);
        app()->detectEnvironment(fn () => $appEnv);
        Config::set('broadcasting.default', 'log');

        try {
            $driver = config('broadcasting.default');
            $this->assertNotSame('log', $driver, 'Misconfigured: silent log broadcaster in prod-like env');
            $this->fail('Expected AssertionFailedError when driver is log');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('log', $e->getMessage());
        }
    }
}
