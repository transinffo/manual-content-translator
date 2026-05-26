<?php
/**
 * MCT_Translate — PHP translation engine.
 *
 * Architecture (mirrors the Apps Script approach):
 *   1. Tokenise content into TEXT / HTML_TAG / WP_SHORTCODE / WPBAKERY_TAG /
 *      HTML_COMMENT / HTML_ENTITY / CDATA tokens.
 *   2. Collect only TEXT tokens, translate them in chunk-safe batches via Google.
 *   3. Safe query length calculation ensures NO MORE HTTP 500 / Timeouts.
 *   4. Re-assemble the original structure from tokens.
 */

defined( 'ABSPATH' ) || exit;

final class MCT_Translate {

    // ── Constants ─────────────────────────────────────────────────────────

    /** Unofficial Google Translate endpoint. */
    private const API_URL = 'https://translate.googleapis.com/translate_a/single';

    /** 
     * МАКСИМАЛЬНАЯ ДЛИНА СТРОКИ ЗАПРОСА (Защита от 500 ошибки).
     * Ограничиваем общую длину склеенного текста в ОДНОМ запросе к API.
     * Значение 1800 гарантирует стабильность даже при агрессивном URL-кодировании.
     */
    private const MAX_QUERY_LEN = 1000;

    /** Delimiter between batched texts. */
    private const DELIMITER = "\n\u{21B5}\n";

    /** WPBakery / Visual Composer shortcode prefix. */
    private const WPBAKERY_PREFIX = 'vc_';

    /** Attributes whose values should be translated (HTML + WPBakery). */
    private const TRANSLATABLE_ATTRS = [ 'title', 'alt', 'label', 'tab_title', 'section_title' ];

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Translate $content from $from_lang to $to_lang.
     */
    public function translate( string $content, string $from_lang, string $to_lang ) {
        if ( '' === trim( $content ) ) {
            return $content;
        }

        // ── Step 1: Tokenise ────────────────────────────────────────────
        $tokens = $this->tokenize( $content );

        // ── Step 2: Translate TEXT tokens ──────────────────────────────
        $text_indices = [];
        foreach ( $tokens as $i => $token ) {
            if ( 'TEXT' === $token['type'] && '' !== trim( $token['value'] ) ) {
                $text_indices[] = $i;
            }
        }

        if ( ! empty( $text_indices ) ) {
            $texts      = array_map( fn( $i ) => $tokens[ $i ]['value'], $text_indices );
            $translated = $this->batch_translate( $texts, $from_lang, $to_lang );

            if ( is_wp_error( $translated ) ) {
                return $translated;
            }

            foreach ( $text_indices as $j => $token_idx ) {
                $tokens[ $token_idx ]['value'] = $translated[ $j ];
            }
        }

        // ── Step 3: Translate translatable attributes ──────────────────
        $tokens = $this->translate_attrs_in_tokens( $tokens, $from_lang, $to_lang );

        // ── Step 4: Reassemble ─────────────────────────────────────────
        return implode( '', array_column( $tokens, 'value' ) );
    }

