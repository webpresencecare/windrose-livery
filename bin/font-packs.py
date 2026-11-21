#!/usr/bin/env python3
"""Builds Windrose Livery's font packs: one zip per script, with the fonts of Style Mermaid's pairs for that script
(`assets/mermaid/scripts.json`, pairs and language fonts), cut down to that script.

Source: the google/fonts repository on GitHub at one pinned commit (`bin/font-sources.json`), SIL Open Font License
files only. No request to the Google Fonts or Bunny Fonts services.

    bin/font-packs.py sources [commit]   list each family's files at a commit (GitHub API) into bin/font-sources.json
    bin/font-packs.py build <out-dir>    download, cut and pack: <out-dir>/windrose-livery-fonts-<script>.zip,
                                         and assets/mermaid/packs.json (what the plugin shows before installing)
    bin/font-packs.py same <file>        exit 1 when assets/mermaid/packs.json and <file> differ in more than sizes
                                         (the release checks that the packs it publishes are the ones committed)

`build` needs fontTools and brotli (`pip install fonttools brotli`; versions in .github/workflows/release.yml).
Set GITHUB_TOKEN for `sources` to avoid the API's limit for anonymous requests.

Each zip: `windrose-livery-fonts-<script>/pack.json`, then `<family slug>/<file>.woff2` and `<family slug>/OFL.txt`.
Variable fonts keep their weight axis (other axes fixed at their default); static families keep 400 to 700. Each face
gets a `unicodeRange` with the script's own characters (joiners and the dotted circle stay in the file but not in the
range, so emoji sequences on a Latin page never download a script font), and a `sizeAdjust` when the script looks
smaller than Latin text at the same size (measured: the height of a few letters against the average x-height of the
Latin fonts bundled with the plugin, from 100% to 130%, in steps of 5%). Arabic is compared with 90% of that x-height:
Noto Sans Arabic, drawn to sit next to Noto Sans, then gets 115%, and the other Arabic fonts the size that matches it.

FONT_PACKS_CACHE=<folder> keeps the downloaded google/fonts files between builds.
"""

from __future__ import annotations

import io
import json
import os
import re
import sys
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPTS = ROOT / 'assets' / 'mermaid' / 'scripts.json'
SOURCES = ROOT / 'bin' / 'font-sources.json'
PACKS = ROOT / 'assets' / 'mermaid' / 'packs.json'
BUNDLED = ROOT / 'assets' / 'mermaid' / 'fonts.json'
PREFIX = 'windrose-livery-fonts-'
FORMAT = 1
ZIP_TIME = (2026, 1, 1, 0, 0, 0)


def ranges(text: str) -> list[tuple[int, int]]:
    out = []
    for part in text.split(','):
        part = part.strip()
        if '-' in part:
            a, b = part.split('-')
            out.append((int(a, 16), int(b, 16)))
        elif part:
            out.append((int(part, 16), int(part, 16)))
    return out


# The script's own characters (listed in unicodeRange) and helpers kept in the file only.
SCRIPT_RANGES = {
    'cyrillic-greek': '0370-03FF, 1F00-1FFF, 0400-052F, 1C80-1C8F, 2DE0-2DFF, A640-A69F, 2116',
    'arabic': '0600-06FF, 0750-077F, 0870-089F, 08A0-08FF, FB50-FDFF, FE70-FEFF',
    'hebrew': '0590-05FF, FB1D-FB4F',
    'devanagari': '0900-097F, 1CD0-1CFF, A8E0-A8FF',
    'thai': '0E00-0E7F',
    'bengali': '0980-09FF, 0964-0965',
    'tamil': '0B80-0BFF, 11FC0-11FFF, 0964-0965',
    'gujarati': '0A80-0AFF, 0964-0965',
    'gurmukhi': '0A00-0A7F, 0964-0965',
    'georgian': '10A0-10FF, 1C90-1CBF, 2D00-2D2F',
    'armenian': '0530-058F, FB13-FB17',
    'ethiopic': '1200-139F, 2D80-2DDF, AB00-AB2F, 1E7E0-1E7FF',
}
HELPERS = '0020, 00A0, 200C-200F, 2010-2011, 25CC'

