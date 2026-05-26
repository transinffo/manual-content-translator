/**
 * MCT Admin JS — Manual Content Translator
 *
 * Architecture (mirrors Apps Script CUSTOM_TRANSLATE):
 *   1. On DOMContentLoaded + MutationObserver — inject globe icon buttons
 *      next to all relevant input[type=text] and textarea fields.
 *   2. User clicks the globe → dropdown with Polylang languages appears.
 *   3. User picks a language → AJAX call to PHP proxy → Google Translate.
 *   4. Globe state: blue (idle) → spinning (translating) → green (done) / red+tooltip (error).
 *
 * Key design decisions:
 *   - Pure vanilla ES6+, zero dependencies.
 *   - MutationObserver handles dynamically inserted fields (ACF, Gutenberg meta, etc.).
 *   - The content tokeniser in JS only needs to strip HTML for the language-detection
 *     snippet — full tokenisation is done server-side in PHP.
 *   - Whitelist-based field detection avoids attaching to password/hidden/search inputs.
 */

( function () {
    'use strict';

    /* ── Config ────────────────────────────────────────────────────────────── */

    const CFG         = window.MCT || {};
    const LANGUAGES   = CFG.languages   || [];
    const AJAX_URL    = CFG.ajax_url    || '';
    const NONCE       = CFG.nonce       || '';
    const i18n        = CFG.i18n        || {};
    const ACT_TRANS   = CFG.action_translate || 'mct_translate';
    const ACT_DETECT  = CFG.action_detect    || 'mct_detect_lang';

    /** Selectors for fields we attach to. */
    const FIELD_SELECTOR = [
        'input[type="text"]',
        'textarea',
    ].join( ', ' );

    /**
     * Selectors / name patterns we should NOT attach to.
     * (password, hidden, nonce, _wp_http_referer, etc.)
     */
    const SKIP_NAMES = /^(_wp|action|nonce|post_ID|original_post_status|referredby|auto_draft|user_login|user_pass|pass|email|search)/i;
    const SKIP_IDS   = /^(search|user_login|user_pass|pass1|pass2)/i;

    /* ── State ──────────────────────────────────────────────────────────────── */

    /** Weak set of already-decorated fields. */
    const decorated = new WeakSet();

    /** Currently open dropdown wrapper (only one at a time). */
    let openDropdown = null;

    /* ── Init ───────────────────────────────────────────────────────────────── */

    function init() {
        if ( ! LANGUAGES.length || ! AJAX_URL ) return;

        decorateFields( document.body );

        // Watch for dynamic fields (ACF, Gutenberg meta boxes, WPBakery popups…).
        const observer = new MutationObserver( mutations => {
            for ( const m of mutations ) {
                for ( const node of m.addedNodes ) {
                    if ( node.nodeType === 1 ) {
                        decorateFields( node );
                    }
                }
            }
        } );

        observer.observe( document.body, { childList: true, subtree: true } );

        // Close open dropdown on outside click.
        document.addEventListener( 'click', onDocumentClick );
    }

    /* ── Field decoration ───────────────────────────────────────────────────── */

    function decorateFields( root ) {
        const fields = root.matches( FIELD_SELECTOR )
            ? [ root, ...root.querySelectorAll( FIELD_SELECTOR ) ]
            : root.querySelectorAll( FIELD_SELECTOR );

        for ( const field of fields ) {
            attachGlobe( field );
        }
    }

    function shouldSkip( field ) {
        if ( decorated.has( field ) )                               return true;
        if ( field.type === 'hidden' )                              return true;
        if ( field.type === 'password' )                            return true;
        if ( field.type === 'search' )                              return true;
        if ( field.type === 'email' )                               return true;
        if ( field.type === 'number' )                              return true;
        if ( field.readOnly || field.disabled )                     return true;
        if ( field.name  && SKIP_NAMES.test( field.name ) )        return true;
        if ( field.id    && SKIP_IDS.test( field.id ) )             return true;
        // Skip very small inputs (e.g. post slug, order qty).
        if ( field.tagName === 'INPUT' && ( field.size < 8 ) )      return true;
        return false;
    }

    function attachGlobe( field ) {
        if ( shouldSkip( field ) ) return;
        decorated.add( field );

        // Ensure the parent has position context.
        const parent = field.parentElement;
        if ( ! parent ) return;

        const style = getComputedStyle( parent );
        if ( style.position === 'static' ) {
            parent.style.position = 'relative';
        }

        // Create wrapper + globe button + dropdown.
        const wrapper  = createWrapper( field );
        const btn      = createGlobeButton();
        const dropdown = createDropdown( field, btn );

        wrapper.appendChild( btn );
        wrapper.appendChild( dropdown );
        parent.insertBefore( wrapper, field.nextSibling );

        btn.addEventListener( 'click', e => {
            e.stopPropagation();
            toggleDropdown( dropdown, btn, field );
        } );
    }

    /** Inserts a transparent overlay wrapper positioned over the field's top-right corner. */
    function createWrapper( field ) {
        const w = document.createElement( 'div' );
        w.className = 'mct-wrapper';
        return w;
    }

    /* ── Globe button ───────────────────────────────────────────────────────── */

    function createGlobeButton() {
        const btn = document.createElement( 'button' );
        btn.type      = 'button';
        btn.className = 'mct-globe-btn';
        btn.title     = i18n.translate || 'Translate';
        btn.setAttribute( 'aria-label', i18n.translate || 'Translate' );
        btn.innerHTML = svgGlobe();
        return btn;
    }

    /**
     * Inline SVG — stylised globe with meridians and parallels.
     * Uses currentColor so CSS can control fill/stroke.
     */
    function svgGlobe() {
        return `<svg class="mct-globe-svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
  <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.6" fill="none"/>
  <!-- vertical meridians -->
  <ellipse cx="12" cy="12" rx="4.5" ry="10" stroke="currentColor" stroke-width="1.2" fill="none"/>
  <line x1="12" y1="2" x2="12" y2="22" stroke="currentColor" stroke-width="1.2"/>
  <!-- horizontal parallels -->
  <line x1="2.2" y1="8.5" x2="21.8" y2="8.5"  stroke="currentColor" stroke-width="1.2"/>
  <line x1="2.2" y1="15.5" x2="21.8" y2="15.5" stroke="currentColor" stroke-width="1.2"/>
  <!-- equator -->
  <line x1="2" y1="12" x2="22" y2="12" stroke="currentColor" stroke-width="1.2"/>
</svg>`;
    }

    /* ── Dropdown ───────────────────────────────────────────────────────────── */

    function createDropdown( field, btn ) {
        const dd = document.createElement( 'div' );
        dd.className = 'mct-dropdown';
        dd.setAttribute( 'role', 'listbox' );
        dd.setAttribute( 'aria-label', i18n.select_lang || 'Select target language' );
        dd.hidden = true;

        for ( const lang of LANGUAGES ) {
            const item = document.createElement( 'button' );
            item.type      = 'button';
            item.role      = 'option';
            item.className = 'mct-lang-item';
            item.dataset.code = lang.locale_short;

            if ( lang.flag_url ) {
                const flag = document.createElement( 'img' );
                flag.src    = lang.flag_url;
                flag.alt    = '';
                flag.width  = 16;
                flag.height = 11;
                flag.className = 'mct-flag';
                item.appendChild( flag );
            }

            item.appendChild( document.createTextNode( lang.name ) );

            item.addEventListener( 'click', e => {
                e.stopPropagation();
                dd.hidden = true;
                openDropdown = null;
                startTranslation( field, btn, lang.locale_short );
            } );

            dd.appendChild( item );
        }

        return dd;
    }

    function toggleDropdown( dropdown, btn, field ) {
        // Close any other open dropdown first.
        if ( openDropdown && openDropdown !== dropdown ) {
            openDropdown.hidden = true;
        }

        const willOpen = dropdown.hidden;
        dropdown.hidden = ! willOpen;
        openDropdown    = willOpen ? dropdown : null;

        if ( willOpen ) {
            // Auto-detect language in background so we can pre-select an intelligent default.
            autoDetectAndMark( field, dropdown );
        }
    }

    function onDocumentClick() {
        if ( openDropdown ) {
            openDropdown.hidden = true;
            openDropdown = null;
        }
    }

    /* ── Auto-detection ─────────────────────────────────────────────────────── */

    /**
     * Sends a snippet from the field to PHP for language detection.
     * Marks the corresponding dropdown item as "current source" via data attribute.
     */
    function autoDetectAndMark( field, dropdown ) {
        const text = getFieldText( field );
        const snippet = stripMarkup( text ).slice( 0, 200 ).trim();

        if ( ! snippet ) return;

        ajaxPost( ACT_DETECT, { snippet } )
            .then( data => {
                if ( data.success && data.data && data.data.lang ) {
                    const detectedCode = data.data.lang;
                    // Store on dropdown for later use during translation.
                    dropdown.dataset.sourceLang = detectedCode;

                    // Highlight the detected language in the list (subtle, not pre-select).
                    const items = dropdown.querySelectorAll( '.mct-lang-item' );
                    for ( const item of items ) {
                        item.classList.toggle( 'mct-lang-source', item.dataset.code === detectedCode );
                    }
                }
            } )
            .catch( () => { /* silent */ } );
    }

    /* ── Translation ────────────────────────────────────────────────────────── */

    /**
     * Returns true when the plain-text content contains only Latin-script characters
     * (letters, digits, punctuation, whitespace) — no Cyrillic or other non-Latin scripts.
     * Such values are typically brand / model names that must not be translated.
     *
     * "Walkera Vitos 101"      → true  (skip)
     * "Дрон Walkera Vitos 101" → false (translate normally — contains Cyrillic)
     *
     * Uses Unicode property escapes (\p{Script=Latin}, flag 'u') — no fragile
     * hard-coded character ranges.
     *
     * @param {string} text  Raw field content (HTML allowed; stripped internally).
     * @returns {boolean}
     */
    function isLatinOnly( text ) {
        const plain = stripMarkup( text ).trim();
        if ( ! plain ) return false;
        // Must contain at least one Latin letter AND no characters outside
        // Latin letters, digits, punctuation, whitespace, or symbols.
        return (
            /\p{Script=Latin}/u.test( plain ) &&
            ! /[^\p{Script=Latin}\p{N}\p{P}\p{Z}\p{S}]/u.test( plain )
        );
    }

    async function startTranslation( field, btn, toLang ) {
        const content = getFieldText( field );

        if ( ! content.trim() ) {
            setGlobeState( btn, 'error', i18n.error_empty || 'Field is empty.' );
            return;
        }

        // Skip fields whose content is entirely Latin-script (brand / model names).
        // Fields mixing Latin with Cyrillic (or other scripts) are translated normally.
        if ( isLatinOnly( content ) ) {
            setGlobeState( btn, 'idle' );
            return;
        }

        // Determine source lang: use detection result if available.
        const wrapper    = btn.closest( '.mct-wrapper' );
        const dropdown   = wrapper ? wrapper.querySelector( '.mct-dropdown' ) : null;
        const sourceLang = dropdown?.dataset.sourceLang || 'auto';

        setGlobeState( btn, 'loading' );

        try {
            const data = await ajaxPost( ACT_TRANS, {
                content,
                from_lang: sourceLang,
                to_lang:   toLang,
            } );

            if ( data.success && data.data && data.data.translated ) {
                setFieldText( field, data.data.translated );
                setGlobeState( btn, 'success' );
            } else {
                const msg = data.data?.message || ( i18n.error_generic || 'Translation error.' );
                setGlobeState( btn, 'error', msg );
            }
        } catch ( err ) {
            setGlobeState( btn, 'error', err.message || ( i18n.error_generic || 'Request failed.' ) );
        }
    }

    /* ── Globe states ───────────────────────────────────────────────────────── */

    /**
     * @param {HTMLElement} btn
     * @param {'idle'|'loading'|'success'|'error'} state
     * @param {string} [errorMsg]
     */
    function setGlobeState( btn, state, errorMsg = '' ) {
        btn.classList.remove( 'mct-state-loading', 'mct-state-success', 'mct-state-error' );
        btn.disabled = false;
        btn.title    = i18n.translate || 'Translate';

        // Remove existing tooltip.
        const existingTooltip = btn.parentElement?.querySelector( '.mct-tooltip' );
        if ( existingTooltip ) existingTooltip.remove();

        switch ( state ) {
            case 'loading':
                btn.classList.add( 'mct-state-loading' );
                btn.disabled = true;
                btn.title    = i18n.translating || 'Translating…';
                break;

            case 'success':
                btn.classList.add( 'mct-state-success' );
                // Auto-reset to idle after 3 seconds.
                setTimeout( () => {
                    btn.classList.remove( 'mct-state-success' );
                }, 3000 );
                break;

            case 'error':
                btn.classList.add( 'mct-state-error' );
                if ( errorMsg ) {
                    const tooltip = createTooltip( errorMsg );
                    btn.parentElement.appendChild( tooltip );
                    btn.title = errorMsg;
                }
                break;
        }
    }

    function createTooltip( message ) {
        const t = document.createElement( 'div' );
        t.className   = 'mct-tooltip';
        t.textContent = message;
        t.setAttribute( 'role', 'alert' );
        return t;
    }

    /* ── Field read / write ─────────────────────────────────────────────────── */

    /**
     * Reads the current value of a field.
     * For textarea that may be a TinyMCE editor, reads the active editor content.
     */
    function getFieldText( field ) {
        // TinyMCE / Classic editor: if the field is controlled by an active editor,
        // get content from the editor instance.
        if ( field.tagName === 'TEXTAREA' ) {
            const editorId = field.id;
            if ( editorId && typeof window.tinyMCE !== 'undefined' ) {
                const editor = window.tinyMCE.get( editorId );
                if ( editor && ! editor.isHidden() ) {
                    return editor.getContent(); // returns HTML
                }
            }
        }
        return field.value;
    }

    /**
     * Writes translated content back to the field.
     * Syncs with TinyMCE if active.
     * Permanently adds the CSS class "translated" to the field on success.
     */
    function setFieldText( field, value ) {
        field.value = value;

        if ( field.tagName === 'TEXTAREA' ) {
            const editorId = field.id;
            if ( editorId && typeof window.tinyMCE !== 'undefined' ) {
                const editor = window.tinyMCE.get( editorId );
                if ( editor && ! editor.isHidden() ) {
                    editor.setContent( value );
                }
            }
        }

        // Mark the field as successfully translated (permanent — never removed).
        field.classList.add( 'translated' );

        // Trigger change/input events so React/Vue state and WP field trackers pick up the change.
        field.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
        field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    }

    /* ── Helpers ────────────────────────────────────────────────────────────── */

    /**
     * Strips HTML tags, shortcodes, and HTML entities from text
     * to produce a plain-text snippet suitable for language detection.
     */
    function stripMarkup( text ) {
        return text
            .replace( /<!--[\s\S]*?-->/g, ' ' )         // HTML comments
            .replace( /<[^>]+>/g, ' ' )                  // HTML tags
            .replace( /\[[^\]]+\]/g, ' ' )               // shortcodes
            .replace( /&[a-zA-Z#][a-zA-Z0-9]*;/g, ' ' ) // HTML entities
            .replace( /\s+/g, ' ' )
            .trim();
    }

    /**
     * Simple AJAX POST wrapper — returns a Promise resolving to parsed JSON.
     *
     * @param {string} action
     * @param {Object} params
     * @returns {Promise<Object>}
     */
    function ajaxPost( action, params ) {
        const body = new URLSearchParams( {
            action,
            nonce: NONCE,
            ...params,
        } );

        return fetch( AJAX_URL, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:        body.toString(),
        } )
            .then( res => {
                if ( ! res.ok ) {
                    throw new Error( `HTTP ${ res.status }` );
                }
                return res.json();
            } );
    }

    /* ── Boot ───────────────────────────────────────────────────────────────── */

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
