=== Windrose Livery - Style Variations ===
Contributors: patriciabt
Tags: style variations, block themes, site editor, fonts, multisite
Requires at least: 7.2
Tested up to: 7.2
Requires PHP: 8.4
Stable tag: 0.9.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Style variations kept in the database: make them with Style Mermaid, import, save a site's styles, export, and apply to sites; light and dark palettes with a switch block.

== Description ==

WordPress finds style variations only as files in the active theme's folder: a theme update replaces them, and the Site Editor cannot create one. Windrose Livery keeps style variations in the database instead and adds them to the Site Editor (Styles, Browse styles) next to the theme's own.

* **Import** a variation file (.json, theme.json shape) or a zip holding one variation, optionally `fonts.json` and the font files and license texts they name. Livery checks it first and shows a preview: colors, fonts, the theme it is for, and anything it left out. Nothing is saved until you confirm.
* **Save a site's styles** (what its Site Editor saved: colors, fonts, sizes, block styles) as a variation, from Livery's screen or from the Site Editor itself (Options menu ⋮, Panels, Save styles as a variation). Their fonts stay in the Font Library (Livery keeps its own copy of each file there, so removing a font in Manage fonts never takes it from the variation).
* **Export** a variation as a zip (variation, fonts.json, fonts and licenses), ready to import elsewhere.
* **Apply** a variation to sites from Livery's screen, as picking it in the Site Editor does. The previous styles stay in the Site Editor, Styles, Revisions.
* **New versions**: importing a variation with the same title for the same theme replaces it (after a tick) as a new version. Picking a variation copies it into a site's styles, so a site keeps the version it picked; Livery lists the sites on an older version and applies the new one when you ask.
* Each variation is made for one theme and shows on that theme and its child themes (for example a variation for Ipsum also shows on a child theme of Ipsum).
* **Style Mermaid** makes a variation for the Ipsum theme, with a live preview: start from one of 20 ready styles (or Ipsum itself); choose fonts (37 families in the plugin, pairs from the ready styles, any font installed in the site's Font Library, font pairs for Arabic, Hebrew, Devanagari, Thai, Bengali, Tamil, Gujarati, Gurmukhi, Georgian, Armenian, Ethiopic, Cyrillic and Greek); browse every preset palette, filter them by accent color, or get five moods from one accent; edit colors as in the Site Editor (palette, text, links, headings, captions, gradients, duotone), with WCAG 2.2 contrast checks and a Fix button; typography, widths and spacing; then a look for a few blocks (buttons, corners, images, quotes, separator, menu, code), saved in the style as the Site Editor's own block styles, so you can keep changing them there. Add the result to the library, or download it as a zip.
* **Browse patterns**: see the site's patterns (the theme's, WordPress's and plugins') with a stored style, or the one Style Mermaid is making, applied. WordPress itself draws them on the site you choose, for the preview only: nothing is saved and the site keeps its own styles. Patterns are grouped by category, with a list to jump to each; "Sample content" shows what visitors see: sample text, menu and posts instead of the site's. Also in Style Mermaid's preview ("Patterns").
* **Style Mermaid on a public page**: the block "Style Mermaid" lets visitors make a style and download it, with no account. It is off until a site's switch is turned on in Livery's screen.
* **Light and dark**: a style can keep a second palette, a light or dark "twin" of each colour ("Dark: Background"...), in the Site Editor's own palette. Style Mermaid and the Site Editor (Options ⋮, Add a dark palette) calculate them from the colours and check their contrast first. Each site can show the block "Light and dark switch" (placed after the header's menu, or anywhere, also inside a Navigation block) and follow the visitor's browser preference. The style's own colours stay the default; a visitor's choice stays in their browser. Gradients made from the palette follow; images, duotone and colours typed by hand do not.
* **Site administrators** (networks, off until a network administrator turns it on): each site's administrators get Appearance, Style variations on their own site: Style Mermaid, import, export and delete of their site's own styles, "Save styles as a variation" in their Site Editor, and their site's switches. Their styles and fonts stay on their site; a network administrator can copy one into the shared library.
* **Fonts in the Font Library**: the fonts of a stored variation are installed in the Font Library of its site (the main site for the shared library), where WordPress puts installed fonts, and show in Styles, Typography, Fonts. Deleting one there that a variation or a site uses asks again first.
* **Font packs**: fonts for 12 other scripts (Arabic with Persian and Urdu, Hebrew, Devanagari, Thai, Bengali, Tamil, Gujarati, Gurmukhi, Georgian, Armenian, Ethiopic, Cyrillic and Greek), installed once on a click (or uploaded) and used by Style Mermaid without Google Fonts or Bunny Fonts. Each pack is small (4.4 MB for all 12) and holds only the script's own letters.

