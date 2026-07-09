<?php

/**
 * lucos_worlds theme functions.
 *
 * Registers the public, unauthenticated `/_info` endpoint required by the
 * estate-wide lucos monitoring + homepage convention (lucas42/lucos_worlds#6)
 * via BookStack's documented logical-theme route-registration hook (see
 * dev/docs/logical-theme-system.md in the upstream BookStack repo, and
 * BookStack's own tests/Theme/LogicalThemeEventsTest.php for the exact usage
 * this mirrors).
 *
 * ROUTES_REGISTER_WEB (not ROUTES_REGISTER_WEB_AUTH) is deliberate: verified
 * against app/App/Providers/RouteServiceProvider.php in the pinned
 * linuxserver/bookstack:26.05.2 image, it's dispatched inside the
 * `web`-only middleware group — i.e. public/unauthenticated, exactly like
 * BookStack's own `/status` route (routes/web.php). Registering on
 * ROUTES_REGISTER_WEB_AUTH by mistake would put `/_info` behind login, and
 * both lucos_monitoring and lucos_root would get redirected to /login
 * instead of a JSON payload.
 *
 * This file is loaded automatically by ThemeService::readThemeActions() on
 * every app boot, same as the rest of this theme (see
 * custom-cont-init.d/10-copy-theme.sh for how theme/lucos ends up on disk in
 * the running container).
 */

use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use Illuminate\Routing\Router;

require __DIR__ . '/InfoController.php';

Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB, function (Router $router) {
    $router->get('/_info', [\LucosTheme\InfoController::class, 'show']);
});
