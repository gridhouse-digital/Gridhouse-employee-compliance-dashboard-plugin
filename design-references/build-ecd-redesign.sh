#!/bin/sh
# Rebuilds assets/ecd-redesign.css from the plugin head (fonts, reset), the
# design-canvas CSS (tokens + components, then the app layer) and the plugin
# adapter tail. Run from anywhere: sh design-references/build-ecd-redesign.sh
set -e
cd "$(dirname "$0")/.."
PY=python3
command -v python3 >/dev/null 2>&1 && python3 -c 'import sys' >/dev/null 2>&1 || PY=python
{
  cat design-references/ecd-redesign.head.css
  cat design-references/gh-tokens.css design-references/gh-components.css > design-references/.board.tmp.css
  $PY design-references/scope-board-css.py design-references/.board.tmp.css
  $PY design-references/scope-board-css.py --app design-references/gh-app.css
  $PY design-references/scope-board-css.py --guard-only design-references/ecd-redesign.tail.css
} > assets/ecd-redesign.css
rm -f design-references/.board.tmp.css
