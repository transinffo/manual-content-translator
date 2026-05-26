<?php
/**
 * MCT_Polylang — wrapper around Polylang's language API.
 *
 * Returns a normalised list of languages configured in Polylang,
 * with the short ISO-639-1 locale code that Google Translate expects.
 */

defined( 'ABSPATH' ) || exit;

final class MCT_Polylang {

    /**
     * Map of WP locale codes → Google Translate language codes
     * where they differ from the simple ISO-639-1 prefix.
     *
     * Most locales map cleanly (uk → uk, ru_RU → ru, de_DE → de).
     * Exceptions: zh_CN → zh-CN, zh_TW → zh-TW, pt_BR → pt, etc.
     *
     * @var array<string,string>
     */
    private const LOCALE_MAP = [
        'zh_CN'     => 'zh-CN',
        'zh_TW'     => 'zh-TW',
        'zh_HK'     => 'zh-TW',
        'pt_BR'     => 'pt',
        'pt_PT'     => 'pt',
        'sr_RS'     => 'sr',
        'bs_BA'     => 'bs',
        'nb_NO'     => 'no',
        'nn_NO'     => 'no',
        'hy_AM'     => 'hy',
        'ka_GE'     => 'ka',
        'mk_MK'     => 'mk',
    ];

    /**
     * Returns the list of languages registered in Polylang.
     *
     * Each item:
     *   [
     *     'name'         => 'Українська',
     *     'slug'         => 'uk',           // Polylang language slug
     *     'locale'       => 'uk',           // WP locale
     *     'locale_short' => 'uk',           // Google Translate code
     *     'flag_url'     => 'https://...',  // optional flag image
     *     'is_default'   => true|false,
     *   ]
     *
     * Falls back gracefully when Polylang is not active.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_languages(): array {
        // Polylang classic function API (pll_the_languages is front-end only).
        if ( ! function_exists( 'PLL' ) && ! class_exists( 'PLL_Model' ) ) {
            return self::fallback_languages();
        }

        // Prefer the object API — works in both admin and front-end.
        $pll_languages = [];

        if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
            /** @var PLL_Language[] $raw_languages */
            $raw_languages = PLL()->model->get_languages_list();
            if ( ! empty( $raw_languages ) ) {
                $pll_languages = $raw_languages;
            }
        }

        // Fallback to pll_languages_list() helper if available.
        if ( empty( $pll_languages ) && function_exists( 'pll_languages_list' ) ) {
            $slugs = pll_languages_list( [ 'fields' => 'slug' ] );
            foreach ( $slugs as $slug ) {
                // Build a minimal object with just the slug.
                $obj        = new stdClass();
                $obj->slug  = $slug;
                $obj->name  = $slug;
                $obj->locale = $slug;
                $pll_languages[] = $obj;
            }
        }

        if ( empty( $pll_languages ) ) {
            return self::fallback_languages();
        }

        $default_slug = function_exists( 'pll_default_language' )
            ? pll_default_language( 'slug' )
            : '';

        $result = [];
        foreach ( $pll_languages as $lang ) {
            $locale       = $lang->locale ?? $lang->slug;
            $locale_short = self::resolve_google_code( $locale );

            $result[] = [
                'name'         => $lang->name,
                'slug'         => $lang->slug,
                'locale'       => $locale,
                'locale_short' => $locale_short,
                'flag_url'     => $lang->flag_url ?? '',
                'is_default'   => ( $lang->slug === $default_slug ),
            ];
        }

        return $result;
    }

    /**
     * Converts a WP locale string (e.g. "ru_RU", "uk", "de_DE")
     * into the Google Translate language code (e.g. "ru", "uk", "de").
     */
    public static function resolve_google_code( string $locale ): string {
        if ( isset( self::LOCALE_MAP[ $locale ] ) ) {
            return self::LOCALE_MAP[ $locale ];
        }

        // Standard pattern: take the part before the underscore.
        // "ru_RU" → "ru", "de_DE" → "de", "uk" → "uk"
        $parts = explode( '_', $locale );
        return strtolower( $parts[0] );
    }

    /**
     * Returns a sensible fallback when Polylang is not available.
     * The UI will still load but only with these two languages.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fallback_languages(): array {
        return [
            [
                'name'         => 'English',
                'slug'         => 'en',
                'locale'       => 'en_US',
                'locale_short' => 'en',
                'flag_url'     => '',
                'is_default'   => false,
            ],
        ];
    }
}