# Letters whose height is compared with Latin x-height.
MEASURE = {
    'cyrillic-greek': '0445, 043E, 03BF',
    'arabic': '0647, 0645, 0635, 0648',
    'hebrew': '05D0, 05D1, 05DE, 05E9',
    'devanagari': '0915, 0928, 092E',
    'thai': '0E01, 0E19, 0E21',
    'bengali': '0995, 09A8, 09AE',
    'tamil': '0B95, 0BA8, 0BAE',
    'gujarati': '0A95, 0AA8, 0AAE',
    'gurmukhi': '0A15, 0A28, 0A2E',
    'georgian': '10D0, 10D4, 10DD',
    'armenian': '0561, 0578, 057D',
    'ethiopic': '1200, 1208, 1218',
}

# Share of the Latin x-height a script's letters are matched to (others: 1.0).
FACTOR = {'arabic': 0.9}

STATIC_WEIGHTS = {'Regular': '400', 'Medium': '500', 'SemiBold': '600', 'Bold': '700'}
GENERIC = {'SERIF': 'serif', 'SANS_SERIF': 'sans-serif', 'DISPLAY': 'sans-serif', 'HANDWRITING': 'cursive', 'MONOSPACE': 'monospace'}


def slugify(name: str) -> str:
    return re.sub(r'[^a-z0-9]+', '-', name.lower()).strip('-')


def families_by_script() -> dict[str, list[str]]:
    out = {}
    for script in json.loads(SCRIPTS.read_text()):
        if script.get('system'):
            continue
        names = []
        for pair in script.get('pairs', []):
            for role in ('heading', 'text'):
                if pair[role] not in names:
                    names.append(pair[role])
        for language in script.get('languages', []):
            for name in language['fonts']:
                if name not in names:
                    names.append(name)
        out[script['slug']] = names
    return out


def fetch(url: str, api: bool = False) -> bytes:
    headers = {'User-Agent': 'windrose-livery-font-packs'}
    token = os.environ.get('GITHUB_TOKEN', '')
    if api and token:
        headers['Authorization'] = 'Bearer ' + token
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=120) as response:
        return response.read()


def cmd_sources(commit: str | None) -> int:
    if not commit:
        commit = json.loads(fetch('https://api.github.com/repos/google/fonts/commits/main', True))['sha']
    families = {}
    for names in families_by_script().values():
        for name in names:
            if name in families:
                continue
            folder = re.sub(r'[^a-z0-9]', '', name.lower())
            listing = None
            for licence in ('ofl', 'apache', 'ufl'):
                try:
                    listing = json.loads(fetch(f'https://api.github.com/repos/google/fonts/contents/{licence}/{folder}?ref={commit}', True))
                    break
                except Exception:  # noqa: BLE001 - 404 for the other licence folders.
                    continue
            if listing is None:
                print(f'Not found in google/fonts: {name}', file=sys.stderr)
                return 1
            files = sorted(item['name'] for item in listing if item['name'].endswith('.ttf'))
            licence_file = next((item['name'] for item in listing if item['name'] in ('OFL.txt', 'LICENSE.txt', 'UFL.txt')), '')
            families[name] = {'dir': f'{licence}/{folder}', 'files': files, 'license': licence_file}
            print(f'{name}: {licence}/{folder} {files}')
    SOURCES.write_text(json.dumps({'commit': commit, 'families': families}, indent='\t', ensure_ascii=False) + '\n')
    print(f'Wrote {SOURCES.relative_to(ROOT)} at google/fonts {commit}')
    return 0


def pick_files(files: list[str], italic: bool) -> list[tuple[str, str, str]]:
    """(file, weight or '' for variable, style) for the files a pack keeps."""
    out = []
    for file in files:
        is_italic = 'Italic' in file
        if is_italic and not italic:
            continue
        if '[' in file:
            out.append((file, '', 'italic' if is_italic else 'normal'))
            continue
        style = file.rsplit('-', 1)[-1].removesuffix('.ttf').replace('Italic', '') or 'Regular'
        if style in STATIC_WEIGHTS:
            out.append((file, STATIC_WEIGHTS[style], 'italic' if is_italic else 'normal'))
    return out


