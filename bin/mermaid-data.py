#!/usr/bin/env python3
"""Builds Style Mermaid's bundled data (development only, Python standard library).

Reads the design kit (its folder as the first argument: generic variations made for Ipsum) and Ipsum
itself, and writes into the plugin:

- assets/fonts/<slug>/                  the Latin font files (WOFF2) and their license texts;
- assets/mermaid/fonts.json             the bundled font catalog (name, category, faces, coverage);
- assets/mermaid/styles.json            the ready styles (the kit's variations, font references by role) and Ipsum;
- assets/mermaid/palettes.json          Ipsum's own color presets.
It does not write assets/mermaid/scripts.json (font pairs for other scripts, fetched from Google Fonts or Bunny Fonts
on a click): that file is edited by hand, and each family in it was checked on both services.

Usage: bin/mermaid-data.py <kit folder> <ipsum folder> <Inter OFL.txt>
Font references in styles become role placeholders: var:preset|font-family|@heading, @text, @labels.
"""

import json
import os
import shutil
import sys

KIT, IPSUM, INTER_OFL = sys.argv[1], sys.argv[2], sys.argv[3]
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(ROOT, 'assets', 'fonts')
DATA = os.path.join(ROOT, 'assets', 'mermaid')

# Left out (2.4 MB): offered through Google Fonts or Bunny Fonts instead.
LEFT_OUT = {'merriweather': {'name': 'Merriweather', 'fallback': 'source-serif-4'}}

CATEGORY = {
    'serif': ['cormorant-garamond', 'fraunces', 'instrument-serif', 'literata', 'lora', 'newsreader',
              'playfair-display', 'roboto-slab', 'source-serif-4', 'young-serif'],
    'display': ['bebas-neue', 'bricolage-grotesque', 'fredoka', 'grandstander', 'shrikhand', 'syne'],
    'mono': ['jetbrains-mono'],
}

HEADING_BLOCKS = ['core/site-title', 'core/query-title', 'core/comments-title', 'core/post-title']

PREFIX = 'var:preset|font-family|'


def category(slug):
    for name, slugs in CATEGORY.items():
        if slug in slugs:
            return name
    return 'sans'


def load(path):
    with open(path, encoding='utf-8') as handle:
        return json.load(handle)


