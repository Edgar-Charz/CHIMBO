<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Links are built from the address the request came in on — but only for trusted hosts. */
final class BaseUrlTest extends TestCase
{
    public function testTunnelHostGetsHttpsLinks(): void
    {
        Env::set('APP_ENV', 'local');
        Env::set('APP_TRUSTED_HOSTS', 'chimbo-demo.ngrok-free.app');
        $_SERVER['HTTP_HOST'] = 'chimbo-demo.ngrok-free.app';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertSame('https://chimbo-demo.ngrok-free.app/chimbo/media/a.webp', url('media/a.webp'));
    }

    public function testForwardedProtoIsIgnoredForUntrustedHosts(): void
    {
        Env::set('APP_ENV', 'local');
        $_SERVER['HTTP_HOST'] = '10.0.2.2';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertSame('http://10.0.2.2/chimbo/media/a.webp', url('media/a.webp'));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        Env::set('APP_ENV', 'testing');
        Env::set('APP_URL', 'http://localhost/chimbo');
        Env::set('APP_TRUSTED_HOSTS', '');
    }

    public function testCommandLineScriptsUseAppUrl(): void
    {
        unset($_SERVER['HTTP_HOST']);
        $this->assertSame('http://localhost/chimbo/media/a.webp', url('media/a.webp'));
    }

    public static function localDevices(): array
    {
        return [
            'Android emulator' => ['10.0.2.2', 'http://10.0.2.2/chimbo/media/a.webp'],
            'phone on Wi-Fi'   => ['192.168.1.20', 'http://192.168.1.20/chimbo/media/a.webp'],
            'PC browser'       => ['localhost', 'http://localhost/chimbo/media/a.webp'],
            'with a port'      => ['127.0.0.1:8080', 'http://127.0.0.1:8080/chimbo/media/a.webp'],
        ];
    }

    #[DataProvider('localDevices')]
    public function testLocalDevicesGetLinksTheyCanOpen(string $host, string $expected_url): void
    {
        Env::set('APP_ENV', 'local');
        $_SERVER['HTTP_HOST'] = $host;

        $this->assertSame($expected_url, url('media/a.webp'));
    }

    public function testUnknownHostFallsBackToAppUrl(): void
    {
        Env::set('APP_ENV', 'local');
        $_SERVER['HTTP_HOST'] = 'evil.example.com';

        $this->assertSame('http://localhost/chimbo/media/a.webp', url('media/a.webp'));
    }

    public function testProductionIgnoresPrivateAddresses(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('APP_URL', 'https://kachimbo.efolder.fun');
        $_SERVER['HTTP_HOST'] = '10.0.2.2';

        $this->assertSame('https://kachimbo.efolder.fun/media/a.webp', url('media/a.webp'));
    }

    public function testProductionUsesItsOwnDomainOverHttps(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('APP_URL', 'https://kachimbo.efolder.fun');
        $_SERVER['HTTP_HOST'] = 'kachimbo.efolder.fun';
        $_SERVER['HTTPS'] = 'on';

        $this->assertSame('https://kachimbo.efolder.fun/media/a.webp', url('media/a.webp'));
    }

    public function testExtraTrustedHostIsAccepted(): void
    {
        Env::set('APP_ENV', 'staging');
        Env::set('APP_TRUSTED_HOSTS', 'staging.kachimbo.efolder.fun, api.kachimbo.efolder.fun');
        $_SERVER['HTTP_HOST'] = 'api.kachimbo.efolder.fun';

        $this->assertSame('http://api.kachimbo.efolder.fun/chimbo/media/a.webp', url('media/a.webp'));
    }
}
