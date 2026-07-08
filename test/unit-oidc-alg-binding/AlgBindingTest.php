<?php

namespace LucosTests\OidcAlgBinding;

// Standalone PHPUnit test for lucas42/lucos_worlds#29 (hardening follow-up to
// #26/#28): binds OIDC JWT signing-key *selection* to the token's declared
// "alg" header, rather than trying every available key regardless of type.
//
// This deliberately does NOT extend BookStack's own Tests\TestCase (which
// boots the full Laravel app / DB) — OidcJwtWithClaims/OidcJwtSigningKey are
// plain PHP with no framework dependency, so a bare PHPUnit\Framework\TestCase
// is sufficient and avoids needing a database or .env in CI. It's run
// directly against the real, patched image (see unit.Dockerfile /
// build-and-run.sh) — not a fork of BookStack's own tests/ suite, which
// exercises upstream's original RSA-only behaviour and would need its own
// (out of scope) updates to match this patch set's error messages.
//
// Key-generation approach: real RSA and EC(P-256) keypairs are generated at
// test-run time via the vendored phpseclib3 (same library the patched code
// itself uses), and JWTs are hand-built and signed with them — same
// verification-first approach used for the original ES256 patch (#28):
// exercise the actual patched verify() logic against real signed tokens, not
// mocks of it.

use BookStack\Access\Oidc\OidcIdToken;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

class AlgBindingTest extends TestCase
{
    protected const ISSUER = 'https://auth.example.com';
    protected const CLIENT_ID = 'client123';

    protected static ?array $rsaFixture = null;
    protected static ?array $ecFixture = null;

    /**
     * Lazily build (and cache for the test run) a real RSA keypair plus the
     * JWK array and signing closure derived from it.
     */
    protected static function rsaFixture(): array
    {
        if (self::$rsaFixture === null) {
            $key = RSA::createKey(2048);
            $pub = $key->getPublicKey();
            $jwk = json_decode($pub->toString('JWK'), true)['keys'][0];
            $jwk['alg'] = 'RS256';
            $jwk['use'] = 'sig';

            self::$rsaFixture = [
                'jwk' => $jwk,
                'pem' => $pub->toString('PKCS8'),
                'sign' => fn (string $content) => $key->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign($content),
            ];
        }

        return self::$rsaFixture;
    }

    /**
     * Lazily build (and cache for the test run) a real EC(P-256) keypair plus
     * the JWK array and signing closure derived from it.
     */
    protected static function ecFixture(): array
    {
        if (self::$ecFixture === null) {
            $key = EC::createKey('secp256r1');
            $pub = $key->getPublicKey();
            $jwk = json_decode($pub->toString('JWK'), true)['keys'][0];
            $jwk['alg'] = 'ES256';
            $jwk['use'] = 'sig';

            self::$ecFixture = [
                'jwk' => $jwk,
                'pem' => $pub->toString('PKCS8'),
                'sign' => fn (string $content) => $key->withSignatureFormat('IEEE')->withHash('sha256')->sign($content),
            ];
        }

        return self::$ecFixture;
    }

    protected static function base64UrlEncode(string $decoded): string
    {
        return rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
    }

    /**
     * Hand-build and sign a JWT with the given alg header and signing closure.
     */
    protected static function buildToken(string $alg, callable $sign, array $payloadOverrides = []): string
    {
        $header = ['kid' => 'test-key', 'alg' => $alg];
        $payload = array_merge([
            'sub' => 'test-subject',
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 720,
        ], $payloadOverrides);

        $signingInput = self::base64UrlEncode(json_encode($header)) . '.' . self::base64UrlEncode(json_encode($payload));
        $signature = $sign($signingInput);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Write a PEM string to a throwaway file and return its file:// path.
     * Caller is responsible for unlinking once done.
     */
    protected static function writePemFile(string $pem): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oidc-alg-binding-test-');
        file_put_contents($path, $pem);

        return $path;
    }

    public function test_es256_token_validates_against_correct_key_in_a_mixed_jwk_keyset()
    {
        $es = self::ecFixture();
        $rsa = self::rsaFixture();
        $token = self::buildToken('ES256', $es['sign']);

        $idToken = new OidcIdToken($token, self::ISSUER, [$rsa['jwk'], $es['jwk']]);
        $this->assertTrue($idToken->validate(self::CLIENT_ID));
    }

