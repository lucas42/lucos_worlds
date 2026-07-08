<?php

namespace BookStack\Access\Oidc;

use phpseclib3\Crypt\Common\PublicKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

// lucos patch (lucas42/lucos_worlds#26, decision in #21): adds EC/ES256 support
// alongside the existing RSA/RS256 path, to support lucos_aithne, which signs
// exclusively with ES256 and publishes no RSA key. Upstream BookStack is
// RSA/RS256-only by design (see BookStackApp/BookStack#5390, open/unresolved
// as of 2026-07). Must stay in sync with the companion patches in
// OidcProviderSettings::filterKeys() and OidcJwtWithClaims::validateTokenSignature()
// — all three independently gate the same RSA-only restriction upstream.
//
// The EC branch loads the key via phpseclib3's own JWK format support
// (Crypt\EC\Formats\Keys\JWK — auto-detected by PublicKeyLoader from a JSON
// string), and sets the "IEEE" (raw R||S / IEEE P1363) signature format,
// since that's the format JWT ES256 signatures use — as opposed to the
// ASN.1 DER format phpseclib3's EC class defaults to. Verified against a
// real ES256-signed test token (valid signature verifies true, a tampered
// one verifies false) before this patch was applied.
class OidcJwtSigningKey
{
    protected PublicKey $key;

    /**
     * Can be created either from a JWK parameter array or local file path to load a certificate from.
     * Examples:
     * 'file:///var/www/cert.pem'
     * ['kty' => 'RSA', 'alg' => 'RS256', 'n' => 'abc123...'].
     * ['kty' => 'EC', 'alg' => 'ES256', 'crv' => 'P-256', 'x' => 'abc123...', 'y' => 'def456...'].
     *
     * @throws OidcInvalidKeyException
     */
    public function __construct(array|string $jwkOrKeyPath)
    {
        if (is_array($jwkOrKeyPath)) {
            $this->loadFromJwkArray($jwkOrKeyPath);
        } elseif (str_starts_with($jwkOrKeyPath, 'file://')) {
            $this->loadFromPath($jwkOrKeyPath);
        } else {
            throw new OidcInvalidKeyException('Unexpected type of key value provided');
        }
    }

    /**
     * @throws OidcInvalidKeyException
     */
    protected function loadFromPath(string $path): void
    {
        try {
            $key = PublicKeyLoader::load(
                file_get_contents($path)
            );
        } catch (\Exception $exception) {
            throw new OidcInvalidKeyException("Failed to load key from file path with error: {$exception->getMessage()}");
        }

        if ($key instanceof EC) {
            // lucos-security review finding (lucas42/lucos_worlds#28): the JWK-array
            // path (loadEcFromJwkArray()) restricts to P-256, but this path — the
            // manual OIDC_PUBLIC_KEY/file:// config route BookStack itself
            // documents as an alternative to discovery — did not. A PEM file could
            // contain any EC curve phpseclib3 supports (e.g. secp384r1) and it
            // would silently be accepted with no restriction. Currently dead code
            // in lucos_worlds (OIDC_ISSUER_DISCOVER=true is always set, so this
            // branch never executes), but it's one env var away from being live —
            // keep both EC-loading paths equally restricted, same as upstream
            // keeps both RSA-loading paths equally restricted to RS256.
            if ($key->getCurve() !== 'secp256r1') {
                throw new OidcInvalidKeyException("Only the P-256 curve is currently supported for EC keys. Found curve {$key->getCurve()}");
            }

            $this->key = $key->withSignatureFormat('IEEE');

            return;
        }

        if (!$key instanceof RSA) {
            throw new OidcInvalidKeyException('Key loaded from file path is not an RSA or EC key as expected');
        }

        $this->key = $key->withPadding(RSA::SIGNATURE_PKCS1);
    }

    /**
     * @throws OidcInvalidKeyException
     */
    protected function loadFromJwkArray(array $jwk): void
    {
        // 'alg' is optional for a JWK, but we will still attempt to validate if
        // it exists otherwise presume it will be compatible.
        $alg = $jwk['alg'] ?? null;

        // 'use' is optional for a JWK but we assume 'sig' where no value exists since that's what
        // the OIDC discovery spec infers since 'sig' MUST be set if encryption keys come into play.
        $use = $jwk['use'] ?? 'sig';
        if ($use !== 'sig') {
            throw new OidcInvalidKeyException("Only signature keys are currently supported. Found key for use {$jwk['use']}");
        }

        if ($jwk['kty'] === 'EC' && (is_null($alg) || $alg === 'ES256')) {
            $this->loadEcFromJwkArray($jwk);

            return;
        }

        if ($jwk['kty'] !== 'RSA' || !(is_null($alg) || $alg === 'RS256')) {
            throw new OidcInvalidKeyException("Only RS256 and ES256 keys are currently supported. Found key using {$alg}");
        }

        if (empty($jwk['e'])) {
            throw new OidcInvalidKeyException('An "e" parameter on the provided key is expected');
        }

        if (empty($jwk['n'])) {
            throw new OidcInvalidKeyException('A "n" parameter on the provided key is expected');
        }

        $n = strtr($jwk['n'], '-_', '+/');

        try {
            $key = PublicKeyLoader::load([
                'e' => new BigInteger(base64_decode($jwk['e']), 256),
                'n' => new BigInteger(base64_decode($n), 256),
            ]);
        } catch (\Exception $exception) {
            throw new OidcInvalidKeyException("Failed to load key from JWK parameters with error: {$exception->getMessage()}");
        }

        if (!$key instanceof RSA) {
            throw new OidcInvalidKeyException('Key loaded from file path is not an RSA key as expected');
        }

        $this->key = $key->withPadding(RSA::SIGNATURE_PKCS1);
    }

    /**
     * @throws OidcInvalidKeyException
     */
    protected function loadEcFromJwkArray(array $jwk): void
    {
        if (($jwk['crv'] ?? null) !== 'P-256') {
            throw new OidcInvalidKeyException("Only the P-256 curve is currently supported for EC keys. Found curve {$jwk['crv']}");
        }

        if (empty($jwk['x']) || empty($jwk['y'])) {
            throw new OidcInvalidKeyException('"x" and "y" parameters on the provided EC key are expected');
        }

        try {
            // phpseclib3's PublicKeyLoader auto-detects the JWK format from a JSON
            // string (it does not accept a raw PHP array for EC keys the way the
            // RSA e/n path above does), so re-encode the already-parsed JWK array.
            $key = PublicKeyLoader::load(json_encode($jwk));
        } catch (\Exception $exception) {
            throw new OidcInvalidKeyException("Failed to load EC key from JWK parameters with error: {$exception->getMessage()}");
        }

        if (!$key instanceof EC) {
            throw new OidcInvalidKeyException('Key loaded from JWK parameters is not an EC key as expected');
        }

        // JWT ES256 signatures are raw R||S concatenated (IEEE P1363) format, not
        // the ASN.1 DER format phpseclib3's EC class defaults to.
        $this->key = $key->withSignatureFormat('IEEE');
    }

    /**
     * Use this key to sign the given content and return the signature.
     */
    public function verify(string $content, string $signature): bool
    {
        return $this->key->verify($content, $signature);
    }

    /**
     * Convert the key to a PEM encoded key string.
     */
    public function toPem(): string
    {
        return $this->key->toString('PKCS8');
    }
}