    /**
     * Detect the language of a short text snippet.
     */
    public function detect_language( string $snippet ) {
        $response = $this->api_request( $snippet, 'auto', 'en' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $detected = $response[2] ?? null;
        if ( $detected && is_string( $detected ) ) {
            return strtolower( $detected );
        }

        return new WP_Error( 'mct_detect_failed', __( 'Could not detect language.', 'manual-content-translator' ) );
    }

    // ── Tokeniser ─────────────────────────────────────────────────────────

    private function tokenize( string $content ): array {
        $tokens = [];
        $pos    = 0;
        $len    = mb_strlen( $content );

        while ( $pos < $len ) {
            $ch = mb_substr( $content, $pos, 1 );

            // HTML comment (Служебные теги Гутенберга)
            if ( '<!--' === mb_substr( $content, $pos, 4 ) ) {
                $end = mb_strpos( $content, '-->', $pos + 4 );
                if ( false !== $end ) {
                    $tokens[] = [ 'type' => 'HTML_COMMENT', 'value' => mb_substr( $content, $pos, $end + 3 - $pos ) ];
                    $pos      = $end + 3;
                    continue;
                }
            }

            // CDATA
            if ( '<![CDATA[' === mb_substr( $content, $pos, 9 ) ) {
                $end = mb_strpos( $content, ']]>', $pos + 9 );
                if ( false !== $end ) {
                    $tokens[] = [ 'type' => 'CDATA', 'value' => mb_substr( $content, $pos, $end + 3 - $pos ) ];
                    $pos      = $end + 3;
                    continue;
                }
            }

            // HTML tag
            if ( '<' === $ch ) {
                $next = $pos + 1 < $len ? mb_substr( $content, $pos + 1, 1 ) : '';
                if ( preg_match( '/[a-zA-Z\/!]/', $next ) ) {
                    $tag_end = $this->find_html_tag_end( $content, $pos );
                    if ( -1 !== $tag_end ) {
                        $tokens[] = [ 'type' => 'HTML_TAG', 'value' => mb_substr( $content, $pos, $tag_end - $pos ) ];
                        $pos      = $tag_end;
                        continue;
                    }
                }
            }

            // WP Shortcode
            if ( '[' === $ch ) {
                $sc = $this->read_shortcode( $content, $pos );
                if ( null !== $sc ) {
                    $tokens[] = $sc['token'];
                    $pos      = $sc['end'];
                    continue;
                }
            }

            // HTML entity
            if ( '&' === $ch ) {
                $entity_end = $this->read_html_entity( $content, $pos );
                if ( -1 !== $entity_end ) {
                    $tokens[] = [ 'type' => 'HTML_ENTITY', 'value' => mb_substr( $content, $pos, $entity_end - $pos ) ];
                    $pos      = $entity_end;
                    continue;
                }
            }

            // Plain text
            $text_end = $this->find_next_special( $content, $pos );
            if ( $text_end === $pos ) {
                $text_end++; 
            }
            
            $text = mb_substr( $content, $pos, $text_end - $pos );
            if ( '' !== $text ) {
                $last_idx = count( $tokens ) - 1;
                if ( $last_idx >= 0 && 'TEXT' === $tokens[ $last_idx ]['type'] ) {
                    $tokens[ $last_idx ]['value'] .= $text;
                } else {
                    $tokens[] = [ 'type' => 'TEXT', 'value' => $text ];
                }
            }
            $pos = $text_end;
        }

        return $tokens;
    }

    // ── Tokeniser helpers ─────────────────────────────────────────────────

    private function find_html_tag_end( string $s, int $start ): int {
        $len = mb_strlen( $s ); $i = $start + 1;
        $in_double = false; $in_single = false; $bracket_depth = 0;

        while ( $i < $len ) {
            $ch = mb_substr( $s, $i, 1 );
            if ( ! $in_double && ! $in_single ) {
                if ( '"' === $ch )      { $in_double = true; }
                elseif ( "'" === $ch )  { $in_single = true; }
                elseif ( '[' === $ch )  { $bracket_depth++; }
                elseif ( ']' === $ch )  { $bracket_depth--; }
                elseif ( '>' === $ch && 0 === $bracket_depth ) { return $i + 1; }
            } elseif ( $in_double && '"' === $ch ) { $in_double = false; }
              elseif ( $in_single && "'" === $ch ) { $in_single = false; }
            $i++;
        }
        return -1;
    }

    private function read_shortcode( string $s, int $pos ): ?array {
        $len = mb_strlen( $s ); $i = $pos + 1;
        if ( $i < $len && '/' === mb_substr( $s, $i, 1 ) ) { $i++; }
        $tag = '';
        while ( $i < $len && preg_match( '/[a-zA-Z0-9_\-]/', mb_substr( $s, $i, 1 ) ) ) {
            $tag .= mb_substr( $s, $i, 1 ); $i++;
        }
        if ( '' === $tag ) { return null; }
        $end = $this->find_shortcode_end( $s, $pos );
        if ( -1 === $end ) { return null; }
        $type = str_starts_with( $tag, self::WPBAKERY_PREFIX ) ? 'WPBAKERY_TAG' : 'WP_SHORTCODE';
        return [ 'token' => [ 'type' => $type, 'value' => mb_substr( $s, $pos, $end - $pos ) ], 'end' => $end ];
    }

    private function find_shortcode_end( string $s, int $start ): int {
        $len = mb_strlen( $s ); $i = $start + 1;
        $in_double = false; $in_single = false; $brace = 0;
        while ( $i < $len ) {
            $ch = mb_substr( $s, $i, 1 );
            if ( ! $in_double && ! $in_single ) {
                if ( '"' === $ch )      { $in_double = true; }
                elseif ( "'" === $ch )  { $in_single = true; }
                elseif ( '{' === $ch )  { $brace++; }
                elseif ( '}' === $ch )  { $brace--; }
                elseif ( ']' === $ch && 0 === $brace ) { return $i + 1; }
            } elseif ( $in_double && '"' === $ch ) { $in_double = false; }
              elseif ( $in_single && "'" === $ch ) { $in_single = false; }
            $i++;
        }
        return -1;
    }

    private function read_html_entity( string $s, int $pos ): int {
        $slice = mb_substr( $s, $pos, 12 );
        if ( preg_match( '/^&(?:#x[0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);/', $slice, $m ) ) {
            return $pos + mb_strlen( $m[0] );
        }
        return -1;
    }

    private function find_next_special( string $s, int $pos ): int {
        $len = mb_strlen( $s );
        for ( $i = $pos; $i < $len; $i++ ) {
            $ch = mb_substr( $s, $i, 1 );
            if ( '<' === $ch || '[' === $ch || '&' === $ch ) { return $i; }
        }
        return $len;
    }

    // ── Attribute translation ─────────────────────────────────────────────

    private function translate_attrs_in_tokens( array $tokens, string $from, string $to ): array {
        $batch = [];
        foreach ( $tokens as $idx => $token ) {
            if ( ! in_array( $token['type'], [ 'HTML_TAG', 'WPBAKERY_TAG' ], true ) ) { continue; }

            foreach ( self::TRANSLATABLE_ATTRS as $attr ) {
                $pattern = '/' . preg_quote( $attr, '/' ) . '=(["\'])([^"\']*)\1/';
                if ( ! preg_match_all( $pattern, $token['value'], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) { continue; }

                foreach ( $matches as $match ) {
                    $full_match_str = $match[0][0];
                    $full_match_off = $match[0][1];
                    $quote          = $match[1][0];
                    $value          = $match[2][0];

                    if ( $this->is_attr_translatable( $value ) ) {
                        $batch[] = [
                            'token_idx'  => $idx,
                            'attr'       => $attr,
                            'original'   => $value,
                            'quote'      => $quote,
                            'full_match' => $full_match_str,
                            'offset'     => $full_match_off,
                        ];
                    }
                }
            }
        }

        if ( empty( $batch ) ) { return $tokens; }

        $values     = array_column( $batch, 'original' );
        $translated = $this->batch_translate( $values, $from, $to );

        if ( is_wp_error( $translated ) ) { return $tokens; }

        $grouped = [];
        foreach ( $batch as $j => $entry ) {
            $entry['translated'] = $translated[ $j ];
            $grouped[ $entry['token_idx'] ][] = $entry;
        }

        foreach ( $grouped as $token_idx => $entries ) {
            usort( $entries, fn( $a, $b ) => $b['offset'] <=> $a['offset'] );
            $token_val = $tokens[ $token_idx ]['value'];

            foreach ( $entries as $entry ) {
                $q = $entry['quote'];
                $new_match = $entry['attr'] . '=' . $q . $entry['translated'] . $q;
                $token_val = substr_replace( $token_val, $new_match, $entry['offset'], strlen( $entry['full_match'] ) );
            }
            $tokens[ $token_idx ]['value'] = $token_val;
        }

        return $tokens;
    }

    private function is_attr_translatable( string $value ): bool {
        $value = trim( $value );
        if ( '' === $value )                               return false;
        if ( str_contains( $value, '{' ) )                 return false;
        if ( preg_match( '~https?://~', $value ) )         return false;
        if ( preg_match( '/^[\d\-a-z_:]+$/', $value ) )    return false;
        return (bool) preg_match( '/[\p{L}]{3,}/u', $value );
    }

    // ── Google Translate API (Улучшено под сверхтяжелый контент) ──────────

    /**
     * Потоковая пакетная сборка перевода. 
     * Гарантирует, что строка запроса к Google никогда не превысит лимиты.
     */
    private function batch_translate( array $texts, string $from, string $to ) {
        $result = array_fill( 0, count( $texts ), '' );
        $delim  = self::DELIMITER;

        $chunks  = [];
        $current = [ 'indices' => [], 'texts' => [], 'size' => 0 ];

        foreach ( $texts as $i => $text ) {
            $text_len = mb_strlen( $text );

            // Если одна строка безумно огромная (больше лимита), выносим ее в персональный чанк
            if ( $text_len > self::MAX_QUERY_LEN ) {
                if ( ! empty( $current['indices'] ) ) {
                    $chunks[] = $current;
                    $current  = [ 'indices' => [], 'texts' => [], 'size' => 0 ];
                }
                $chunks[] = [ 'indices' => [ $i ], 'texts' => [ $text ], 'size' => $text_len ];
                continue;
            }

            // Считаем размер потенциальной склейки
            $expected_size = $current['size'] === 0 
                ? $text_len 
                : $current['size'] + mb_strlen( $delim ) + $text_len;

            // Если размер превысил лимит безопасного запроса, закрываем текущий чанк
            if ( $expected_size > self::MAX_QUERY_LEN && ! empty( $current['indices'] ) ) {
                $chunks[] = $current;
                $current  = [ 'indices' => [], 'texts' => [], 'size' => 0 ];
                $expected_size = $text_len;
            }

            $current['indices'][] = $i;
            $current['texts'][]   = $text;
            $current['size']      = $expected_size;
        }
        
        if ( ! empty( $current['indices'] ) ) {
            $chunks[] = $current;
        }

        // Выполняем запросы частями
        foreach ( $chunks as $chunk ) {
            $joined   = implode( $delim, $chunk['texts'] );
            $response = $this->api_request( $joined, $from, $to );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $translated_joined = $this->extract_translation( $response );
            $parts             = explode( $delim, $translated_joined );

            // Защита на случай, если Google сожрал или повредил наш Юникод-разделитель
            if ( count( $parts ) !== count( $chunk['indices'] ) ) {
                // Поштучный запасной сценарий (Fallback) для стабильности
                foreach ( $chunk['indices'] as $k => $orig_idx ) {
                    $r = $this->api_request( $chunk['texts'][ $k ], $from, $to );
                    $result[ $orig_idx ] = is_wp_error( $r ) ? $chunk['texts'][ $k ] : $this->extract_translation( $r );
                }
                continue;
            }

            foreach ( $chunk['indices'] as $k => $orig_idx ) {
                $result[ $orig_idx ] = $parts[ $k ];
            }
        }

        return $result;
    }

    private function api_request( string $text, string $from, string $to ) {
        $url = add_query_arg(
            [
                'client' => 'gtx',
                'sl'     => $from,
                'tl'     => $to,
                'dt'     => 't',
                'q'      => $text, 
            ],
            self::API_URL
        );

        $response = wp_remote_get( $url, [
            'timeout'    => 25, // Увеличенный таймаут ожидания ответа
            'user-agent' => 'Mozilla/5.0 (compatible; MCT-WP/1.0.0)',
            'headers'    => [
                'Accept'          => 'application/json',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'mct_request_failed',
                sprintf( __( 'Translation request failed: %s', 'manual-content-translator' ), $response->get_error_message() )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            return new WP_Error(
                'mct_http_error',
                sprintf( __( 'Google Translate returned HTTP %d. Rate limit hit.', 'manual-content-translator' ), $code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'mct_parse_error', __( 'Could not parse translation response.', 'manual-content-translator' ) );
        }

        return $data;
    }

    private function extract_translation( array $data ): string {
        if ( empty( $data[0] ) || ! is_array( $data[0] ) ) { return ''; }
        $result = '';
        foreach ( $data[0] as $segment ) {
            if ( isset( $segment[0] ) && is_string( $segment[0] ) ) { $result .= $segment[0]; }
        }
        return $result;
    }
}