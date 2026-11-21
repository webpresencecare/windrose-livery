# Windrose Livery - Style Variations

Style variations kept in the database, part of the Windrose Suite. They appear in the Site Editor, Styles, Browse styles, next to the theme's own, on the theme they were made for and its child themes; theme updates never remove them. Works on any block theme, on a single site or a network, without the other Windrose plugins.

Requires WordPress 7.2 and PHP 8.4.

## What it does

- **Library**: on a network, one shared library for the whole network, kept on the main site; network administrators (`manage_network_themes`) manage it in Network Admin, Themes, Style variations. On a single site: Appearance, Style variations, administrators (`edit_theme_options`). Since 0.4 each site of a network can also keep its own styles (below, "Site administrators").
- **Import** (`.json` or `.zip`): dry run first (preview: title, theme, colors, fonts, custom CSS, what was left out, which variation it would replace), then the same upload again for real, checked again from scratch. Zip shape (as written by Export):

  | Path | Holds |
  |---|---|
  | `<slug>/<slug>.json` | theme.json shape: `title`, `settings`, `styles` |
  | `<slug>/fonts.json` | `{ "fontFamilies": [...] }`, sources relative to this folder (`fonts/...` or `file:./fonts/...`) |
  | `<slug>/fonts/<family>/...` | Font files and license texts (`OFL.txt`, `LICENSE`...) |

