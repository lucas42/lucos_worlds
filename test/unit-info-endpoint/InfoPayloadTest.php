<?php

namespace LucosTests\InfoEndpoint;

// Standalone PHPUnit test for lucas42/lucos_worlds#6: covers
// InfoController::buildPayload(), the pure mapping from BookStack's
// database/cache/session health booleans to the lucos `/_info` schema.
//
// Deliberately does NOT boot the Laravel app or a database — buildPayload()
// has no framework dependency (unlike InfoController::checkDependencies(),
// which does the actual Cache/DB/Session round-trips and is exercised
// end-to-end, against the real running app, by the extended
// test/oidc-es256/ integration harness instead). Same split rationale as
// test/unit-oidc-alg-binding/AlgBindingTest.php: a bare
// PHPUnit\Framework\TestCase is sufficient for pure logic, and avoids
// needing a database or .env in CI for it.
//
// Run against the real, shipped file (not a copy) — see unit.Dockerfile,
// which builds the actual production Dockerfile and requires
// /theme-source/lucos/InfoController.php straight out of the built image.

use PHPUnit\Framework\TestCase;

require_once '/theme-source/lucos/InfoController.php';

use LucosTheme\InfoController;

class InfoPayloadTest extends TestCase
{
    public function test_all_healthy_reports_ok_with_no_debug_field()
    {
        $payload = InfoController::buildPayload(['database' => true, 'cache' => true, 'session' => true]);

        $this->assertSame('lucos_worlds', $payload['system']);
        $this->assertTrue($payload['checks']['bookstack']['ok']);
        $this->assertArrayNotHasKey('debug', $payload['checks']['bookstack']);
        // Must be an empty object (stdClass), not a bare [] -- a bare []
        // json_encodes as a JSON array, not {}, which the /_info spec
        // requires. See the comment on buildPayload() for why.
        $this->assertEquals(new \stdClass(), $payload['metrics']);
        $this->assertJsonStringEqualsJsonString('{}', json_encode($payload['metrics']));
        $this->assertSame('Worlds', $payload['title']);
        $this->assertSame('/theme/lucos/img/logo.png', $payload['icon']);
        $this->assertTrue($payload['show_on_homepage']);
        $this->assertTrue($payload['network_only']);
        $this->assertSame('/', $payload['start_url']);
    }

    public function test_single_dependency_down_reports_unhealthy_and_names_it_in_debug()
    {
        $payload = InfoController::buildPayload(['database' => true, 'cache' => false, 'session' => true]);

        $this->assertFalse($payload['checks']['bookstack']['ok']);
        $this->assertStringContainsString('cache', $payload['checks']['bookstack']['debug']);
        $this->assertStringNotContainsString('database', $payload['checks']['bookstack']['debug']);
        $this->assertStringNotContainsString('session', $payload['checks']['bookstack']['debug']);
    }

    public function test_all_dependencies_down_names_all_three_in_debug()
    {
        $payload = InfoController::buildPayload(['database' => false, 'cache' => false, 'session' => false]);

        $this->assertFalse($payload['checks']['bookstack']['ok']);
        $this->assertStringContainsString('database', $payload['checks']['bookstack']['debug']);
        $this->assertStringContainsString('cache', $payload['checks']['bookstack']['debug']);
        $this->assertStringContainsString('session', $payload['checks']['bookstack']['debug']);
    }

    /**
     * Guards the deliberate design choice flagged on the issue: /_info must
     * always be reportable as HTTP 200 by the caller (InfoController::show())
     * regardless of health -- buildPayload() itself never encodes a non-200
     * status, that decision is fixed at the show() call site. This test
     * can't assert an HTTP status (no framework booted), but it does assert
     * that an unhealthy payload is still a normal, well-formed array rather
     * than an exception or error marker -- i.e. nothing about this mapping
     * function itself signals "the API is broken".
     */
    public function test_unhealthy_payload_is_still_well_formed_not_an_error_shape()
    {
        $payload = InfoController::buildPayload(['database' => false, 'cache' => true, 'session' => true]);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('system', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('metrics', $payload);
    }
}