One style serves every language: each font list continues with the chosen fonts for other scripts, so each letter comes from the first font that has it, and a browser downloads a script's font only when a page holds that script. Chinese, Japanese and Korean use the visitor's own fonts.

Languages that share a script can have their own font (Persian and Urdu next to Arabic; Japanese and Korean next to Chinese): the style keeps a rule per language, and pages in that language, or parts of a page marked with that language, use that font; other pages never download it. Scripts that look small next to Latin letters (Arabic) get a size of their own.

On a network, the library is shared: network administrators manage it in Network Admin, Themes, Style variations, and every site using the right theme sees the variations in its Site Editor. Where the network allows it, each site can also keep its own styles. On a single site: Appearance, Style variations, for administrators.

Safety: only theme.json data is kept (no PHP, no JavaScript), checked with WordPress's own rules for unsafe CSS values; nothing that loads a file (`url()`, background images); custom CSS only for users who may edit CSS and add unfiltered HTML; font files recognized by their content (WOFF2, WOFF, TrueType, OpenType), at most 10 MB each; zips are read in memory, never unpacked to disk, and a font is only looked up inside the variation's folder.

== Privacy ==

Windrose Livery sets no cookies. Fonts are stored in the uploads folder of this website (the Font Library's folder, `uploads/fonts/` or `uploads/sites/<id>/fonts/`; packs and Style Mermaid's downloads in `uploads/windrose-livery/`) and served from it; a page never loads a font from another host.

Where a site shows the light and dark switch, a visitor's choice is kept in their own browser (local storage, not a cookie) and never sent anywhere. The same goes for the style a visitor makes in Style Mermaid when they open the site's pattern library from it.

Where a site turns Style Mermaid on for visitors, the choices a visitor makes are sent to this website to build the preview and the download, and are not stored; the same goes for the patterns it shows with that style, which use sample text instead of the site's content, also when another plugin's pattern library (such as Windrose Knotboard) shows its patterns in a chosen style. To limit how often one connection can use it (20 downloads, 20 new fonts and 600 previews an hour, pages of patterns included), a keyed hash of the visitor's IP address (not the address) and a counter are kept for one hour. The site's privacy policy guide (Settings, Privacy) has a paragraph about it.

== External services ==

* **Google Fonts and Bunny Fonts**, only when a visitor of a public Style Mermaid clicks "Get the font" or picks fonts for another script, where the site's second switch is on (set by network administrators). Since 0.4 the people who manage styles install fonts in the Site Editor's Font Library instead (WordPress's own Install Fonts). This website then downloads the font's CSS (fonts.googleapis.com or fonts.bunny.net) and its WOFF2 files (fonts.gstatic.com or fonts.bunny.net) once, keeps them in `uploads/windrose-livery/cache/` and serves them itself. Sent: the font's name and weights, and a browser user agent; nothing about the visitor. Google Fonts terms: https://developers.google.com/fonts/terms, privacy: https://policies.google.com/privacy. Bunny Fonts: https://fonts.bunny.net/about, privacy: https://bunny.net/privacy
* **GitHub**, only when the people who manage the library click "Install" for a font pack: this website asks the GitHub API (api.github.com) for the release of this Livery version and downloads the pack from GitHub's release file host, then checks it against the checksum GitHub publishes. Sent: the repository name, a "Windrose" User-Agent. "Upload a pack" needs no request.
* The bundled update library contacts the GitHub API (api.github.com) to check for and download updates of Windrose plugins: sent are the repository name and a "Windrose" User-Agent. GitHub terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service, privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Frequently Asked Questions ==

= Does a change to a variation reach the sites that use it? =

Not by itself: picking a variation copies it into the site's styles (WordPress does the same with a theme's variations). Livery lists the sites on an older version and applies the new version when you ask.

= What happens when I delete a variation? =

It disappears from the Site Editor. Sites that use it keep their styles, and the font files a site still uses stay (with their licenses).

= Does it work on a site with its own domain on a network? =

The shared library's fonts are served from the main site's address. A site on another domain may need a CORS header for font files on the main site (not set by Livery). A site's own styles use fonts from its own address.

== Uninstall ==

