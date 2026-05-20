<?php
/**
 * Plugin Name:       Manual Content Translator
 * Plugin URI:        https://4bstudio.com.ua
 * Description:       Adds inline translation buttons to WP admin input/textarea fields using Polylang languages and Google Translate API.
 * Version:           1.0.0
 * Author:            4bStudio
 * License:           GPL-2.0+
 * Text Domain:       manual-content-translator
 */

defined( 'ABSPATH' ) || exit;

define( 'MCT_VERSION',   '1.0.0' );
define( 'MCT_DIR',       plugin_dir_path( __FILE__ ) );
define( 'MCT_URL',       plugin_dir_url( __FILE__ ) );
define( 'MCT_AJAX_ACTION_TRANSLATE', 'mct_translate' );
define( 'MCT_AJAX_ACTION_DETECT',    'mct_detect_lang' );

require_once MCT_DIR . 'includes/class-mct-polylang.php';
require_once MCT_DIR . 'includes/class-mct-translate.php';

/**
 * Core plugin class — keeps global namespace clean.
 */
final class Manual_Content_Translator {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX: translate
        add_action( 'wp_ajax_' . MCT_AJAX_ACTION_TRANSLATE, [ $this, 'ajax_translate' ] );

        // AJAX: detect language
        add_action( 'wp_ajax_' . MCT_AJAX_ACTION_DETECT, [ $this, 'ajax_detect' ] );
    }

    /**
     * Enqueue assets only on relevant admin pages:
     * post/edit screens (any post_type) + term edit screens (any taxonomy).
     */
    public function enqueue_assets( string $hook ): void {
        $allowed_hooks = [
            'post.php',
            'post-new.php',
            'edit-tags.php',   // taxonomy term edit
            'term.php',        // taxonomy term edit (WP 4.5+)
        ];

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        $languages = MCT_Polylang::get_languages();

        // No point injecting the UI if Polylang has no languages configured.
        if ( empty( $languages ) ) {
            return;
        }

        wp_enqueue_style(
            'mct-admin',
            MCT_URL . 'assets/css/mct-admin.css',
            [],
            MCT_VERSION
        );

        wp_enqueue_script(
            'mct-admin',
            MCT_URL . 'assets/js/mct-admin.js',
            [],            // no jQuery dependency — pure vanilla JS
            MCT_VERSION,
            true           // footer
        );

        wp_localize_script( 'mct-admin', 'MCT', [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'mct_nonce' ),
            'languages'       => $languages,
            'action_translate'=> MCT_AJAX_ACTION_TRANSLATE,
            'action_detect'   => MCT_AJAX_ACTION_DETECT,
            'i18n'            => [
                'translate'       => __( 'Translate', 'manual-content-translator' ),
                'translating'     => __( 'Translating…', 'manual-content-translator' ),
                'select_lang'     => __( 'Select target language', 'manual-content-translator' ),
                'error_empty'     => __( 'Field is empty.', 'manual-content-translator' ),
                'error_generic'   => __( 'Translation error. See tooltip.', 'manual-content-translator' ),
                'auto_detect'     => __( 'Auto-detect', 'manual-content-translator' ),
            ],
        ] );
    }

    /**
     * AJAX: perform translation via Google Translate unofficial API.
     */
    public function ajax_translate(): void {
        check_ajax_referer( 'mct_nonce', 'nonce' );

        $content  = isset( $_POST['content'] )   ? wp_unslash( $_POST['content'] )   : '';
        $from     = isset( $_POST['from_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['from_lang'] ) ) : 'auto';
        $to       = isset( $_POST['to_lang'] )   ? sanitize_text_field( wp_unslash( $_POST['to_lang'] ) )   : '';

        if ( '' === trim( $content ) ) {
            wp_send_json_error( [ 'message' => __( 'Content is empty.', 'manual-content-translator' ) ], 400 );
        }

        if ( '' === $to ) {
            wp_send_json_error( [ 'message' => __( 'Target language is required.', 'manual-content-translator' ) ], 400 );
        }

        // Validate that $to is among the Polylang languages.
        $languages   = MCT_Polylang::get_languages();
        $valid_codes = array_column( $languages, 'locale_short' );
        if ( ! in_array( $to, $valid_codes, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid target language.', 'manual-content-translator' ) ], 400 );
        }

        $translator = new MCT_Translate();
        $result     = $translator->translate( $content, $from, $to );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        wp_send_json_success( [ 'translated' => $result ] );
    }

    /**
     * AJAX: detect source language of a small text snippet.
     */
    public function ajax_detect(): void {
        check_ajax_referer( 'mct_nonce', 'nonce' );

        $snippet = isset( $_POST['snippet'] ) ? wp_unslash( $_POST['snippet'] ) : '';
        $snippet = trim( wp_strip_all_tags( $snippet ) );

        // Take first 200 chars — enough for reliable detection.
        if ( mb_strlen( $snippet ) > 200 ) {
            $snippet = mb_substr( $snippet, 0, 200 );
        }

        if ( '' === $snippet ) {
            wp_send_json_success( [ 'lang' => 'auto' ] );
        }

        $translator = new MCT_Translate();
        $detected   = $translator->detect_language( $snippet );

        if ( is_wp_error( $detected ) ) {
            // Non-fatal: fall back to 'auto' on detection failure.
            wp_send_json_success( [ 'lang' => 'auto' ] );
        }

        wp_send_json_success( [ 'lang' => $detected ] );
    }
}

// Boot.
add_action( 'plugins_loaded', static function () {
    Manual_Content_Translator::instance();
} );
