{{--
  lucos_worlds dark-mode-toggle override — removes the toggle entirely
  (lucas42/lucos_worlds#61, Option B of the architect's assessment there).

  Dark mode was found to render page headings near-unreadable against the
  parchment reading card (~1.7:1 contrast; BookStack's own upstream h1-h6
  colour flips light in dark mode, but the theme deliberately keeps that
  card, and the desk background behind it, identical in both modes per
  lucas42/lucos_worlds#42 — headings are the one element on that card whose
  foreground flips with no background of its own). lucas42 doesn't use dark
  mode, so rather than carry a second rendering mode's worth of upkeep
  across future BookStack upgrades for a feature with no users, the toggle
  is removed instead of patched.

  This overrides BookStack's own resources/views/common/dark-mode-toggle.
  blade.php via the logical theme system (APP_THEME=lucos) — see
  app/Theming/ThemeViews.php's registerViewPathsForTheme(), which prepends
  theme_path() to the Blade view finder, so this file is found first.
  Deliberately left EMPTY rather than deleted: BookStack always @includes
  this view by relative path from five call sites (both home-view variants,
  book/shelf/page action panels, and the header user menu) with no
  conditional guard, so a missing file would throw rather than degrade —
  emptying it is what actually removes the toggle from every one of those
  five places at once, with no per-call-site changes needed.

  Confirmed (not assumed) that this doesn't relegate anyone to a state with
  no way out: no `prefers-color-scheme` exists anywhere in BookStack's own
  source, and the sole write path to the persisted preference is the route
  this partial's <form> posts to, PATCH /preferences/toggle-dark-mode — so
  removing the affordance is sufficient on its own to stop anyone from
  entering dark mode. That's not quite the same as never being IN it,
  though: the preference is stored per-user, and lucas42 was in dark mode
  when he confirmed this bug — so the companion half of this change,
  custom-cont-init.d/40-clear-dark-mode-preference.sh, clears everyone's
  existing preference on container start so nobody is stranded there. Both
  halves ship together; neither is safe alone.

  Two small, deliberate cosmetic side-effects from this file rendering
  empty, tidied in the theme's own CSS rather than by touching BookStack's
  markup: the header user-menu's dark-mode <li> sits between two <hr>
  separators and would otherwise become a redundant empty gap, and the
  homepage's dark-mode-only .icon-list wrapper would otherwise become a
  content-less box. See the "Removed dark-mode toggle" tidy-up section in
  public/css/custom.css.

  Honest limit, not fixed here: this removes the affordance, not the
  route. PATCH /preferences/toggle-dark-mode is still registered and would
  still work if called directly (e.g. by hand-crafted request) — see the
  #61 assessment for why a genuine server-side lock would need a BookStack
  patch, and why that wasn't judged worth it on a single-user private
  system where the only way in is now gone.
--}}