Uninstalling removes the stored variations (shared and sites' own), each site's record of the variation it uses, each site's Style Mermaid switches and light and dark options, the network switch, the fonts Style Mermaid downloaded (`uploads/windrose-livery/cache/`) and the installed font packs (`uploads/windrose-livery/packs/`). Fonts in the Font Library stay, as ordinary installed fonts: sites that applied a variation load them from there. Twin colours stay in sites' styles as plain colours.

== Changelog ==

= 0.9.4 =
* Windrose Common 1.3.0: updates come from the public GitHub repositories, without a token (WordPress 7.2 RC no longer has the Secrets API, where the token was kept).
* Font packs install from the public repository, without a token.

= 0.9.3 =
* Light and dark switch: runs on WordPress's Interactivity API as a script module; same behavior (the small script in the page head that avoids a flash is unchanged).
* Code: all WordPress packages on their newest releases.

= 0.9.2 =
* Styles with language rules (the design kit's Japanese and Korean rules): whenever a style enters the library (import, a site's styles saved as a style, a copy to the shared library), a font that serves only its language rule gets the language in its family name (for example noto-sans-jp-lang-ja), as Style Mermaid writes it since 0.9.1, so the rule also works when a site administrator applies the style. A font the style also uses elsewhere keeps its name. Styles already in the library: import them again with Replace.

= 0.9.1 =
* Language fonts (Persian, Urdu…) now work for site administrators too: the language is part of the language font's family name in the style (for example vazirmatn-lang-fa), which WordPress keeps for everybody, instead of a custom setting it removes for anyone who may not add unfiltered HTML. Styles made before 0.9.1, and imported styles whose rules are only in settings.custom (the design kit's Japanese and Korean rules), keep working when a network administrator applies them; a site administrator needs a style made again in Style Mermaid (Persian, Urdu).
* Style Mermaid: "Add and use on this site" says the style is used when WordPress removed some of it, and then says what was removed.
* Public Style Mermaid: the visitor limits count an IPv6 /64 as one visitor.
* Fonts from Google or Bunny Fonts: cached files that no cache entry names any more are removed after an hour.
* A site's own Livery screen reads that site only, not every site of the network.
* Removed: the move of fonts from versions before 0.4 (the notice on the network screen and `wp windrose-livery migrate-fonts`). A site still running a version before 0.4 updates to 0.9.0 first, moves its fonts, then updates.
* Windrose Common 1.2.0.

= 0.9.0 =
* Ipsum's new color names (WordPress 7.2-beta4): Ipsum now uses the same color names as Twenty Twenty-Five (base, contrast, accent-1, accent-3, accent-5, accent-6). Style Mermaid, the ready styles, the preset palettes, the contrast checks and the light and dark twins use them, with Ipsum's labels: Base, Contrast, Accent, Strong Contrast, Surface, Border. Styles, saved styles and content made with the old names (theme-1 to theme-6) are renamed with the WP-CLI command `wp windrose-livery rename-colors` (a dry run; `--network` for every site, `--run` to rename; back up the database first). Old files are not converted on import.
* Import: the theme a file was made for is guessed from all its preset names (colors, font sizes, font families, spacing), since Ipsum and Twenty Twenty-Five now share their color names. On a tie, the library site's active theme.

= 0.8.0 =
* Contrast: the accent must read as text on the background (it was only a warning), and two more pairs are checked, the background color on the accent and on the strong color, so patterns can set text on Accent and Strong sections in any style that passes. Style Mermaid's Bold mood keeps its accent readable both ways (its accent buttons take the background color as text). All ready styles and preset palettes pass.

= 0.7.0 =
* Pattern libraries of other plugins (for Windrose Knotboard): the pattern preview can show a list of patterns another plugin offers, with the chosen style, as a list with names or as one page. Visitors of that plugin's public library get such previews even where Style Mermaid is not public: only with a style that is not saved, with sample content, and only the patterns that plugin offers them.
* The ready styles are also available as finished recipes, so such a library can show its patterns in any of them.
* Style Mermaid: "See the patterns in this style" where the site has a pattern library (Windrose Knotboard). The style being made stays in the visitor's browser and the library's previews use it, also while it changes.
* Fix: font files of a site's Font Library are found whatever the uploads folder is called (a site with the UPLOADS setting lost its fonts in "Save styles as a variation").