- **Theme**: chosen on import, or guessed from its preset names (since 0.9: the block theme with most names in common among colors, font sizes, font families and spacing, defined or used; Ipsum and Twenty Twenty-Five share their color names since core r65224, so `accent-2` or a font size such as `xx-large` or `2-x-large` tells them apart; on a tie the library site's active theme, then a parent before its children). Shown when the active theme is that theme or a child of it.
- **Site Editor menu item** (`src/editor`, 0.1.1): "Save styles as a variation" in the Options menu (⋮; WordPress 7.2 lists plugin items under Panels), `PluginMoreMenuItem`, loaded in the Site Editor for library managers only. Check, preview, add (POST `/from-site` for the current site); asks to save first when the styles have unsaved changes. Afterwards it gives Browse styles a fresh list (`__experimentalReceiveThemeGlobalStyleVariations`), since the editor would otherwise reuse the list preloaded with the page.
- **Save a site's styles**: the site's `wp_global_styles` for its active theme (presets grouped by origin are flattened: the theme's edited ones, then the site's own); fonts from its Font Library (any uploads address of the installation) copied, under Livery's names, into the Font Library folder of the library it goes to. Theme: the site's theme or its parent.
- **Export**: `admin-post.php?action=windrose_livery_export&id=<id>` (nonce), a zip in the shape above.
- **Apply** (`Applier`): writes the variation's settings and styles into each chosen site's saved styles for its active theme (what the Site Editor does when a variation is picked); core keeps the previous ones as a revision. Checked per site: same network, `edit_theme_options` **on that site**, the theme fits. When WordPress filters the saved styles (no `unfiltered_html` there), the result says "Applied in part".
- **Versions** (`Tracker`): site option `windrose_livery_applied` records the variation, version and fingerprint a site uses, how (`livery` or `editor`: saved styles equal to a stored variation, also when picked in the Site Editor) and whether the site changed its styles since. The screen lists sites on an older version with "Apply the new version".
- **Shape** (0.1.1): a variation goes to the Site Editor, and is applied, as WordPress gives a theme's variation files: through `new WP_Theme_JSON( ... )->get_raw_data()`, which groups presets by origin (`"palette": { "theme": [...] }`, also font families). The Site Editor's font code in 7.2 fails on plain lists (Typography, Manage fonts, before saving). So a Livery variation behaves exactly like a file in the theme's `styles/` folder: its fonts replace the theme's font list on that site, as with core's own variations. Stored data and exports keep plain lists (the shape of variation files).
- **Site Editor** (`Editor`): `rest_request_after_callbacks` appends the fitting variations to `GET /wp/v2/global-styles/themes/<theme>/variations` (preloaded too); their fonts as @font-face rules in the editor's assets (`enqueue_block_assets`, admin only), as core does for the theme's variations. The Playwright test checks it on every build, since it depends on the editor reading that route.
- **Delete**: the record goes; font files no variation stored on that site and no site's saved styles (revisions included) mention are deleted, with the Font Library entries Livery made for them; a license stays while a font of its family stays.

## Fonts in the Font Library (0.4, `Fonts`, `Font_Library`)

Fonts go where WordPress's Font Library puts them. A stored variation's files are in `wp_get_font_dir()` of its library site (`uploads/fonts/` of the main site for the shared library, `uploads/sites/<id>/fonts/` for a site's own styles), still named `<sha256 16>-<name>` (one copy per content), and registered there as WordPress registers an installed font: a `wp_font_family` post (title, slug, `fontFamily`) and a `wp_font_face` post per face (title `WP_Font_Utils::get_font_face_slug()`, the face settings with the file's address, `_wp_font_face_file`). Livery's own posts carry `_windrose_livery_font`; an installed family with the same slug, or the same face, is used as it is. They show in Styles, Typography, Fonts like any installed font.

- **Deleting one in Manage fonts** that a stored variation or a site's saved styles use (`rest_pre_dispatch` on `DELETE /wp/v2/font-families/<id>` and its faces): the first time it is refused (409) with a message core's dialog shows after its own ("This font is used by the style variations "A", and by 2 sites. Delete it again within 10 minutes to delete it anyway."); the same person's second delete within 10 minutes goes through (transient per user, site and font). Only for people who may edit theme options there.
- **Style Mermaid** offers the fonts installed in the Font Library (not Livery's own, which it offers as bundled fonts): recipe source `library` with the family post ID; their files are copied like any other.
- **Packs and Style Mermaid's cache** stay in Livery's folder on the main site (`uploads/windrose-livery/packs/`, `cache/`): a pack font enters a Font Library only when a style uses it.
- **Before 0.4** the files were in `uploads/windrose-livery/fonts/` of the main site; 0.4 to 0.9.0 moved them (network screen, or `wp windrose-livery migrate-fonts`). The move was removed in 0.9.1: every installation had moved its fonts. A site still on a version before 0.4 updates to 0.9.0 first.

## Light and dark (0.4, `Schemes`, `src/mermaid/twins.js`, `src/editor/light-dark.js`, block `windrose/light-dark-switch`)

A style's other scheme is a set of **twin colours** in its own palette: for each colour (`base`…), a twin `windrose-livery-dark-<slug>` (or `-light-` for a dark style), named "Dark: <name>". Core edits them in Styles, Colors, Palette (renaming keeps a colour's slug), saves, exports and applies them like any colour. Stored styles keep one plain palette list; when a style reaches the Site Editor or is applied (`Library::resolved()`, `twins_as_custom()`), the twins go under the palette's "custom" origin, so the Site Editor lists them under Custom, apart from the style's own colours under Theme (core's palette panel has fixed groups: a group titled "Dark" would need core; undo there if core gains light and dark); they also appear in block colour pickers (a block coloured "Dark: Text" stays that colour in both schemes). The style's own colours are the default.

- **Calculating** (`makeTwins`, shared by Style Mermaid and the Site Editor): each colour's OKLCH lightness turned around within the palette's range (the lightest colour becomes the other scheme's darkest end, 0.2 for dark, the darkest its lightest, 0.95 or 0.985), hue and transparency kept, chroma ×0.6 on dark backgrounds below lightness 0.35; then the contrast checks run with the twins in place, and each twin a failing check points to (never the page background) is moved to the nearest colour that passes, up to four rounds. All 21 ready styles pass this way (`tests/js/twins.test.mjs`).
- **Style Mermaid**: Colors step, "Light and dark" (Add a dark/light palette, edit, Calculate again, Remove, "Contrast in dark"); the preview's "Dark/light switch"; Finish lists both schemes. The server checks both (`Contrast::check_twins()`): admins are warned, visitors cannot download while either fails.
- **Site Editor** (Options ⋮, Panels; anyone with `edit_theme_options`): "Add a dark palette" calculates the twins from the palette in effect (theme base and the site's), shows them with their contrast, and adds them to the styles being edited (`palette.custom`), saved with core's Save; "Preview dark" points the canvas's colour variables at the twins while on.
- **On the page** (front end, `wp_enqueue_scripts`): `:root[data-windrose-livery-scheme="dark"]` sets `color-scheme`, `--wp--custom--control-scheme` (Ipsum's audio player) and each colour's variable to its twin's variable; with "Follow the visitor's browser preference" the same rules sit in `@media (prefers-color-scheme: dark)` for `:root:not([data-windrose-livery-scheme="light"])`, which works without JavaScript. With the switch on, an inline script at the top of the head reads `localStorage['windrose-livery-scheme']` and sets the attribute before the page shows (no cookie, nothing sent; on a subdirectory network all sites at one address share the choice). Gradients made from the palette follow; duotone, images and typed colours do not.
- **The block** "Light and dark switch" (`blocks/light-dark-switch`, rendered by PHP; on the page an Interactivity API block, script module `build/light-dark-switch-view.js` from `src/light-dark-switch/view.js`, data as config and state from the server): a button "Dark mode" with `aria-pressed` and a sun or moon icon, the label visible or for screen readers only; inside a Navigation block it is a navigation item (core's Navigation accepts it through `allowedBlocks`, server and editor). Nothing shows while the switch option is off or the style has no twins. While on, Livery places it after the header's Navigation block (`hooked_block_types`: template parts of the header area, patterns for headers such as Ipsum's), so it can be moved or removed in the Site Editor like any hooked block.
- **Options per site**: `windrose_livery_scheme` = `{ switch, follow }`, off by default, in each site's switches on Livery's screen.

## Site administrators (0.4, `Network`, `Library` scopes)

Network option `windrose_livery_network` = `{ site_admins }`, off by default (Network Admin, Themes, Style variations, card "Site administrators"). When on, each site's administrators (`edit_theme_options` there; network administrators too) get Appearance, Style variations on their own site:

- **Their site's own styles**: private `windrose_livery` posts on their site marked `_windrose_livery_own` (no custom table), fonts in their site's Font Library; Style Mermaid ("Add to this site's styles", "Add and use on this site"), import (the site's upload quota is checked), export, delete, apply to their site only; "Save styles as a variation" in their Site Editor saves there (network administrators choose: shared or this site). Offered in that site's Site Editor after the shared library's.
- **Their site's switches**: Style Mermaid for visitors, light and dark. Fonts from Google Fonts and Bunny Fonts for visitors stay a network administrator's switch (the network's server fetches them). Font packs: they use the installed ones; installing and removing stay with network administrators.
- **Network administrators**: card "Sites' own styles" (every site's, with Copy to the shared library, dry run first, and Delete).
- Turned off again: their styles stay and keep showing in their Site Editor; only the screen goes.
- How: every REST request names its library with `scope` (0 or none: shared; a site's ID: that site's own), picked before the permission check (`rest_pre_dispatch`) and reset after the callbacks; a site's screen adds it to every request. Rights are always checked on that site (`Library::can_manage_site()`).

## Style Mermaid (0.2, `Mermaid`, `Builder`, `Catalog`, `Contrast`, `Remote_Fonts`, `Rate_Limit`, `src/mermaid`)

Makes a style variation for **Ipsum** (and so its child themes, such as Windrose Prow for Ipsum), with a live preview. One React app, two places:

- **Livery's screen**, "Create a style" (`&view=create`, library managers): add to the library (`Importer::add_built()`, the same checks and storage as an import) or download.
- **The block `windrose/style-mermaid`** ("Style Mermaid", `blocks/style-mermaid/block.json`, rendered by PHP) on a public page: download only. Off until the site's switch is on (Livery's screen, "Style Mermaid on public pages"); a second switch lets visitors get fonts from Google Fonts or Bunny Fonts. No account, no cookie, nothing stored; per visitor and hour 20 downloads, 20 new fonts, 600 previews (pages of patterns included) (`Rate_Limit`: transient keyed by an HMAC of the address and the action). Visitors cannot download while a contrast check fails.

Steps: **Start** (20 ready styles from the design kit, and Ipsum), **Fonts** (pairs from the ready styles, heading and text fonts, labels font, fonts installed in the Font Library; for visitors where allowed a font from Google Fonts or Bunny Fonts; pairs for other scripts), **Colors** (every preset palette: ready styles, Ipsum's 7, the library in the admin screen; filter by accent; five moods from an accent in OKLCH; palette, element colors, gradients, duotone; contrast with Fix), **Typography** (text, links, headings, h1 to h6, captions, buttons), **Layout** (text width 640/860/980 or any, wide width 1080/1280/1440 or any, spacing scale, block spacing, page padding), **Blocks** (0.5, below; was Buttons), **Finish**.

How the pieces fit:

- The browser keeps the style as theme.json data with fonts by role (`var:preset|font-family|@heading`, `@text`, `@labels`) and sends a **recipe** (`Builder` docblock): settings, styles, and font choices by reference only (`bundled` slug or `remote` cache key). The server builds the font families itself, then runs `Sanitizer` (custom CSS never kept), the contrast checks, and previews, stores or zips.
- **Preview**: `POST /mermaid/preview` merges core's data, Ipsum's `theme.json` and the variation with `WP_Theme_JSON` and returns `get_stylesheet()` and the @font-face rules; a sandboxed frame (no scripts) shows a sample page with core's block styles.
- **Fonts**: 39 families in `assets/fonts/` (built by `bin/mermaid-data.py` from the design kit and Ipsum; OFL or Apache texts next to each). Every stack continues with the chosen script fonts (headings take the script's heading font), then the generic family; "Cyrillic and Greek" only where a family lacks them; a script family named like a Latin one gets its own name ("Rubik Hebrew"). Script fonts come from an installed **font pack** first (0.3, below), otherwise from Google Fonts or Bunny Fonts. Each script has a **Size next to Latin text** (80 to 150%, the faces' `sizeAdjust`; default: the size measured for the pack's text font) and, for Arabic, a font for **Persian** (Vazirmatn) and **Urdu** (Noto Nastaliq Urdu): a family of its own, in no stack, with a language rule. Chinese, Japanese, Korean: system fonts (browsers pick them by the page's language).
- **Google Fonts and Bunny Fonts** (`Remote_Fonts`), since 0.4 for visitors of a public Style Mermaid only (managers install fonts in the Site Editor's Font Library): a name, or the address of the font's page (`fonts.google.com/specimen/…`, `fonts.bunny.net/family/…`) or of its CSS; only the name is read from an address. "Use it for" applies it at once (headings, text, both, or only the lists). On a click, the server asks the provider's CSS (`/css`, a current browser's user agent), keeps only the wanted subsets (Latin with extensions, Vietnamese, Cyrillic, Greek; or one script), downloads each WOFF2 from the provider's own host (HTTPS, no redirects, `wp_safe_remote_get()`, 5 MB, first bytes checked, at most 40 files) into `windrose-livery/cache/` with a LICENSE.txt naming the source. Index: option `windrose_livery_font_cache` on the library site, 150 MB, oldest out first. The browser only ever loads fonts from this website.
- **Contrast** (`Contrast`, `src/mermaid/contrast.js`): the design kit's rules (text 4.5 on Base and on Surface, links and their hover 4.5, headings 3, buttons and their hover 4.5, captions 4.5, the accent as text 4.5; since 0.8 also required, for patterns, Base on Accent and on Strong Contrast, 4.5 each: patterns put Base text on Accent and Strong Contrast sections). `tests/fixtures/contrast.json` is shared by PHPUnit and the JavaScript tests.

**Colors** (0.9): Ipsum's six colors, in its order, as Ipsum names them since core r65224 (2026-11-03), which are Twenty Twenty-Five's slugs too: `base` Base, `contrast` Contrast, `accent-1` Accent, `accent-3` Strong Contrast, `accent-5` Surface, `accent-6` Border (before: `theme-1`, `theme-2`, `theme-3`, `theme-6`, `theme-5`, `theme-4`). The list lives in `src/mermaid/roles.js`; extra colors are `extra-1` to `extra-4`, twins `windrose-livery-dark-<slug>` or `-light-<slug>`. Old files are not converted on import. What sites stored before is renamed by **`wp windrose-livery rename-colors`** (`Rename_Colors`, WP-CLI only; a dry run unless `--run`; `--network` for every site, otherwise the `--url` site): Livery's stored styles made for Ipsum or a child (palette put in Ipsum's order), the saved styles of Ipsum and its children (the site's record keeps matching), and block content on sites whose theme is Ipsum or a child (`has-theme-N-…` classes, color attributes, `var:preset|color|theme-N`, `--wp--preset--color--theme-N`, twins; words in the text stay; a Windrose Knotboard library pattern that matched its import keeps matching it, so a later import does not see an edit). Revisions stay as they are; a palette holding old and new names together is left alone and reported. Rows are written directly (no revision, no content filter). A one-off for sites made before WordPress 7.2-beta4; it will be removed in a later version.

Data files: `assets/mermaid/fonts.json`, `styles.json`, `palettes.json` (built by `bin/mermaid-data.py <kit> <ipsum> <Inter OFL.txt>`), `scripts.json` (pairs for other scripts, edited by hand; each family checked on both services).

## Per-block looks (0.5, `src/mermaid/blocks.js`, `src/mermaid/step-blocks.js`)

Style Mermaid's Blocks step gives a few blocks a **named look**, written as plain theme.json (`styles.blocks`, `styles.elements.button`), so the result is a ready style that the Site Editor (Styles, Blocks) shows and edits further. No free values or box controls: those are the Site Editor's. Values point at the style's presets (palette, spacing sizes, Ipsum's `soft` shadow), so light and dark twins follow them.

| Entry | Choices | Writes |
|---|---|---|
| Buttons | shape, fill, color, hover color, size | `elements.button`, `core/button` (with its Outline style), the search button's radius; on hover (outlined ones too) a button fills with the hover color; focus takes the hover colors, pressed the normal ones (core 7.2 keeps no states inside a block style variation, so Outline shares them) |
| Corners | square 0, soft 6px, round 16px | the radius of image, featured image, cover, quote, pullquote, code |
| Images | frame: none, line (Border color), shadow | border and shadow of image and featured image |
| Quotes | Surface background, lines (above and below), plain | quote and pullquote background and borders (lines and plain: no radius). No line on one side: theme.json writes left and right, wrong on right-to-left pages |
| Separator | color (Border, Accent, Contrast), thickness | `core/separator` |
| Menu | letters (as written, capitals), weight, space between items | `core/navigation` typography and `blockGap` |
| Code | line, Surface background | `core/code` |

- Each entry has "Keep the preset …" (the default: nothing written, the block keeps the look of the style chosen in the Start step), a small picture of the current choice in the style's colors, and the contrast checks it touches. Opening a panel scrolls the preview to that block (the sample page has every block of the list).
- Every color pair a look uses is already checked (text on Base and on Surface, button colors, links); separators and markers are decoration. `tests/js/blocks.test.mjs` checks that no look makes a check fail on any ready style.
- **Settings only, never custom CSS**: WordPress keeps custom CSS only for people who may edit CSS (on a network, network administrators), so a look written as CSS would be lost when a site administrator imports the style. Every look is a setting WordPress keeps for everyone, so whoever imports a style gets exactly what was made. Lists are not offered (their markers have no setting) and keep Ipsum's look; people allowed to add CSS change them in the Site Editor (Styles, Blocks, List, Additional CSS).
- Shared fixture: `tests/fixtures/block-looks.json` (three recipes covering every option, made by the JavaScript; `UPDATE_FIXTURES=1 npm run test:js` rewrites it), read by `BlockLooksTest`.

## Pattern browser (0.6, `Patterns`, `Sample`, `src/mermaid/patterns.js`, `src/admin/patterns-view.js`)

The site's registered patterns with a style applied, **rendered by WordPress itself** on that site, for one request: nothing is saved.

- **Where**: Livery's screen, "Browse patterns" (a row action of each stored style, and a button on each of the network screen's sites' own styles; `&view=patterns&style=<id>&scope=<n>`), and Style Mermaid's preview ("Sample page" or "Patterns": the style being made, or on Livery's screen a stored one). Core first: a site's Site Editor shows the same for one site when a style is picked without saving and Patterns is opened; Livery adds the style being made, the network screen, visitors and sample content.
- **How** (`Patterns::render()`, `template_redirect` at 0): `POST <home>/?windrose_livery_patterns=1` with `style` and `scope` (stored) or `recipe` (Style Mermaid's, built by `Builder::style_data()`), `category` (a group key, or all), `sample`, `_wpnonce`. Core's public filter `wp_theme_json_data_user` (priority -1000, so other filters such as Helm's Bunny faces see it as applied styles) returns the style in place of the site's saved styles; `$_wp_current_template_content` holds, for each pattern, a small label then core's Pattern block, and core's `template-canvas.php` renders it. The stylesheet, `@font-face` rules and HTML equal those of the site with the style really applied (PHPUnit and Playwright compare them). No admin bar, `noindex`, `no-store`; the other palette's rules always printed so the preview's switch works; the Navigation block's fallback may not create a menu (`wp_navigation_should_create_fallback`). The browser fetches the page and shows it in a sandboxed frame without scripts (`srcdoc`), keeps the scroll position when only the style changes, and jumps to a pattern by its label.
- **Which patterns** (`GET /patterns` on that site, `Patterns::groups()`): those the inserter offers (not `inserter: false`, such as Ipsum's page layouts), grouped by category label (core registers two "Posts"), sorted, uncategorized last. Administrators: every registered pattern (theme, WordPress, plugins). Visitors: only WordPress's own (`source: core`) and the parent theme's files (Ipsum's), what a site has by default with a new style. "My patterns" (the site's own) never.
- **Who**: with the nonce `windrose_livery_patterns`, people who may edit theme options on the site and use Livery (network administrators, or site administrators while the network allows it); a stored style also needs the right to manage its library, a site's own style renders on that site only. Anyone else (also a logged-in person without the nonce) is a visitor: only where the site's public Style Mermaid is on, only an unsaved style, always sample content, counted with Style Mermaid's previews (600 an hour). The style must fit the site's theme (Style Mermaid's: Ipsum or a child; no theme switch for one request), and only sites at the screen's address are offered (the request carries the login cookie).
- **Sample content** (`Sample`; administrators' "Sample content" switch, always for visitors): site title "Site Title" and a tagline, no logo or site icon; six posts made in memory (IDs near `PHP_INT_MAX`, put in the object cache for the request and taken out at its end) for every query of posts or pages, the main query included, with a category, two tags, a picture (an SVG gradient in the style's colours) and no author; template parts from the theme's files, not the site's edited ones; a Navigation block with a saved menu or none gets four sample links, the Page List block sample pages; avatars a circle drawn here. Other post types (styles, fonts, templates, "My patterns") are left alone.

## Other plugins' patterns (0.7, `Patterns`, for Windrose Knotboard)

The pattern browser's render address also draws patterns another plugin supplies, in the order it gives, with the same style rules.

- Request fields: `source` (the plugin's key), `items` (its own keys, comma-separated: letters, digits, `-`, `_`, `/`, `:`; at most 60; duplicates kept) and `layout` (`list`: a label before each, as the browser; `page`: one after the other, as a page). With a stored style or a recipe, as usual.
- Filter `windrose_livery_pattern_items` (`null`, `$source`, `$keys`, `$visitor`): the plugin answers with a list of `name`, `title`, `content` (block markup) for what that person may see, or a `WP_Error`; malformed entries are dropped; nothing usable answers 404. Livery does not filter the markup: the source sends what it would show that person on its own pages.
- Filter `windrose_livery_visitor_previews` (`false`, `$source`): `true` opens visitors' previews **for that source only** where the public Style Mermaid is off (Knotboard's public library). Visitors still get only an unsaved style (no stored style), always sample content, and the 600 previews an hour. It does not open the site's registered patterns.
- **"See the patterns in this style"** (Style Mermaid, both places): shown when the filter `windrose_livery_pattern_library` gives an address (Windrose Knotboard gives its library's where the site offers patterns; `Mermaid::pattern_library()`, `config.patternLibrary`). It keeps the style being made in the visitor's browser (local storage `windrose-livery-recipe`: `{ title, recipe, at }`; never sent to the server, never a cookie), opens the library in a new tab with `#windrose-livery-recipe`, and keeps the stored style up to date while the visitor goes on changing it.
- **Ready styles as recipes**: `assets/mermaid/ready-recipes.json`, written by `node bin/ready-recipes.mjs` with Style Mermaid's own `fromStyle()` and `toRecipe()` (rerun after changing the ready styles or the recipe code; `tests/js/ready-recipes.test.mjs` fails when it is out of date), read by `Catalog::ready_recipes()`. A library sends one as `recipe`.
- Tests: `PatternsTest` (items in order, list and page, errors, key rules, visitors of a source library); `patterns.spec.mjs` `ready-recipes.test.mjs`; `PatternsTest` also checks every ready recipe passes the server's checks; (a test must-use plugin on the throwaway site, rendered for a visitor with the public Style Mermaid off).

## Font packs (0.3, `Packs`, `bin/font-packs.py`)

Fonts for other scripts without Google Fonts or Bunny Fonts: one pack per script (Cyrillic and Greek, Arabic, Hebrew, Devanagari, Thai, Bengali, Tamil, Gujarati, Gurmukhi, Georgian, Armenian, Ethiopic), holding the fonts of Style Mermaid's pairs for that script and its language fonts (51 families, SIL Open Font License), cut down to that script: 4.4 MB for all 12 (Arabic 0.9 MB, Armenian 64 KB). Chinese, Japanese and Korean stay on system fonts.

- **Built** by `bin/font-packs.py build <dir>` from the google/fonts repository at the commit in `bin/font-sources.json` (`sources [commit]` lists the files again; no request to the Google Fonts or Bunny Fonts services). Variable fonts keep their weight axis (other axes at their default), static families 400 to 700; WOFF2 without hinting; every face gets a `unicodeRange` with the script's own characters (joiners and the dotted circle stay in the file, not in the range, so emoji sequences on a Latin page never download a script font) and a `sizeAdjust` when the script looks smaller than Latin text (letter height against the average x-height of the 39 bundled Latin families, Arabic against 90% of it, so Noto Sans Arabic gets 115%; 100% to 130% in steps of 5%). Zips are reproducible (fixed dates, sorted). `assets/mermaid/packs.json` is the summary the plugin shows before installing.
- **Published** with each release (`.github/workflows/release.yml`: Python 3.14, fontTools and Brotli pinned with hashes in `bin/font-packs-requirements.txt`; the release stops when the summary differs from the committed one): `windrose-livery-fonts-<script>.zip`.
- **Installed** by library managers, on Livery's screen (card **Font packs**: Install, Install again, Remove, Upload a pack) or in Style Mermaid's Fonts step ("Install the font pack for Arabic (0.9 MB)"). **Install** asks GitHub for the release of this Livery version (`v<version>`) through Windrose Common 1.1.0's `Updater::release_asset()`: GitHub's published sha256 and size checked. **Upload a pack** takes the same zip, for sites that cannot reach GitHub.
- **Checked** like an import: read in memory by name, never extracted; one `windrose-livery-fonts-<script>/pack.json` (format 1) naming only families Style Mermaid offers for that script (slug from the name); faces checked with the import's rules; WOFF2 by their first bytes, license texts as plain text; at most 300 entries, 30 MB packed, 60 MB unpacked.
- **Stored** in `windrose-livery/packs/<script>/<family>/` in the library site's uploads, index option `windrose_livery_packs` there. A style made with pack fonts keeps its own copies (in its Font Library folder, as for every font), so Remove never breaks a style.
- **Visitors** of a public Style Mermaid use installed packs too, without the Google Fonts and Bunny Fonts switch.

## Language rules (0.3, `Languages`)

For languages that share a script with others and want another font (Persian and Urdu next to Arabic; Japanese, Korean and Chinese, whose Han characters differ):

```
"settings": { "custom": { "windrose-livery-languages": { "fa": { "heading": "vazirmatn", "text": "vazirmatn" } } } }
```

Since 0.9.1 the rule also travels in the language font's family slug, `<font>-lang-<tag>` (`vazirmatn-lang-fa`; since 0.9.2 the import renames a rule's font this way when the style names it nowhere else, `Languages::into_slugs()`): WordPress removes `settings.custom` from the saves of anyone without `unfiltered_html` (site administrators on a network) but keeps font presets, so Livery reads rules from both. Family slugs of the style's own families; tags BCP 47 in lowercase (`zh-hant` also covers zh-TW, zh-HK, zh-MO; `zh-hans` covers zh-CN, zh-SG). Stored in the style, so it goes wherever the style goes (applied, picked in the Site Editor, exported; WordPress also prints the values as harmless `--wp--custom--*` variables). Style Mermaid writes it from the language fonts chosen (only for the script's languages; a rule sent by the browser is replaced). The design kit's 20 styles carry `ja` and `ko` rules.

Livery prints, from the site's current global settings and styles (`enqueue_block_assets`: front end and editor canvas), for each rule: `:root:is(:lang(fa))` redefines the font family variables of every stack that already continues with script fonts, with the language's font right after the first font; elements with their own `lang` (Windrose Sabir's language item, a quotation) get the same variables and the text font (headings: the heading font; in a menu that replaces the label font for such a link only). Other pages never download the language's font. Works without Windrose Sabir: WordPress puts the site's language on every page.

## Validation (`Sanitizer`, `Package`, `Fonts`)

- Kept: `title`, `description`, `settings`, `styles`; block style partials (`blockTypes`) refused; theme.json versions 1 to 3 (migrated by core).
- Settings and styles go through `WP_Theme_JSON::remove_insecure_properties()`; presets are grouped as "theme" for that check (core expects grouped presets; variation files list them directly), then ungrouped. Values are kept as written (`var:preset|...`). `settings.custom`: names of letters, digits, `-` and `_`, values without `;{}<>\`.
- Left out, with a note in the preview: unknown keys, unsafe values, anything with `url(` or `@import`, background images, custom CSS (`css`) unless the user has `edit_css` and `unfiltered_html` (never with `<`, `url(` or `@import`, at most 50 KB).
- Fonts: families rebuilt field by field (names of letters, digits, spaces, quotes, commas, `-`, `_`); faces keep `fontStyle`, `fontWeight`, `fontDisplay`, `fontStretch`, `unicodeRange`, and (0.3) `sizeAdjust`, `ascentOverride`, `descentOverride`, `lineGapOverride` (percentages; WordPress prints them as `size-adjust`...); sources must be files of the package, inside the variation's folder (no `..`, no absolute path, no address); addresses on other hosts and `data:` refused. Files: WOFF2, WOFF, TrueType, OpenType by their first bytes, at most 10 MB; licenses plain text, at most 200 KB; at most 60 files.
- Zips: at most 500 entries and 80 MB unpacked, read in memory by name, never extracted; exactly one variation file.

## Data

| Where | Key | Holds |
|---|---|---|
| Library site (main site, or the site) | posts `windrose_livery` (private) | One per variation: title, slug, description (excerpt) |
| Their meta | `_windrose_livery_data` | JSON `{settings, styles}`; font sources `windrose-livery:<file>` |
| | `_windrose_livery_theme` | Stylesheet it was made for |
| | `_windrose_livery_version` | 1, then +1 per replacement |
| | `_windrose_livery_files` | Font and license files: stored name, original name, family, kind |
| Library site's Font Library folder (`uploads/fonts/`, `uploads/sites/<id>/fonts/`) | `<sha256 16>-<name>` | Font and license files, each stored once; fonts registered as `wp_font_family` / `wp_font_face` posts (marked `_windrose_livery_font`) |
| Each site (its own styles) | posts `windrose_livery` with `_windrose_livery_own` | As above, for that site only |
| Each site | option `windrose_livery_scheme` | Light and dark: `{ switch, follow }` |
| Network | site option `windrose_livery_network` | `{ site_admins }` |
| Visitor's browser | local storage `windrose-livery-scheme` | `dark` or `light`: the switch's choice (never sent) |
| Each site | option `windrose_livery_applied` | The variation it uses (see Versions) |
| Each site | option `windrose_livery_mermaid` | Style Mermaid switches: `{ public, remote }` |
| Library site | option `windrose_livery_font_cache` | Fonts fetched from Google Fonts or Bunny Fonts: key => family, faces, license, size, time |
| Library site | option `windrose_livery_packs` | Installed font packs: script => Livery version, source, time, size, families with faces |
| Library site's uploads | `windrose-livery/packs/<script>/<family>/<file>` | Their files and license texts |
| Library site's uploads | `windrose-livery/cache/<sha256 16>-<name>` | Their files (variations keep their own copies in `fonts/`) |
| Any site (transients) | `windrose_livery_rl_<hash>` | Style Mermaid's per-visitor counters, one hour |

Font addresses are built from the library site's stored address (option `siteurl` + the uploads path, never the request's scheme), so they are the same on every site of a subdirectory network and from WP-CLI.

## REST (`windrose-livery/v1`)

| Route | Method | Does |
|---|---|---|
| `/variations` | GET | Summaries, installed block themes, whether the user may bring custom CSS |
| `/sites` | GET | Sites with theme, rights and the variation they use |
| `/import` | POST | Upload `file`; `dry_run` (default true), `theme`, `title`, `replace` |
| `/from-site` | POST | `site`, `title`, `theme`, `dry_run`, `replace` |
| `/variations/<id>/apply` | POST | `sites`: site IDs (ignored on a single site) |
| `/variations/<id>` | DELETE | Delete; answers `fontsKept`, `filesDeleted` |
| `/mermaid/catalog` | GET | Style Mermaid's fonts, ready styles, palettes, scripts (managers; anyone where the site's switch is on) |
| `/mermaid/preview` | POST | `recipe`: stylesheet, font faces, contrast checks (same) |
| `/mermaid/font` | POST | `provider`, `family` (name or address), `weights`, `italic`, `script` (managers; anyone where both switches are on) |
| `/mermaid/save` | POST | `recipe`, `dry_run`, `replace`: add to the library (managers) |
| `/mermaid/settings` | GET, POST | The switches of each site, with light and dark (`switch`, `follow`) and whether its style has twins (managers; a site's own screen: that site) |
| `/mermaid/packs` | GET, POST | Font packs (installed or not); POST with `file` uploads one (managers) |
| `/mermaid/packs/<script>` | POST, DELETE | Install from this version's GitHub release; remove (network managers) |
| `/network` | GET, POST | `site_admins` (network administrators) |
| `/site-styles` | GET | Every site's own styles (network administrators) |
| `/variations/<id>/copy` | POST | A site's own (`scope`) into the shared library: `dry_run`, `replace` |
| `/patterns` | GET | This site's patterns by category for the pattern browser (`visitor`: only the theme's and WordPress's), its theme (administrators; anyone where the site's public Style Mermaid is on) |

Every route takes `scope` (0 or none: the shared library; a site's ID: that site's own styles).

Patterns: `POST <home>/?windrose_livery_patterns=1` on the site that renders (above, "Pattern browser"); an HTML page, or JSON with the error (403, 405 for anything but POST, 409 when the style does not fit the site, 429 with Retry-After).

Download: `admin-post.php?action=windrose_livery_mermaid` (POST `recipe`), for visitors too; a zip, or JSON with the error (403 off, 422 contrast with the checks of both schemes, 429 with Retry-After).

## Development

The screen is React (`src/admin`, built into `build/`, committed) with WordPress components and DataViews (`@wordpress/dataviews/wp`, bundled). Style Mermaid: `src/mermaid` (into `build/admin.js` and, for the block on public pages, `build/mermaid.js`), the block's editor side `src/mermaid-block`. Light and dark: `src/mermaid/twins.js` (shared), `src/editor/light-dark.js`, the switch block's editor side `src/light-dark-switch/index.js` and its visitor side `src/light-dark-switch/view.js` (a script module: `npm run build` adds `--experimental-modules`, and `webpack.config.js` gives the module its entry, as its block.json lives in `blocks/`).

```
npm install
npm run build
npx wp-scripts lint-js src
npx wp-scripts lint-style "src/**/*.scss"
npm run test:js        # Style Mermaid's color math, contrast and recipe (node --test tests/js)
```

Font packs (Python 3 with fontTools and Brotli, versions in `bin/font-packs-requirements.txt`): `bin/font-packs.py build <dir>` (set `FONT_PACKS_CACHE=<folder>` to keep the downloads between builds). For the Playwright test, build into a folder and set `WINDROSE_E2E_PACKS` to it.

PHP checks: `php -l`, PHPCS (`phpcs.xml.dist`, WordPress Coding Standards) and PHPStan level 8 (`phpstan.neon.dist`). PHPUnit in multisite mode (`phpunit.xml.dist`) and on a single site (`phpunit-single.xml.dist`, `tests/phpunit/single`) need the WordPress test library: `bin/install-wp-tests.sh` installs it (as CI does), or point `WP_TESTS_DIR` at a copy.

Playwright (`tests/e2e`) runs against a WordPress multisite with this plugin, Ipsum, Windrose Prow for Ipsum and WP-CLI on the same machine, with a Playwright configuration of your own: `baseURL` the network's address, and a network administrator logged in (`storageState`). The specs read `WINDROSE_E2E_HOST` (the network's host, default `localhost`), `WINDROSE_E2E_USER` (the network administrator WP-CLI acts as, default `admin`), `WINDROSE_E2E_WP_PATH` (the WordPress folder for WP-CLI, default `/var/www/html/web`) and `WINDROSE_E2E_PACKS` (built font packs, optional). Screenshots go to Playwright's output folder.

Playwright `tests/e2e/patterns.spec.mjs` (0.6) works on a throwaway site on Windrose Prow for Ipsum with its own title and post, and two test styles: Browse patterns from the row action, the site's real content then sample content (no real title, post or page), switching styles, the other palette, all patterns with the jump list, phone width, axe on the view, no other host, no menu or post made; the preview's stylesheet holds every rule of the site's home page once the style is really applied, with the same presets; Style Mermaid's Patterns view follows the style being made and a stored one; as a visitor of the public block, only `core/` and `ipsum/` patterns with sample content, and refusals (stored style, a made-up nonce, GET, the switch off). Playwright `tests/e2e/blocks.spec.mjs` (0.5) gives every entry of the Blocks step a look and checks each in the preview (and that it scrolls to the block), adds the style to the library, applies it to a throwaway site and checks the same looks on a real page, then opens the Site Editor's Styles, Blocks, Quote; screenshots in its results folder. Playwright `tests/e2e/light-dark.spec.mjs` (0.4) works on two throwaway sites: a variation with dark twins applied with WP-CLI (the switch after the header's menu, by keyboard, the choice applied before the page shows on the next page, no cookie, no outside request; the browser preference followed even without JavaScript), and "Add a dark palette" and "Preview dark" in the Site Editor. `tests/e2e/site-admins.spec.mjs` (0.4) uses a throwaway site and user: no screen while off, the network card, an import into the site's own styles with its fonts in the site's Font Library, their switches only, the Site Editor items, the delete warning, then Copy to the shared library; the network switch is restored. Playwright `tests/e2e/packs.spec.mjs` uploads the Arabic pack, makes a style with its fonts, a Persian font and a size (no request from the browser to another host; only the fonts the preview shows are downloaded), then checks a throwaway site's front end (the Persian paragraph in Vazirmatn, no script font on an English page, `size-adjust` printed by WordPress); skipped without the built packs. Playwright `tests/e2e/mermaid.spec.mjs` makes a style in the admin screen (a light palette for a dark one, a font it installs in the main site's Font Library, Arabic from the font pack when installed) and uses the block on a throwaway site as a visitor (real requests from the server to fonts.bunny.net and Google Fonts); it deletes its variation, its Font Library font, the fonts it fetched (cache entries that were not there before) and the site. Playwright `tests/e2e/livery.spec.mjs` works on two throwaway sites and a test variation, all deleted at the end.

**On GitHub** (`.github/workflows/ci.yml`): `php -l` on PHP 8.4 and 8.5, PHPCS, PHPStan level 8, JavaScript and style lint, JavaScript unit tests, with a fresh build equal to `build/`, PHPUnit multisite and single site on PHP 8.4 and 8.5 against the WordPress nightly. Release: tag `vX.Y.Z` builds `windrose-livery.zip` (`bin/build-zip.sh`) and the 12 font packs (`bin/font-packs.py`).

**Version bump** (every release): the `Version` header and `WINDROSE_LIVERY_VERSION` in `windrose-livery.php`, `Stable tag` and the changelog in `readme.txt`, `version` in `package.json` (and `package-lock.json`), and `version` in `assets/mermaid/packs.json`, which the release compares with the packs it builds (0.4.0's first tag failed on it).

`common/` is Windrose Common (the update library), copied from Windrose Helm with `windrose-helm/bin/sync-common.sh`; never edit it here.

## License

GPL-2.0-or-later.
