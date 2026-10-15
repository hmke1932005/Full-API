<?php

namespace Tests\Feature;

use App\Support\DisposableEmail;
use App\Support\PlatformPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Admin → Platform Policy: custom block / allow lists, per-check switches, registration + encryption switches. */
class PlatformPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformPolicy::flush();
    }

    protected function tearDown(): void
    {
        PlatformPolicy::flush();
        parent::tearDown();
    }

    #[Test]
    public function normalize_accepts_real_rules_and_rejects_catch_alls(): void
    {
        $this->assertSame(['spam.com', 'domain'], PlatformPolicy::normalize('@Spam.com'));
        $this->assertSame(['bad@x.com', 'email'], PlatformPolicy::normalize('BAD@x.com'));
        $this->assertSame(['bot*@*.xyz', 'pattern'], PlatformPolicy::normalize('bot*@*.xyz'));
        foreach (['*', '*@*', '*.*', 'nodomain', 'a b@x.com', 'http://x.com'] as $bad) {
            $this->assertNull(PlatformPolicy::normalize($bad), $bad);
        }
    }

    #[Test]
    public function block_list_allow_list_and_switch_work_together(): void
    {
        PlatformPolicy::addRules('block', ['spam.com', 'bad@x.com'], null, null);
        $this->assertSame('custom_block', DisposableEmail::diagnose('u@sub.spam.com', 'en', false)['code']);
        $this->assertNull(DisposableEmail::diagnose('u@notspam.com', 'en', false)['code']);

        PlatformPolicy::addRules('allow', ['vip@spam.com'], null, null);
        $this->assertTrue(DisposableEmail::diagnose('vip@spam.com', 'en', false)['ok']);

        PlatformPolicy::setFlag('email_block_enabled', false);
        $this->assertTrue(DisposableEmail::diagnose('u@spam.com', 'en', false)['ok']);
    }

    #[Test]
    public function admin_cannot_block_their_own_address(): void
    {
        $res = PlatformPolicy::addRules('block', ['*@school.edu'], null, null, ['admin@school.edu']);
        $this->assertSame([], $res['added']);
        $this->assertSame(['*@school.edu'], $res['protected']);
    }

    #[Test]
    public function switches_default_open_and_can_be_flipped(): void
    {
        $this->assertTrue(PlatformPolicy::registrationOpen());
        PlatformPolicy::setFlag('registration_enabled', false);
        $this->assertFalse(PlatformPolicy::registrationOpen());

        PlatformPolicy::setFlag('response_encryption', false);
        $this->assertFalse(PlatformPolicy::encryptionOn());
    }
}