= 0.6.0 =
* Browse patterns: the site's patterns with a stored style applied (an action on each style, also for sites' own styles), drawn by WordPress itself on a site you choose, for the preview only; switch between styles, categories, wide and phone width, the other palette; a list to jump to each pattern.
* Style Mermaid's preview shows the patterns too ("Sample page" or "Patterns"), with the style being made or a stored one.
* Sample content: placeholders for the site's title, menu, logo and posts, never saved. Visitors of a public Style Mermaid always see it, with only the Ipsum theme's and WordPress's patterns: what a site shows with a new style before adding its own.

= 0.5.0 =
* Style Mermaid, Blocks step (was Buttons): a look for buttons (now also their hover colour and size), corners (one choice for images, featured images, covers, quotes, pullquotes and code), images (frame: none, line, shadow), quotes and pullquotes (soft background, lines, plain), the separator (color, thickness), the menu (letters, weight, space between items) and code (line or soft background). Each look is saved in the style's theme.json, so the Site Editor's Styles, Blocks shows it and can change it further, and everyone who imports the style gets all of it (no custom CSS).
* Buttons: focus and pressed colors follow the chosen colors.
* The preview's button for the other palette is now called "Dark/light switch".
* The preview shows more blocks (a separator, a pullquote, a cover, a real image) and scrolls to the block whose look you are choosing.

= 0.4.0 =
* Light and dark: twin colours for each colour of a style, in the palette; "Add a dark palette" (or light) in Style Mermaid and in the Site Editor (Options ⋮), contrast checked for both schemes before adding; "Preview dark" in the Site Editor; the block "Light and dark switch" (after the header's menu while on, or anywhere); per site: show the switch, follow the browser preference. The 20 ready styles have their twins.
* Site administrators may keep their own styles on their site, when a network administrator allows it (off by default); network administrators can copy them into the shared library.
* Fonts now live in WordPress's Font Library (the main site's for the shared library, a site's own for its styles), and deleting one that is in use asks again. Livery's screen moves the fonts of earlier versions there (Check, then Move; or `wp windrose-livery migrate-fonts --run`).
* Style Mermaid for managers takes fonts from the Font Library instead of Google Fonts and Bunny Fonts; "Add and use on this site" on a site's own screen.

= 0.3.0 =
* Font packs: fonts for 12 other scripts without Google Fonts or Bunny Fonts, from this version's GitHub release ("Install", checksum checked) or uploaded by hand ("Upload a pack"); a "Font packs" card on Livery's screen, and "Install" in Style Mermaid's Fonts step. Styles keep their own copies of the fonts, so removing a pack changes no style.
* Language rules: a font for one language of a script (Persian: Vazirmatn; Urdu: Noto Nastaliq Urdu), used only on pages and page parts in that language; also for styles made elsewhere (the design kit's styles use it to show Japanese and Korean in their own fonts).
* Size next to Latin text for each script (font size-adjust), measured for every pack font; Livery now keeps `sizeAdjust` and the three line overrides of a font face on import.
* Visitors of a public Style Mermaid can use installed font packs.

= 0.2.0 =
* Style Mermaid, part 1: make a style variation for Ipsum with a live preview, in Livery's screen ("Create a style": add to the library or download) and as a block for public pages (download only, off until a site's switch is on).
* 37 Latin font families in the plugin (with Manrope and JetBrains Mono, which Ipsum's blocks use), any font from Google Fonts or Bunny Fonts (type its name or paste the address of its page; it is used at once for headings, text or both), and font pairs for 12 other scripts, downloaded once by this website and served from it.
* Colors: every preset palette (20 ready styles, Ipsum's 7, your library), a filter by accent color, five moods from one accent (computed in OKLCH), editing as in the Site Editor with gradients and duotone, WCAG 2.2 contrast checks with a fix.
* Typography of text, links, headings, captions and buttons; text width (640, 860, 980 px or any), wide width (1080, 1280, 1440 px or any), spacing scale, block spacing, page padding; button shape, fill and color.
* Font lists may now be up to 600 characters long (fonts for several scripts).

= 0.1.1 =
* Site Editor: "Save styles as a variation" in the Options menu (⋮, Panels), for the people who manage the library; the new variation appears in Browse styles at once.
* Fix: after picking a variation with fonts, opening Typography, Manage fonts crashed the Site Editor until the styles were saved. Variations now reach the Site Editor, and are applied, exactly as WordPress gives a theme's own variation files (colors and fonts grouped by origin). Sites that applied a variation with 0.1.0: apply it again.
* Fix: font addresses kept the site's https address when a variation was applied from WP-CLI.

= 0.1.0 =
* First version: import with a preview, save a site's styles, export, apply to sites, new versions, variations shown on their theme and its child themes, fonts served from this website.