def write(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as handle:
        json.dump(data, handle, indent='\t', ensure_ascii=False)
        handle.write('\n')


def copy_family(family, folder):
    """Copies a family's files and license; returns its catalog entry."""
    slug = family['slug']
    stack = family['fontFamily']
    own = stack.split(',')[1].strip() if ',' in stack else ''
    covers = ['latin', 'latin-ext', 'vietnamese']
    if own not in ('"Noto Sans"', '"Noto Serif"'):
        covers += ['cyrillic', 'greek']
    generic = stack.split(',')[-1].strip()
    target = os.path.join(FONTS, slug)
    os.makedirs(target, exist_ok=True)
    faces = []
    for face in family['fontFace']:
        src = face['src'][0].replace('file:./', '')
        name = os.path.basename(src)
        shutil.copyfile(os.path.join(folder, src), os.path.join(target, name))
        faces.append({'file': name, 'fontWeight': str(face['fontWeight']), 'fontStyle': face.get('fontStyle', 'normal')})
        lic_dir = os.path.dirname(os.path.join(folder, src))
        for lic in os.listdir(lic_dir):
            if lic.upper().startswith(('OFL', 'LICENSE')):
                shutil.copyfile(os.path.join(lic_dir, lic), os.path.join(target, lic))
    return {
        'slug': slug,
        'name': family['name'],
        'category': category(slug),
        'generic': generic if generic in ('serif', 'sans-serif', 'monospace') else 'sans-serif',
        'covers': covers,
        'faces': sorted(faces, key=lambda f: f['fontStyle']),
    }


def walk(node, path, fn):
    if isinstance(node, dict):
        for key, value in node.items():
            if key == 'fontFamily' and isinstance(value, str):
                node[key] = fn(path, value)
            else:
                walk(value, path + [key], fn)
    elif isinstance(node, list):
        for item in node:
            walk(item, path, fn)


def to_roles(styles, heading, text):
    def fn(path, value):
        if not value.startswith(PREFIX):
            return value
        slug = value[len(PREFIX):]
        if path[:2] == ['elements', 'heading'] or (len(path) > 1 and path[0] == 'blocks' and path[1] in HEADING_BLOCKS):
            return PREFIX + '@heading' if slug == heading else value
        if path == [] or path == ['typography']:
            return PREFIX + '@text' if slug == text else value
        if slug == heading and heading != text:
            return PREFIX + '@heading'
        if slug == text:
            return PREFIX + '@text'
        return value
    walk(styles, [], fn)
    return styles


def main():
    if os.path.isdir(FONTS):
        shutil.rmtree(FONTS)
    catalog = {}
    styles = []
    for entry in sorted(os.listdir(KIT)):
        folder = os.path.join(KIT, entry)
        if entry.startswith(('_', '.')) or entry in ('reference', 'tools', 'zips', 'test') or not os.path.isdir(folder):
            continue
        variation = load(os.path.join(folder, entry + '.json'))
        families = load(os.path.join(folder, 'fonts.json'))['fontFamilies']
        for family in families:
            if family['slug'] not in catalog and family['slug'] not in LEFT_OUT:
                catalog[family['slug']] = copy_family(family, folder)
        st = variation['styles']
        heading = st['elements']['heading']['typography']['fontFamily'][len(PREFIX):]
        text = st['typography']['fontFamily'][len(PREFIX):]
        nav = st.get('blocks', {}).get('core/navigation', {}).get('typography', {}).get('fontFamily', '')[len(PREFIX):]
        labels = 'manrope' if nav in ('', 'manrope') else ('heading' if nav == heading and heading != text else 'text')
        settings = variation['settings']
        settings.get('typography', {}).pop('fontFamilies', None)
        if 'typography' in settings and not settings['typography']:
            del settings['typography']
        missing = {}
        for role, slug in (('heading', heading), ('text', text)):
            if slug in LEFT_OUT:
                missing[role] = LEFT_OUT[slug]
        styles.append({
            'slug': entry,
            'title': variation['title'],
            'description': variation.get('description', ''),
            'fonts': {
                'heading': LEFT_OUT[heading]['fallback'] if heading in LEFT_OUT else heading,
                'text': LEFT_OUT[text]['fallback'] if text in LEFT_OUT else text,
                'labels': labels,
            },
            'missing': missing,
            'settings': settings,
            'styles': to_roles(st, heading, text),
        })

    # Ipsum: Inter (body and headings), Manrope labels, JetBrains Mono italic too.
    ipsum = load(os.path.join(IPSUM, 'theme.json'))
    for slug in ('inter', 'jetbrains-mono'):
        folder = os.path.join(IPSUM, 'assets', 'fonts', slug)
        target = os.path.join(FONTS, slug)
        os.makedirs(target, exist_ok=True)
        for name in os.listdir(folder):
            shutil.copyfile(os.path.join(folder, name), os.path.join(target, name))
    shutil.copyfile(INTER_OFL, os.path.join(FONTS, 'inter', 'OFL.txt'))
    catalog['inter'] = {
        'slug': 'inter', 'name': 'Inter', 'category': 'sans', 'generic': 'sans-serif',
        'covers': ['latin', 'latin-ext', 'vietnamese', 'cyrillic', 'greek'],
        'faces': [{'file': 'inter-var-normal.woff2', 'fontWeight': '100 900', 'fontStyle': 'normal'},
                  {'file': 'inter-var-italic.woff2', 'fontWeight': '100 900', 'fontStyle': 'italic'}],
    }
    catalog['jetbrains-mono']['faces'] = [
        {'file': 'jetbrains-mono-var-normal.woff2', 'fontWeight': '100 800', 'fontStyle': 'normal'},
        {'file': 'jetbrains-mono-var-italic.woff2', 'fontWeight': '100 800', 'fontStyle': 'italic'},
    ]
    # Ipsum names its colors by role (Base, Contrast, Accent, Strong Contrast, Surface, Border; core r65224).
    palette = [{'color': c['color'], 'name': c['name'], 'slug': c['slug']} for c in ipsum['settings']['color']['palette']]
    styles.insert(0, {
        'slug': 'ipsum',
        'title': 'Ipsum',
        'description': 'The theme as it comes: a calm, neutral starting point.',
        'fonts': {'heading': 'inter', 'text': 'inter', 'labels': 'manrope'},
        'missing': {},
        'settings': {'color': {'palette': palette}, 'layout': dict(ipsum['settings']['layout'])},
        'styles': {'typography': {'fontFamily': PREFIX + '@text'},
                   'elements': {'heading': {'typography': {'fontFamily': PREFIX + '@heading'}}}},
    })

    palettes = []
    colors_dir = os.path.join(IPSUM, 'styles', 'colors')
    for name in sorted(os.listdir(colors_dir)):
        preset = load(os.path.join(colors_dir, name))
        color = preset['settings']['color']
        palettes.append({
            'slug': 'ipsum-' + os.path.splitext(name)[0].split('-', 1)[1],
            'title': preset['title'],
            'source': 'Ipsum',
            'palette': [{'color': c['color'], 'name': c['name'], 'slug': c['slug']} for c in color['palette']],
            'duotone': color.get('duotone', []),
            'controlScheme': preset['settings'].get('custom', {}).get('controlScheme', 'light'),
        })

    write(os.path.join(DATA, 'fonts.json'), sorted(catalog.values(), key=lambda f: f['name'].lower()))
    write(os.path.join(DATA, 'styles.json'), styles)
    write(os.path.join(DATA, 'palettes.json'), palettes)
    print(len(catalog), 'families,', len(styles), 'styles,', len(palettes), 'Ipsum palettes')


main()
