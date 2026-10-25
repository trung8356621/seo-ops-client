<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use PHPUnit\Framework\TestCase;

final class EntitlementSnapshotMapperCommercialPresentationTest extends TestCase
{
    private EntitlementSnapshotMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new EntitlementSnapshotMapper;
    }

    public function test_enrollment_placeholders_are_not_prior_commercial(): void
    {
        $seo = [
            'key' => 'seo',
            'scope' => 'licensed',
            'license_model' => 'domain_quota',
            'enabled' => false,
            'quota' => null,
            'valid_until' => '2099-01-01T00:00:00Z',
            'revoked_at' => null,
        ];
        $agent = [
            'key' => 'agent',
            'scope' => 'global',
            'enabled' => true,
            'quota' => null,
        ];

        $this->assertFalse($this->mapper->hasPriorCommercialEntitlement($seo));
        $this->assertFalse($this->mapper->hasPriorCommercialEntitlement($agent));
    }

    public function test_granted_and_terminal_states_remain_visible(): void
    {
        $active = [
            'key' => 'seo',
            'scope' => 'licensed',
            'license_model' => 'domain_quota',
            'enabled' => true,
            'quota' => 30,
            'service_plan_key' => 'seo_basic',
            'valid_until' => '2099-01-01T00:00:00Z',
            'revoked_at' => null,
        ];
        $suspended = array_merge($active, ['enabled' => false]);
        $expired = array_merge($active, ['valid_until' => '2020-01-01T00:00:00Z']);
        $revoked = array_merge($active, [
            'enabled' => false,
            'revoked_at' => '2026-10-25T03:00:00Z',
        ]);

        $this->assertTrue($this->mapper->hasPriorCommercialEntitlement($active));
        $this->assertTrue($this->mapper->hasPriorCommercialEntitlement($suspended));
        $this->assertTrue($this->mapper->hasPriorCommercialEntitlement($expired));
        $this->assertTrue($this->mapper->hasPriorCommercialEntitlement($revoked));

        $this->assertSame('active', $this->mapper->commercialEntitlementStatus($active));
        $this->assertSame('suspended', $this->mapper->commercialEntitlementStatus($suspended));
        $this->assertSame('expired', $this->mapper->commercialEntitlementStatus($expired));
        $this->assertSame('revoked', $this->mapper->commercialEntitlementStatus($revoked));
    }
}
