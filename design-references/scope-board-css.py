"""Scope the design-canvas CSS (gh-tokens.css + gh-components.css v2.4.x and the
gh-app.css app layer) to this plugin's root wrapper, so it cannot leak into the
host theme and theme or page-builder rules cannot override it.

Adapted from the admin compliance plugin's build (same design system). The
employee plugin uses its own wrapper, `.ghca-ecd-ui`, so both plugins' CSS can
load on one page without either restyling the other.

Usage: python scope-board-css.py [--guard-only | --app] input.css > scoped.css

--app        the canvas app layer: drops prototype-only rules (page reset,
             prototype bar, docs page) and chrome this plugin never renders
             (the WordPress theme supplies the header and the phone nav).
--guard-only input is already scoped (the plugin adapter); only add the guard.
"""
import re
import sys

P = ':is(.ghca-ecd-ui, #ghca-ecd-ui-scope)'
# Board-only presentation classes (spec frames, focus demos, print sheets).
DROP = ('.gh-phone', '.gh-spec-', '.gh-focus-demo', '.gh-design-note',
        '.gh-bul--sheet', '.gh-bul--panel', '.gh-print', '.gh-live')
# Dropped only from the app layer: prototype scaffolding and chrome the theme owns.
APP_DROP = ('.app-protobar', '.app-doc', '.app-new', '.app-tabbar', '.gh-appbar', '.app-locked')
APP_DROP_EXACT = {'html', 'body', '#root'}
# Interactive components. Themes and page builders style links, link children
# and button hovers with !important, which plain rules cannot beat, so these
# properties are marked !important on every rule for them and on every rule
# that targets an `a` element.
GUARD = re.compile(r'\.gh-(qlink|btn|stat|certl|ticker-item|ticker-all|ann|tab|nav|logo|skip|table|pager-btns|linkbtn|bul-all|bul-x|bul-step|ecd-act|ecd-lock|ph-choose|ecd-avatar)(?![a-z0-9_])')
GUARD_A = re.compile(r'(?:^|[\s>+~(,])a(?=[.:\[\s,)>]|$)')
GUARD_PROPS = {'color', 'background', 'background-color', 'border-color',
               'text-decoration', 'text-decoration-line', 'text-decoration-color'}
# Themes also force heading typography, sometimes with !important.
HEADING = re.compile(r'\.gh-(?:h[1-4]|display|bul-h|bp-h|bl-h)(?![a-z0-9_-])')
HEADING_PROPS = {'font-family', 'text-transform', 'letter-spacing', 'font-style'}


def split_top(s, sep):
    out, depth, quote, cur = [], 0, None, ''
    for ch in s:
        if quote:
            cur += ch
            if ch == quote:
                quote = None
            continue
        if ch in '"\'':
            quote = ch
        elif ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == sep and depth == 0:
            out.append(cur)
            cur = ''
            continue
        cur += ch
    out.append(cur)
    return out


def guard(decl, extra=frozenset(), base=True):
    parts = []
    for d in split_top(decl, ';'):
        d = d.strip()
        if not d:
            continue
        name = d.split(':', 1)[0].strip().lower()
        if ((base and name in GUARD_PROPS) or name in extra) and '!important' not in d:
            d += ' !important'
        parts.append(d)
    return '; '.join(parts) + ';'


APP_MODE = False


def scope_selector(sel):
    sel = sel.strip()
    if any(d in sel for d in DROP):
        return None
    if APP_MODE and (sel in APP_DROP_EXACT or any(d in sel for d in APP_DROP)):
        return None
    if sel == ':root':
        return P + ' .gh-app'
    return P + ' ' + sel


def blocks(css):
    """Yield (prelude, body) for each top-level block, comments stripped."""
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    i, n = 0, len(css)
    while i < n:
        j = css.find('{', i)
        if j < 0:
            return
        prelude = css[i:j].strip()
        depth, k, quote = 1, j + 1, None
        while k < n and depth:
            ch = css[k]
            if quote:
                if ch == quote:
                    quote = None
            elif ch in '"\'':
                quote = ch
            elif ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
            k += 1
        yield prelude, css[j + 1:k - 1]
        i = k


def emit(css, indent='', rescope=True):
    out = []
    for prelude, body in blocks(css):
        if prelude.startswith('@keyframes'):
            out.append(indent + prelude + ' {' + ' '.join(body.split()) + '}')
            continue
        if prelude.startswith('@media') or prelude.startswith('@supports'):
            inner = emit(body, indent + '  ', rescope)
            if inner:
                out.append(indent + prelude + ' {')
                out.extend(inner)
                out.append(indent + '}')
            continue
        if prelude.startswith('@'):
            continue
        sels = [scope_selector(s) if rescope else s.strip() for s in split_top(prelude, ',')]
        sels = [s for s in sels if s]
        decl = ' '.join(body.split())
        if not sels or not decl:
            continue
        extra = HEADING_PROPS if HEADING.search(prelude) else frozenset()
        if GUARD.search(prelude) or GUARD_A.search(prelude):
            decl = guard(decl, extra)
        elif extra:
            decl = guard(decl, extra, base=False)
        out.append(indent + ', '.join(sels) + ' { ' + decl + ' }')
    return out


if __name__ == '__main__':
    rescope = sys.argv[1] != '--guard-only'
    APP_MODE = sys.argv[1] == '--app'
    with open(sys.argv[-1], encoding='utf-8') as fh:
        src = fh.read()
    sys.stdout.reconfigure(encoding='utf-8', newline='\n')
    sys.stdout.write('\n'.join(emit(src, '', rescope)) + '\n')
