{{--
  lucos_worlds "clear all favourites" button (lucas42/lucos_worlds#59).

  Overrides BookStack's layouts/parts/base-body-end.blade.php (upstream is
  just a placeholder comment) via the logical theme system, same mechanism
  as theme/lucos/layouts/parts/custom-head.blade.php. This partial is
  included right at the end of every page's <body>, after all page
  content, so gating it to /favourites and leaving it here (rather than
  moving it into the page content itself) satisfies two requirements at
  once: the button appears only on /favourites, and it renders below the
  favourites list rather than adjacent to the entries being tapped during
  normal use.

  A confirm() prompt is the only safeguard against an accidental clear --
  there's no favourites activity log to recover from (see
  FavouritesClearController.php) and this button lives on the page tapped
  most often during a session, from a tablet.

  The confirm() call is wired via a nonce'd <script> block, not an
  `onsubmit="..."` attribute -- BookStack's CSP (script-src with
  'strict-dynamic') blocks inline event-handler attributes outright, nonce
  or not; only nonce'd <script> elements are trusted. $cspNonce is shared
  with every view by ApplyCspRules (the 'web' middleware group), same
  mechanism BookStack's own inline scripts use (see
  auth/login-initiate.blade.php upstream).
--}}
@if(request()->is('favourites'))
    <div class="container small text-center py-l">
        <form id="lucos-clear-favourites-form" action="/favourites/clear-all" method="POST">
            @csrf
            <button type="submit" class="button outline">Clear all favourites</button>
        </form>
    </div>
    <script nonce="{{ $cspNonce }}">
        document.getElementById('lucos-clear-favourites-form').addEventListener('submit', function (e) {
            if (!confirm('Clear all favourites? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    </script>
@endif
