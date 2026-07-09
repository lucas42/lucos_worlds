<?php

namespace LucosTheme;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Serves the estate-wide lucos `/_info` payload for lucos_worlds (an adopted
 * BookStack instance) — see lucas42/lucos_worlds#6 and the spec at
 * ~/.claude/references/info-endpoint-spec.md in the agent config repo.
 *
 * BookStack doesn't speak this schema natively, so this maps its own
 * dependency-health signal into it. The database/cache/session checks below
 * are BookStack's own app/Settings/StatusController.php logic (verified
 * against the pinned linuxserver/bookstack:26.05.2 image), reused verbatim
 * rather than reinvented — that's the same signal backing BookStack's public
 * `/status` route, already proven in production. `checkDependencies()` is
 * deliberately kept separate from `buildPayload()` (a pure function) so the
 * mapping logic can be unit-tested without booting the Laravel app — see
 * test/unit-info-endpoint/.
 *
 * This is a plain class, not a BookStack\Http\Controller subclass: theme
 * files aren't part of BookStack's PSR-4 autoload map (only `functions.php`
 * is auto-required — see ThemeService::readThemeActions()), so this stays on
 * framework facades only (available anywhere once the app has booted, which
 * it always has by request time) rather than app internals that assume
 * controller-base wiring.
 */
class InfoController
{
    public function show()
    {
        // Always 200: monitoring distinguishes a genuine API failure (5xx)
        // from a reported-but-unhealthy dependency (`ok:false` inside
        // `checks`). Mirroring BookStack's own /status 500-on-failure
        // convention here would turn a real DB/cache/session outage into a
        // false "lucos_worlds' /_info API is broken" monitoring alert
        // instead of an accurate "bookstack dependency unhealthy" one.
        return response()->json(self::buildPayload($this->checkDependencies()), 200);
    }

    /**
     * @return array<string, bool>
     */
    public function checkDependencies(): array
    {
        return [
            'database' => $this->trueWithoutError(function () {
                return DB::table('migrations')->count() > 0;
            }),
            'cache' => $this->trueWithoutError(function () {
                $rand = Str::random(12);
                $key = "status_test_{$rand}";
                Cache::add($key, $rand);

                return Cache::pull($key) === $rand;
            }),
            'session' => $this->trueWithoutError(function () {
                $rand = Str::random();
                Session::put('status_test', $rand);

                return Session::get('status_test') === $rand;
            }),
        ];
    }

    /**
     * Check the callable passed returns true and does not throw an
     * exception — same shape as BookStack's own StatusController so a
     * dependency failure here is reported the same way BookStack's own
     * `/status` route already reports it, not a new failure mode.
     */
    private function trueWithoutError(callable $test): bool
    {
        try {
            return $test() === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pure mapping from BookStack's dependency-check booleans to the lucos
     * `/_info` schema. No framework dependency — this is what
     * test/unit-info-endpoint/ exercises directly, covering every
     * healthy/unhealthy combination without needing a database.
     *
     * @param array<string, bool> $statuses e.g. ['database'=>true, 'cache'=>true, 'session'=>false]
     */
    public static function buildPayload(array $statuses): array
    {
        $failed = array_keys(array_filter($statuses, fn ($ok) => !$ok));
        $ok = empty($failed);

        $check = [
            'ok' => $ok,
            'techDetail' => 'BookStack app + dependencies reachable (database, cache, session)',
        ];
        if (!$ok) {
            $check['debug'] = 'unhealthy: ' . implode(', ', $failed);
        }

        return [
            'system' => 'lucos_worlds',
            'checks' => ['bookstack' => $check],
            // A bare `[]` json_encodes as `[]`, not `{}` -- PHP has no
            // native distinction between an empty list and an empty map.
            // Force the object shape the /_info spec requires (caught by
            // test/oidc-es256's live /_info assertion: it was serializing
            // as a JSON array before this).
            'metrics' => new \stdClass(),
            'title' => 'Worlds',
            // Same path BookStack's own theme-asset route serves the logo
            // from — already proven to 200 in production (it's what the
            // branding script sets as `app-logo`; see
            // custom-cont-init.d/20-set-branding.sh).
            'icon' => '/theme/lucos/img/logo.png',
            'show_on_homepage' => true,
            // BookStack needs the network — no offline service worker.
            'network_only' => true,
            'start_url' => '/',
        ];
    }
}