def latin_x_height() -> float:
    from fontTools.ttLib import TTFont

    heights = []
    for family in json.loads(BUNDLED.read_text()):
        face = next((f for f in family['faces'] if f['fontStyle'] == 'normal'), None)
        if not face:
            continue
        font = TTFont(str(ROOT / 'assets' / 'fonts' / family['slug'] / face['file']))
        os2 = font['OS/2']
        if getattr(os2, 'sxHeight', 0):
            heights.append(os2.sxHeight / font['head'].unitsPerEm)
    return sum(heights) / len(heights)


def measure(font, script: str) -> float:
    from fontTools.pens.boundsPen import BoundsPen

    cmap = font.getBestCmap()
    glyphs = font.getGlyphSet()
    tops = []
    for code, _ in ranges(MEASURE[script]):
        name = cmap.get(code)
        if not name:
            continue
        pen = BoundsPen(glyphs)
        glyphs[name].draw(pen)
        if pen.bounds:
            tops.append(pen.bounds[3])
    return (sum(tops) / len(tops) / font['head'].unitsPerEm) if tops else 0.0


def range_text(codes: list[int]) -> str:
    codes = sorted(set(codes))
    parts = []
    start = prev = None
    for code in codes:
        if start is None:
            start = prev = code
        elif code == prev + 1:
            prev = code
        else:
            parts.append((start, prev))
            start = prev = code
    if start is not None:
        parts.append((start, prev))
    return ', '.join(f'U+{a:04X}' if a == b else f'U+{a:04X}-{b:04X}' for a, b in parts)


def cut(raw: bytes, script: str, variable: bool):
    """The font cut down to the script, as WOFF2 bytes; with its range and weight span."""
    from fontTools import subset
    from fontTools.ttLib import TTFont
    from fontTools.varLib import instancer

    font = TTFont(io.BytesIO(raw), recalcTimestamp=False, lazy=False)
    weight = ''
    if variable and 'fvar' in font:
        pins = {}
        for axis in font['fvar'].axes:
            if axis.axisTag == 'wght':
                weight = f'{int(axis.minValue)} {int(axis.maxValue)}'
            else:
                pins[axis.axisTag] = axis.defaultValue
        if pins:
            font = instancer.instantiateVariableFont(font, pins)
            # Round trip, so the subsetter reads the new tables, not the original's lazily loaded ones.
            buffer = io.BytesIO()
            font.save(buffer)
            font = TTFont(io.BytesIO(buffer.getvalue()), recalcTimestamp=False, lazy=False)
    wanted = set()
    for a, b in ranges(SCRIPT_RANGES[script]) + ranges(HELPERS):
        wanted.update(range(a, b + 1))
    have = set(font.getBestCmap())
    own = set()
    for a, b in ranges(SCRIPT_RANGES[script]):
        own.update(c for c in range(a, b + 1) if c in have)
    options = subset.Options()
    options.layout_features = ['*']
    options.name_IDs = ['*']
    options.name_languages = ['*']
    options.notdef_outline = True
    options.hinting = False
    options.desubroutinize = True
    options.flavor = 'woff2'
    subsetter = subset.Subsetter(options)
    subsetter.populate(unicodes=sorted(wanted & have))
    subsetter.subset(font)
    size = measure(font, script)
    out = io.BytesIO()
    font.flavor = 'woff2'
    font.save(out, reorderTables=False)
    return out.getvalue(), range_text(sorted(own)), weight, size


def category(metadata: str) -> str:
    match = re.search(r'^category:\s*"([A-Z_]+)"', metadata, re.M)
    return GENERIC.get(match.group(1), 'sans-serif') if match else 'sans-serif'