    public function test_rs256_token_validates_against_correct_key_in_a_mixed_jwk_keyset()
    {
        $es = self::ecFixture();
        $rsa = self::rsaFixture();
        $token = self::buildToken('RS256', $rsa['sign']);

        $idToken = new OidcIdToken($token, self::ISSUER, [$rsa['jwk'], $es['jwk']]);
        $this->assertTrue($idToken->validate(self::CLIENT_ID));
    }

    /**
     * This is the core regression/resilience test for the fix. Before #29, key
     * selection was unconditional: OidcJwtWithClaims::validateTokenSignature()
     * tried to construct an OidcJwtSigningKey for *every* entry in $this->keys
     * regardless of the token's declared alg. A malformed or unsupported
     * same-shape entry that happens not to be the alg the token actually
     * declares would still blow up construction and break verification for
     * every token, any alg. Confirmed via git-stash before writing this test:
     * pre-#29 this scenario throws "Failed to read signing key with error: A
     * "n" parameter on the provided key is expected" instead of the correct
     * "Token signature could not be validated using the provided keys".
     *
     * Post-#29, the broken RSA entry is excluded from candidacy before
     * construction is even attempted (the token declares ES256, and the only
     * available key is kty=RSA), so verification fails cleanly instead of
     * crashing on the unrelated key's malformed data.
     */
    public function test_es256_token_rejected_cleanly_when_only_a_broken_non_matching_alg_key_is_present()
    {
        $es = self::ecFixture();
        $token = self::buildToken('ES256', $es['sign']);

        // Deliberately missing the required "n" parameter for an RSA JWK.
        $brokenRsaJwk = ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'e' => 'AQAB'];

        $idToken = new OidcIdToken($token, self::ISSUER, [$brokenRsaJwk]);

        $this->expectException(\BookStack\Access\Oidc\OidcInvalidTokenException::class);
        $this->expectExceptionMessage('Token signature could not be validated using the provided keys');
        $idToken->validate(self::CLIENT_ID);
    }

    /**
     * Regression guard for the "naive fix" pitfall called out in #29: a plain
     * `$key['kty']` pre-filter run across both key shapes silently drops every
     * file:// string entry (PHP array-access on a string only warns and
     * returns null), which would make this file:// RSA path start failing
     * with no visible error. This proves the RS256/file:// path still works.
     */
    public function test_rs256_token_validates_against_file_reference_rsa_key()
    {
        $rsa = self::rsaFixture();
        $token = self::buildToken('RS256', $rsa['sign']);
        $path = self::writePemFile($rsa['pem']);

        try {
            $idToken = new OidcIdToken($token, self::ISSUER, ['file://' . $path]);
            $this->assertTrue($idToken->validate(self::CLIENT_ID));
        } finally {
            unlink($path);
        }
    }

    /**
     * New coverage for the file:// + EC combination — untested before #29 and
     * still dead code in lucos_worlds' actual deployed config (OIDC discovery
     * is always on), but the acceptance criteria for #29 requires both shapes
     * to be covered for both algs, since this is exactly the path a config
     * change could make live with no further code change.
     */
    public function test_es256_token_validates_against_file_reference_ec_key()
    {
        $es = self::ecFixture();
        $token = self::buildToken('ES256', $es['sign']);
        $path = self::writePemFile($es['pem']);

        try {
            $idToken = new OidcIdToken($token, self::ISSUER, ['file://' . $path]);
            $this->assertTrue($idToken->validate(self::CLIENT_ID));
        } finally {
            unlink($path);
        }
    }

    /**
     * The core test for the file:// shape specifically: its real key type
     * isn't knowable until OidcJwtSigningKey loads it (unlike a JWK array,
     * where "kty" is visible upfront), so the only way to bind key selection
     * to the declared alg for this shape is the post-construction alg()
     * check. Without it, this scenario wouldn't crash (RSA/EC verification
     * can't cross-confuse — see the module docblock), but it also wouldn't be
     * alg-bound, which is the whole point of #29.
     */
    public function test_es256_token_rejected_when_only_a_wrong_type_file_reference_key_is_present()
    {
        $rsa = self::rsaFixture();
        $es = self::ecFixture();
        $token = self::buildToken('ES256', $es['sign']);
        $path = self::writePemFile($rsa['pem']);

        try {
            $idToken = new OidcIdToken($token, self::ISSUER, ['file://' . $path]);

            $this->expectException(\BookStack\Access\Oidc\OidcInvalidTokenException::class);
            $this->expectExceptionMessage('Token signature could not be validated using the provided keys');
            $idToken->validate(self::CLIENT_ID);
        } finally {
            unlink($path);
        }
    }
}
