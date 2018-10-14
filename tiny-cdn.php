<?php
/**
 * Plugin Name: Tiny CDN
 * Description: Use an origin pull CDN with very few lines of code.
 * Version: 0.2.0
 * Author: Viktor Szépe
 * License: GNU General Public License (GPL) version 2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/szepeviktor/tiny-cdn
 * Constants: TINY_CDN_INCLUDES_URL
 * Constants: TINY_CDN_CONTENT_URL
 *
 * @package tiny-cdn
 */

/**
 * Tiny CDN plugin in the form of a class.
 */
final class O1_Tiny_Cdn {

    /**
     * Exclusion pattern.
     *
     * @var string $excludes
     */
    private $excludes;

    /**
     * Bootstrap plugin.
     */
    public function __construct() {

        if ( is_admin() || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
            return;
        }

        // Add early rewrites.
        add_action( 'init', array( $this, 'init' ) );

        // Add rewrites for template pages.
        add_action( 'template_redirect', array( $this, 'hooks' ) );
    }

    /**
     * Add hooks for filters before the template.
     */
    public function init() {

        // Filter to disable early hooks.
        if ( true === apply_filters( 'tiny_cdn_disable_early', false ) ) {
            return;
        }

        // Early excludes regexp.
        $this->excludes = apply_filters( 'tiny_cdn_excludes_early', '#\.php#' );

        // Yoast sitemap is generated in pre_get_posts hook.
        add_filter( 'wpseo_xml_sitemap_img_src', array( $this, 'rewrite_content' ), 9999 );

        // Expose rewrite_content early as a filter for rewrites.
        add_filter( 'tiny_cdn_early', array( $this, 'rewrite_content' ) );
    }

    /**
     * Hook only URL-s WordPress core "knows about".
     */
    public function hooks() {

        // Capability for rewriting frontend asset URL-s.
        $capability = apply_filters( 'tiny_cdn_capability', 'edit_pages' );
        // Filter to disable TinyCDN from the template.
        if ( apply_filters( 'tiny_cdn_disable', false ) || current_user_can( $capability ) ) {
            return;
        }

        // Excludes regexp.
        $this->excludes = apply_filters( 'tiny_cdn_excludes', '#\.php#' );

        /**
         * Don't go this deep.
         *
         * add_filter( 'includes_url', array( $this, 'rewrite_includes' ), 9999 );
         * add_filter( 'content_url', array( $this, 'rewrite_content' ), 9999 );
         */

        // Rewrite URL-s of files under /wp-content.
        add_filter( 'plugins_url', array( $this, 'rewrite_content' ), 9999 );
        add_filter( 'theme_root_uri', array( $this, 'rewrite_content' ), 9999 );
        add_filter( 'upload_dir', array( $this, 'uploads' ), 9999 );

        // Rewrite style and script URL-s.
        add_filter( 'script_loader_src', array( $this, 'rewrite' ), 9999 );
        add_filter( 'style_loader_src', array( $this, 'rewrite' ), 9999 );

        // Rewrite URL-s in post_content.
        add_filter( 'the_content', array( $this, 'images' ), 9999 );
        add_filter( 'widget_text', array( $this, 'images' ), 9999 );

        // Post thumbnail.
        add_filter( 'wp_get_attachment_image_src', array( $this, 'thumbnail' ), 9999 );

        // Third-parties.
        add_filter( 'wpseo_opengraph_image', array( $this, 'rewrite_content' ), 9999 );

        // Expose rewrite_content as a filter, e.g. for Resource Versioning plugin.
        add_filter( 'tiny_cdn', array( $this, 'rewrite_content' ) );
    }

    /**
     * Rewrite both includes URL and content URL.
     *
     * @param string $url Any URL.
     * @return string
     */
    public function rewrite( $url ) {

        if ( 1 === preg_match( $this->excludes, $url ) ) {
            return $url;
        }

        $url = $this->replace_includes( $url );
        $url = $this->replace_content( $url );

        return $url;
    }

    /**
     * Rewrite content URL.
     *
     * @param string $url Any URL.
     * @return string
     */
    public function rewrite_content( $url ) {

        if ( 1 === preg_match( $this->excludes, $url ) ) {
            return $url;
        }

        $url = $this->replace_content( $url );

        return $url;
    }

    /**
     * Replace includes URL if the given constant is present.
     *
     * @param string $url Any URL.
     * @return string
     */
    private function replace_includes( $url ) {

        if ( ! defined( 'TINY_CDN_INCLUDES_URL' ) ) {
            return $url;
        }

        $includes_url = site_url( '/' . WPINC, null );
        $url          = str_replace( $includes_url, TINY_CDN_INCLUDES_URL, $url );

        return $url;
    }

    /**
     * Replace content URL if the given constant is present.
     *
     * @param string $url Any URL.
     * @return string
     */
    private function replace_content( $url ) {

        if ( ! defined( 'TINY_CDN_CONTENT_URL' ) ) {
            return $url;
        }

        $url = str_replace( WP_CONTENT_URL, TINY_CDN_CONTENT_URL, $url );

        return $url;
    }

    /**
     * Rewrite uploads URL.
     *
     * @param array $upload_data Upload data.
     * @return array
     */
    public function uploads( $upload_data ) {

        $upload_data['url']     = $this->rewrite_content( $upload_data['url'] );
        $upload_data['baseurl'] = $this->rewrite_content( $upload_data['baseurl'] );

        return $upload_data;
    }

    /**
     * Rewrite image URL-s in post content.
     *
     * @param string $content Post content.
     * @return string
     */
    public function images( $content ) {

        /**
         * Only catch images inserted with the editor
         *           (        1        )(  2  )(         3          )
         */
        $pattern = '|(<img [^>]*\bsrc=")([^"]+)(" [^>]*\balt="[^"]*")|';

        $content = preg_replace_callback(
            $pattern,
            function ( $matches ) {
                $url = $this->rewrite_content( $matches[2] );
                return $matches[1] . $url . $matches[3];
            },
            $content
        );

        return $content;
    }

    /**
     * Rewrite image URL of post thumbnail.
     *
     * @param array|false $image Image data.
     * @return array|false
     */
    public function thumbnail( $image ) {

        if ( is_array( $image ) && array_key_exists( 'src', $image ) ) {
            $image['src'] = $this->rewrite_content( $image['src'] );
        }

        return $image;
    }
}

// Start!
new O1_Tiny_Cdn();
