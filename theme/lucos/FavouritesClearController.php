<?php

namespace LucosTheme;

use BookStack\Activity\Models\Favourite;
use Illuminate\Http\RedirectResponse;

/**
 * Bulk-clears all of the signed-in user's favourites in one action
 * (lucas42/lucos_worlds#59). BookStack's native /favourites/remove route
 * only takes one item at a time and there's no favourites API, so a
 * one-tap "start each session from empty" reset needs this extra route.
 *
 * Plain class, not a BookStack\Http\Controller subclass -- same reasoning
 * as InfoController.php: theme files aren't part of BookStack's PSR-4
 * autoload map, so this stays on framework facades/helpers only.
 */
class FavouritesClearController
{
    public function clearAll(): RedirectResponse
    {
        // Scoped to the signed-in user -- never a bare
        // Favourite::query()->delete(). There's one user today, but aithne
        // RBAC already exists (see lucos_worlds ADR-0003) and lucas42 may
        // run sessions as DM in future; an unscoped delete would then wipe
        // every user's favourites.
        Favourite::where('user_id', user()->id)->delete();

        // No favourites activity log exists to recover from -- see the
        // confirmation dialog in layouts/parts/base-body-end.blade.php,
        // which is the only safeguard against an accidental clear.
        session()->flash('success', 'All favourites cleared.');

        return redirect('/favourites');
    }
}
