{{--
  lucos_worlds custom-head override.

  Wires up the injection mechanism for lucas42/lucos_worlds#2: this file
  overrides BookStack's core view of the same relative path
  (resources/views/layouts/parts/custom-head.blade.php) via the logical
  theme system (APP_THEME=lucos), so BookStack looks here first.

  The stylesheet itself is a deliberate placeholder — the actual "fantasy"
  styling (parchment background, cursive headings, etc.) is scoped to
  lucas42/lucos_worlds#7, not this issue.
--}}
<link rel="stylesheet" href="/theme/lucos/css/custom.css">
