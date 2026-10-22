<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Services\H1\Endpoint\ServerEndpointValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ServerEndpointValidatorTest extends TestCase
{
    public function test_loopback_hosts_are_detected(): void
    {
        $this->assertTrue(ServerEndpointValidator::isLoopbackHost('localhost'));
        $this->assertTrue(ServerEndpointValidator::isLoopbackHost('127.0.0.1'));
        $this->assertTrue(ServerEndpointValidator::isLoopbackHost('127.0.0.100'));
        $this->assertTrue(ServerEndpointValidator::isLoopbackHost('::1'));
        $this->assertTrue(ServerEndpointValidator::isLoopbackHost('[::1]'));

        $this->assertFalse(ServerEndpointValidator::isLoopbackHost('example.com'));
        $this->assertFalse(ServerEndpointValidator::isLoopbackHost('192.168.1.1'));
        $this->assertFalse(ServerEndpointValidator::isLoopbackHost('10.0.0.1'));
    }

    public function test_loopback_allows_http_and_https(): void
    {
        $this->assertSame('http://localhost:3000', ServerEndpointValidator::validate('http://localhost:3000'));
        $this->assertSame('https://localhost:3000', ServerEndpointValidator::validate('https://localhost:3000'));
        $this->assertSame('http://127.0.0.1:3000', ServerEndpointValidator::validate('http://127.0.0.1:3000/'));
        $this->assertSame('http://[::1]:3000', ServerEndpointValidator::validate('http://[::1]:3000'));
    }

    public function test_non_loopback_requires_https(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');
        ServerEndpointValidator::validate('http://ops.example.com');
    }

    public function test_non_loopback_https_is_allowed(): void
    {
        $this->assertSame('https://ops.example.com', ServerEndpointValidator::validate('https://ops.example.com/'));
        $this->assertSame('https://ops.example.com:8443', ServerEndpointValidator::validate('https://ops.example.com:8443'));
    }

    public function test_private_ips_are_rejected(): void
    {
        $privateUrls = [
            'https://192.168.1.1',
            'https://10.0.0.1',
            'https://172.16.0.1',
            'https://169.254.169.254',
        ];

        foreach ($privateUrls as $url) {
            try {
                ServerEndpointValidator::validate($url);
                $this->fail("Expected exception for private IP: {$url}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('IP', $e->getMessage());
            }
        }
    }

    public function test_embedded_credentials_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thông tin xác thực');
        ServerEndpointValidator::validate('http://admin:secret@localhost:3000');
    }

    public function test_query_and_fragment_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('truy vấn');
        ServerEndpointValidator::validate('http://localhost:3000?foo=bar');
    }

    public function test_subpath_rejected_when_not_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('đường dẫn con');
        ServerEndpointValidator::validate('http://localhost:3000/api/v1');
    }

    public function test_subpath_allowed_when_explicitly_permitted(): void
    {
        $valid = ServerEndpointValidator::validate('http://localhost:3000/api/v1/health', allowPath: true);
        $this->assertSame('http://localhost:3000/api/v1/health', $valid);
    }
}