def cmd_build(out_dir: str) -> int:
    sources = json.loads(SOURCES.read_text())
    commit = sources['commit']
    version = re.search(r"WINDROSE_LIVERY_VERSION', '([^']+)'", (ROOT / 'windrose-livery.php').read_text()).group(1)
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    reference = latin_x_height()
    print(f'Latin x-height (average of the bundled families): {reference:.3f} em')
    summary = {'version': version, 'source': f'https://github.com/google/fonts/tree/{commit}', 'packs': {}}
    cache: dict[str, bytes] = {}

    keep = Path(os.environ['FONT_PACKS_CACHE']) / commit if os.environ.get('FONT_PACKS_CACHE') else None

    def raw(path: str) -> bytes:
        if path not in cache:
            kept = keep / path if keep else None
            if kept and kept.is_file():
                cache[path] = kept.read_bytes()
            else:
                cache[path] = fetch(f'https://raw.githubusercontent.com/google/fonts/{commit}/{path}')
                if kept:
                    kept.parent.mkdir(parents=True, exist_ok=True)
                    kept.write_bytes(cache[path])
        return cache[path]

    for script, names in families_by_script().items():
        folder = PREFIX + script
        entries: dict[str, bytes] = {}
        families = []
        for name in names:
            source = sources['families'].get(name)
            if not source:
                print(f'{name} is missing from {SOURCES.name}: run `sources` again.', file=sys.stderr)
                return 1
            slug = slugify(name)
            generic = category(raw(f"{source['dir']}/METADATA.pb").decode('utf-8'))
            faces = []
            sizes = []
            for file, weight, style in pick_files(source['files'], script == 'cyrillic-greek'):
                data, unicode_range, span, size = cut(raw(f"{source['dir']}/{file}"), script, not weight)
                stored = f"{slug}-{(weight or 'var')}-{style}.woff2"
                entries[f'{folder}/{slug}/{stored}'] = data
                faces.append({'file': stored, 'fontWeight': weight or span or '400', 'fontStyle': style, 'unicodeRange': unicode_range})
                if size:
                    sizes.append(size)
            if not faces:
                print(f'{name}: no usable files', file=sys.stderr)
                return 1
            licence = source['license']
            entries[f'{folder}/{slug}/{licence}'] = raw(f"{source['dir']}/{licence}")
            family = {'name': name, 'slug': slug, 'generic': generic, 'license': licence, 'faces': faces}
            measured = sum(sizes) / len(sizes) if sizes else 0
            adjust = min(1.3, max(1.0, round((reference * FACTOR.get(script, 1.0) / measured) / 0.05) * 0.05)) if measured else 1.0
            if adjust > 1.0:
                family['sizeAdjust'] = f'{round(adjust * 100)}%'
            families.append(family)
            print(f"  {script}: {name} ({generic}) {len(faces)} face(s), letters {measured:.3f} em, size {family.get('sizeAdjust', '100%')}")
        pack = {'format': FORMAT, 'script': script, 'livery': version, 'source': summary['source'], 'families': families}
        entries[f'{folder}/pack.json'] = (json.dumps(pack, indent='\t', ensure_ascii=False) + '\n').encode('utf-8')
        zip_path = out / f'{folder}.zip'
        with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as archive:
            for path in sorted(entries):
                info = zipfile.ZipInfo(path, ZIP_TIME)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o644 << 16
                archive.writestr(info, entries[path])
        summary['packs'][script] = {
            'file': zip_path.name,
            'bytes': zip_path.stat().st_size,
            'families': [{'name': f['name'], 'slug': f['slug'], 'generic': f['generic'], 'sizeAdjust': f.get('sizeAdjust', '')} for f in families],
        }
        print(f'{zip_path.name}: {zip_path.stat().st_size / 1048576:.2f} MB')
    PACKS.write_text(json.dumps(summary, indent='\t', ensure_ascii=False) + '\n')
    print(f'Wrote {PACKS.relative_to(ROOT)}')
    return 0


def cmd_same(other: str) -> int:
    def strip(data: dict) -> dict:
        for pack in data.get('packs', {}).values():
            pack.pop('bytes', None)
        return data

    mine = strip(json.loads(PACKS.read_text()))
    theirs = strip(json.loads(Path(other).read_text()))
    if mine != theirs:
        print('The packs differ from the committed assets/mermaid/packs.json (families, sizes or version).', file=sys.stderr)
        return 1
    print('Packs match the committed summary.')
    return 0


def main() -> int:
    if len(sys.argv) == 3 and sys.argv[1] == 'same':
        return cmd_same(sys.argv[2])
    if len(sys.argv) >= 2 and sys.argv[1] == 'sources':
        return cmd_sources(sys.argv[2] if len(sys.argv) > 2 else None)
    if len(sys.argv) == 3 and sys.argv[1] == 'build':
        return cmd_build(sys.argv[2])
    print(__doc__)
    return 2


if __name__ == '__main__':
    sys.exit(main())
