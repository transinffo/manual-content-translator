# Manual Content Translator — WP Plugin

**Version:** 1.0.0  
**Requires:** WordPress 6.0+, PHP 8.0+, Polylang 3.x  
**Tested with:** WP 6.9, WooCommerce 10.7, Polylang 3.8.3

---

## Overview

Adds an inline globe-icon button to every `input[type="text"]` and `textarea`
in the WordPress admin. Clicking it opens a Polylang-sourced language picker.
Selecting a language sends the field content through the Google Translate
unofficial API and inserts the translation back into the field.

---

## File Structure

```
manual-content-translator/
├── manual-content-translator.php   # Plugin bootstrap, AJAX endpoints
├── includes/
│   ├── class-mct-polylang.php      # Reads language list from Polylang
│   └── class-mct-translate.php     # Tokeniser + Google Translate proxy
└── assets/
    ├── js/mct-admin.js             # Vanilla JS — UI, AJAX, field I/O
    └── css/mct-admin.css           # Globe button, states, dropdown, tooltip
```

---

## Translation Engine Architecture

Mirrors the Apps Script `CUSTOM_TRANSLATE` approach:

### 1. Tokenisation (PHP, `MCT_Translate::tokenize`)

Content is split into typed tokens:

| Type           | Translated? | Notes                                         |
|----------------|-------------|-----------------------------------------------|
| `TEXT`         | ✅ Yes       | Plain text between tags/shortcodes            |
| `HTML_TAG`     | attrs only  | `alt` and `title` attributes                  |
| `WPBAKERY_TAG` | attrs only  | `title`, `label`, `tab_title`, `section_title`|
| `WP_SHORTCODE` | ❌ No        | `[contact-form-7 ...]` etc.                   |
| `HTML_COMMENT` | ❌ No        | `<!-- ... -->`                                |
| `HTML_ENTITY`  | ❌ No        | `&nbsp;` `&#x2705;` `•` etc.                  |
| `CDATA`        | ❌ No        |                                               |

### 2. Batch translation

- TEXT tokens are joined with `\n↵\n` delimiter, sent as one request.
- If response delimiter count mismatches → one-by-one fallback.
- Respects 4000-char chunk limit.

### 3. Content safety rules

- HTML tags, attributes, classes, IDs, URLs, file names → **never translated**.
- CSS inside `vc_column css=".vc_custom_..."` → skipped (contains `{}`).
- Tailwind `py-[0.2rem]` brackets inside HTML attributes → parser respects
  bracket depth, never breaks on `]`.
- Whitespace (newlines, `&nbsp;`, bullet `•`) → preserved as-is.
- `alt` / `title` attributes on `<img>`, `<a>`, etc. → **translated**.
- WPBakery `title=` / `label=` → **translated** (separate batch).
- HTML comments → **not translated**.

---

## Globe Button States

| State     | Visual              | Behaviour                                  |
|-----------|---------------------|--------------------------------------------|
| Idle      | Blue globe          | Click to open language picker              |
| Loading   | Spinning blue globe | Button disabled during request             |
| Success   | Green pulse         | Auto-resets to idle after 3 s              |
| Error     | Red + shake         | Hover reveals tooltip with error message   |

---

## Supported Fields

Works on any `input[type="text"]` and `textarea` on:

- `post.php` / `post-new.php` — any post type (post, page, product, custom CPT)
- `edit-tags.php` / `term.php` — any taxonomy (category, product_cat, etc.)

Automatically skips: password, hidden, search, email, number inputs and
nonce/system fields.

---

## TinyMCE / Classic Editor

When a textarea is driven by TinyMCE (Classic Editor mode), the plugin:
- Reads content via `tinyMCE.get(id).getContent()` → gets raw HTML.
- Writes back via `editor.setContent(translated)`.
- Falls back to `field.value` when TinyMCE is not active (Text tab / ACF plain).

Gutenberg / Elementor / WPBakery are handled in Classic Editor mode only
(as per project requirements — translation targets the raw textarea).

---

## Rate Limiting

The plugin uses the **unofficial** `translate.googleapis.com/translate_a/single`
endpoint (same as used by Google Translate browser extension). 

**Limits:** Google does not publish official limits for this endpoint.
In practice, ~100–500 requests/day per IP works reliably.
Heavy use (batch content migration) may trigger temporary 429 blocks.

**Mitigation:** The PHP layer returns a clear WP_Error with the HTTP status code,
which the UI displays in the error tooltip.

---

## Security

- All AJAX endpoints check `wp_verify_nonce`.
- Target language is validated against the actual Polylang language list.
- Content is passed raw (not sanitized) to preserve HTML/shortcodes;
  the translated result is inserted directly into the field — no DB write occurs.
- PHP: `wp_remote_get` with 15 s timeout, custom UA string.

---

## Extending

### Add more translatable WPBakery attributes

In `class-mct-translate.php`, add to `TRANSLATABLE_ATTRS`:

```php
private const TRANSLATABLE_ATTRS = [ 'title', 'alt', 'label', 'tab_title', 'section_title', 'description' ];
```

### Add a locale override

In `class-mct-polylang.php`, add to `LOCALE_MAP`:

```php
'uz_UZ' => 'uz',
```
