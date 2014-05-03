<?php namespace WPTiles;

// Exit if accessed directly
if ( !defined ( 'ABSPATH' ) )
    exit;

class WPTiles
{

    const GRID_POST_TYPE = 'grid_template';

    /**
     * Store the current tiles id, in case we add more to one page
     *
     * @var int
     */
    protected $tiles_id = 1;

    /**
     * Data to put to the page at the end of the day
     * @var array
     */
    protected $data = array( );

    /**
     * @var PostQuery
     * @since 1.0
     */
    public $post_query;

    /**
     * @var Options
     * @since 1.0
     */
    public $options;

    /**
     * Only made available so other plugins can interact with the AJAX class (for
     * example to remove the action).
     *
     * @var Ajax
     * @since 1.0
     */
    public $ajax;

    /**
     * Creates an instance of the WP_Tiles class
     *
     * @return WP_Tiles object
     * @since 0.1
     * @static
     */
    public static function get_instance() {
        static $instance = false;

        if ( !$instance ) {
            load_plugin_textdomain( 'wp-tiles', false, WPTILES_DIR . '/languages/' );

            $class = get_called_class();
            $instance = new $class;
            $instance->init();
        }

        return $instance;
    }

    protected function __construct() {}

    public function init() {
        $this->post_query = new PostQuery();
        $this->options    = new Options();
        $this->ajax       = new Ajax();

        Admin\Admin::setup(); // Static class

        add_action( 'init', array( &$this, 'register_post_type' ) );

        // The Shortcode
        add_shortcode( 'wp-tiles', array( '\WPTiles\Shortcode', 'do_shortcode' ) );

        // The Gallery
        add_filter( 'post_gallery', array( '\WPTiles\Gallery', 'maybe_do_gallery' ), 1001, 2 );
    }

    public function register_post_type() {
        register_post_type( self::GRID_POST_TYPE, apply_filters( 'wp_tiles/grid_template_post_type', array(
            'labels'             => array(
                'name'               => _x( 'Grids', 'post type general name', 'wp-tiles' ),
                'singular_name'      => _x( 'Grid', 'post type singular name', 'wp-tiles' ),
                'menu_name'          => _x( 'WP Tiles Grids', 'admin menu', 'wp-tiles' ),
                'name_admin_bar'     => _x( 'Grid', 'add new on admin bar', 'wp-tiles' ),
                'add_new'            => _x( 'Add New Grid', 'book', 'wp-tiles' ),
                'add_new_item'       => __( 'Add New Grid', 'wp-tiles' ),
                'new_item'           => __( 'New Grid', 'wp-tiles' ),
                'edit_item'          => __( 'Edit Grid', 'wp-tiles' ),
                'view_item'          => __( 'View Grid', 'wp-tiles' ),
                'all_items'          => __( 'Grids', 'wp-tiles' ),
                'search_items'       => __( 'Search Grids', 'wp-tiles' ),
                'parent_item_colon'  => __( 'Parent Grids:', 'wp-tiles' ),
                'not_found'          => __( 'No grids found.', 'wp-tiles' ),
                'not_found_in_trash' => __( 'No grids found in Trash.', 'wp-tiles' ),
            ),
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            //'show_in_menu'       => true,
            'show_in_menu'       => 'wp-tiles',
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 100,
            'menu_icon'          => 'dashicons-screenoptions',
            'supports'           => array( 'title' )
        ) ) );
    }

    /**
     * @deprecated since version 1.0
     */
    /*public function show_tiles( $atts_arg ) {
        echo $this->shortcode( $atts_arg );
    }*/

    public function get_tiles( $posts, $opts = array() ) {
        $defaults = $this->options->get_options();
        $opts = wp_parse_args( $opts, $defaults );

        return $this->render_tiles( $posts, $opts );
    }

    public function get_query_nonce( $query ) {
        $hash = $this->get_query_hash( $query );
        return wp_create_nonce( $hash );
    }

    public function get_query_hash( $query ) {
        array_walk( $query, function( &$var ){
            if ( 'false' === $var )
                $var = false;
            elseif( 'true' === $var )
                $var = true;
        } );

        $q = build_query( wp_parse_args( $query ) );
        return md5( $q );
    }

    public function render_tiles( $posts, $opts = false ) {

        if ( empty( $posts ) )
            return;

        // Is $posts a query?
        if ( is_array( $posts ) && count(array_filter(array_keys( $posts ), 'is_string') ) ) {

            // Automatically set paged var if tile pagination is on
            if ( $opts['pagination'] )
                $posts['paged'] = ( get_query_var('paged') ) ? get_query_var('paged') : 1;

            $posts = new \WP_Query( apply_filters( 'wp_tiles_get_posts_query', $posts ) );
        }

        // Is posts a WP_Query? (enables pagination)
        $wp_query = false;
        if ( is_a( $posts, 'WP_Query' ) ) {
            $wp_query = $posts;
            $posts = $wp_query->posts;
        }

        if ( !$opts )
            $opts = $this->options->get_options();

        /**
         * Set the variables in the instance
         */
        $wp_tiles_id = "wp_tiles_" . $this->tiles_id;
        $this->tiles_id++;

        /**
         *  Cleanup grids and set names
         */
        if ( !$opts['grids'] )
            $opts['grids'] = $this->get_grids();

        $grid__pretty_names = array_keys( $opts['grids'] );
        $opts['grids'] = $this->format_grids( $opts['grids'] );
        $grid_names = array_combine( array_keys( $opts['grids'] ), $grid__pretty_names );

        $opts['small_screen_grid'] = $this->format_grid( $opts['small_screen_grid'] );

        $opts['byline_color'] = $this->options->get_byline_color( $opts );
        $opts['colors'] = $this->options->get_colors( $opts );

        /**
         * Make sure carousel module isn't loaded in vain
         */
        if ( 'carousel' == $opts['link']
            && ( !doing_filter( 'post_gallery' ) ||  !class_exists( 'No_Jetpack_Carousel' ) && !class_exists( 'Jetpack_Carousel' ) ) ) {
            $opts['link'] = 'thickbox';
        }

        /**
         * Pagination
         */

        // Only allow pagination when we have a WP Query
        $opts['next_query'] = false;
        $next_page = false;
        if ( $wp_query ) {
            $max_page  = $wp_query->max_num_pages;
            $next_page = intval( $wp_query->get( 'paged', 1 ) ) + 1;

            if ( $next_page > $max_page )
                $next_page = false;

            // Sign the query and pass it to JS
            if ( $next_page && 'ajax' == $opts['pagination'] ) {
                $next_query = $wp_query->query;

                $max_page  = $wp_query->max_num_pages;
                $next_page = intval( $wp_query->get( 'paged', 1 ) ) + 1;

                if ( $next_page <= $max_page ) {

                    $next_query['paged'] = $next_page;

                    $opts['next_query'] = array(
                        'query' => $next_query,
                        'action' => Ajax::ACTION_GET_POSTS,
                        '_ajax_nonce' => $this->get_query_nonce( $next_query )
                    );
                    $opts['ajaxurl'] = admin_url( 'admin-ajax.php' );

                }
            }
        }

        /**
         * Pass the required info to the JS
         */
        $this->add_data_for_js( $wp_tiles_id, $opts );

        /**
         * Get the classes
         */
        $classes = array(
            ( 'top' == $opts['byline_align'] ) ? 'wp-tiles-byline-align-top' : 'wp-tiles-byline-align-bottom'
        );

        if ( !empty( $opts['byline_effect'] ) && in_array( $opts['byline_effect'], $this->options->get_allowed_byline_effects() ) )
            $classes = array_merge( $classes, array(
                'wp-tiles-byline-animated',
                'wp-tiles-byline-' . $opts['byline_effect']
            ) );

        if ( !empty( $opts['image_effect'] ) && in_array( $opts['image_effect'], $this->options->get_allowed_image_effects() )  )
            $classes = array_merge( $classes, array(
                'wp-tiles-image-animated',
                'wp-tiles-image-' . $opts['image_effect']
            ) );

        /**
         * Render the template
         *
         * POLICY: Though the PHP should remain readable at all times, getting clean
         * HTML output is nice. To strive to get clean HTML output, WP Tiles starts 8
         * spaces (2 tabs) from the wall, and leaves an empty line between each line
         * of HTML. Remeber that ?> strips a folliwing newline, so always leave an
         * empty line after ?>.
         */
        ob_start();
        ?>
        <?php if ( count( $grid_names ) > 1 ) : ?>

        <div id="<?php echo $wp_tiles_id; ?>-templates" class="tile-templates">

            <ul class="template-selector">

            <?php foreach ( $grid_names as $slug => $name ) : ?>

                <li class="template" data-grid="<?php echo $slug ?>"><?php echo $name; ?></li>
            <?php endforeach; ?>

            </ul>

        </div>
        <?php endif; ?>

        <div class="wp-tiles-container">
        <?php if ( 'carousel' == $opts['link'] ):?>

            <?php echo apply_filters( 'gallery_style', '<div id="' . $wp_tiles_id . '" class="wp-tiles-grid gallery ' . implode( ' ', $classes ) . '">' ); ?>
        <?php else : ?>

            <div id="<?php echo $wp_tiles_id; ?>" class="wp-tiles-grid <?php echo implode( ' ', $classes ); ?>">
        <?php endif; ?>
                <?php $this->render_tile_html( $posts, $opts ) ?>

            </div>

        </div>
        <?php

        /**
        * Pagination
        **/
        if ( $next_page && 'ajax' === $opts['pagination'] && $opts['next_query'] ) : ?>

        <nav class="wp-tiles-pagination wp-tiles-pagination-ajax" id="<?php echo $wp_tiles_id; ?>-pagination">
            <a href="<?php next_posts( $max_page, true ) ?>"><?php _e( 'Load More', 'wp-tiles' ) ?></a>
        </nav>
        <?php elseif ( 'prev_next' === $opts['pagination'] ) : ?>
            <?php wp_tiles_prev_next_nav( $wp_query, $wp_tiles_id ); ?>

        <?php elseif ( 'paging' === $opts['pagination'] ) : ?>
            <?php wp_tiles_paging_nav( $wp_query, $wp_tiles_id ); ?>

        <?php endif; ?>

        <?php
        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }

    public function render_tile_html( $posts, $opts ) {

        foreach( $posts as $post ) :

            $img = false;
            if ( !$opts['text_only'] && $img = $this->get_first_image( $post, $opts['image_size'] ) ) {
                $tile_class = 'wp-tiles-tile-with-image';
            } elseif ( $opts['images_only'] ) {
                continue; // If text_only *and* image_only are enabled, the user should expect 0 tiles..

            } else {
                $tile_class = 'wp-tiles-tile-text-only';
            }

             if ( $opts['byline_template_textonly'] && ($opts['text_only'] || !$img ) ) {
                $byline = $this->render_byline( $opts['byline_template_textonly'], $post );

            } elseif ( $opts['byline_template'] ) {
                $byline = $this->render_byline( $opts['byline_template'], $post );

            } else {
                $byline = false;
            }

            $tile_classes = array( 'wp-tiles-tile' );

            if ( 'carousel' == $opts['link'] )
                $tile_classes[] = 'gallery-item';

            $tile_classes = apply_filters( 'wp_tiles_tile_classes', $tile_classes );

            ?>

                <div class='<?php echo implode( ' ', $tile_classes ) ?>' id='tile-<?php echo $post->ID ?>'>
                <?php if ( 'post' == $opts['link'] ) : ?>

                    <a href="<?php echo get_permalink( $post->ID ) ?>" title="<?php echo apply_filters( 'the_title', $post->post_title ) ?>">
                <?php elseif ( 'file' == $opts['link'] ) : ?>

                    <a href="<?php echo $this->get_first_image( $post, 'full' ) ?>" title="<?php echo apply_filters( 'the_title', $post->post_title ) ?>">
                <?php elseif ( 'thickbox' == $opts['link'] ) : ?>

                    <a href="<?php echo $this->get_first_image( $post, 'full' ) ?>" title="<?php echo strip_tags( $byline ) ?>" class="thickbox" rel="<?php echo $this->tiles_id ?>">
                <?php elseif ( 'carousel' == $opts['link'] ) : ?>

                    <a href="<?php echo $this->get_first_image( $post, 'full' ) ?>" title="<?php echo strip_tags( $byline ) ?>"<?php echo Gallery::get_carousel_image_attr( $post ) ?>>
                <?php endif; ?>

                        <article class='<?php echo $tile_class ?> wp-tiles-tile-wrapper' itemscope itemtype="http://schema.org/CreativeWork">
                        <?php if ( $img ) : ?>

                            <div class='wp-tiles-tile-bg'>

                                <img src='<?php echo $img ?>' class='wp-tiles-img' itemprop="image" />

                            </div>
                        <?php endif; ?>
                        <?php if ( $byline || !$opts['hide_title'] ) : ?>

                            <div class='wp-tiles-byline'>
                            <?php if ( !$opts['hide_title'] ) : ?>

                                <h4 itemprop="name" class="wp-tiles-byline-title"><?php echo apply_filters( 'the_title', $post->post_title ) ?></h4>
                            <?php endif; ?>
                            <?php if ( $byline ) : ?>

                                <div class='wp-tiles-byline-content' itemprop="description">
                                    <?php echo $byline; ?>

                                </div>
                            <?php endif; ?>

                            </div>
                        <?php endif; ?>

                        </article>
                <?php if ( $opts['link'] && 'none' != $opts['link'] ) : ?>

                    </a>
                <?php endif; ?>

                </div>
            <?php
        endforeach;
    }

    protected function render_byline( $template, $post ) {
        // Only use below filter to change the byline on a per-post level
        $template = apply_filters( 'wp_tiles_byline_template_post', $template, $post );

        $tags = array(
            '%title%'   => apply_filters( 'the_title', $post->post_title ),
            '%content%' => apply_filters( 'the_content', strip_shortcodes( $post->post_content ) ),
            '%excerpt%' => $this->get_the_excerpt( $post ),
            '%date%'    => $this->get_the_date( $post ),
            '%link%'    => get_permalink( $post ),
        );
        // Only do the more expensive tags if needed
        if ( strpos( $template, '%categories%' ) !== false ) {
            $tags['%categories%'] = implode( ', ', wp_get_post_categories( $post->ID, array( "fields" => "names" ) ) );
        }
        if ( strpos( $template, '%tags%' ) !== false ) {
            $tags['%tags%'] = implode( ', ', wp_get_post_tags( $post->ID, array( "fields" => "names" ) ) );
        }
        if ( strpos( $template, '%featured_image%' ) !== false ) {
            $tags['%featured_image%'] = get_the_post_thumbnail( $post->ID );
        }
        if ( strpos( $template, '%author%' ) !== false ) {
            $authordata = get_userdata( $post->post_author );
            $tags['%author%'] = apply_filters('the_author', is_object($authordata) ? $authordata->display_name : null);
        }

        $tags = apply_filters( 'wp_tiles_byline_tags', $tags, $post, $template );

        $ret = str_replace( array_keys( $tags ), array_values( $tags ), $template );

        // Strip empty paragraphs and headings
        $ret = preg_replace( "/<(p|h[1-6])[^>]*>[\s|&nbsp;]*<\/(p|h[1-6])>/i", '', $ret );
        return !empty( $ret ) ? $ret : false;
    }

    /**
     * @todo Filter out vars we don't need
     */
    protected function add_data_for_js( $wp_tiles_id, $opts ) {
        static $enqueued = false;

        if ( !$enqueued ) {
            $this->enqueue_scripts();
            $this->enqueue_styles();

            $enqueued = true;
        }

        if ( 'thickbox' == $opts['link'] )
            add_thickbox();

        $opts['id'] = $wp_tiles_id;
        $this->data[$wp_tiles_id] = $opts;
    }

    public function add_data() {
        wp_localize_script( 'wp-tiles', 'wptilesdata', $this->data );

    }

    public function enqueue_scripts() {
        //if ( !is_admin() ) {
            wp_enqueue_script( "jquery" );

            $script_path = WP_TILES_ASSETS_URL . '/js/';
            $ext = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

            wp_enqueue_script( 'tilesjs',  $script_path . 'tiles' . $ext, array( "jquery" ), "2013-05-18", true );
            wp_enqueue_script( 'jquery-dotdotdot',  $script_path . 'jquery.dotdotdot' . $ext, array( "jquery" ),  "1.6.14", true );

            wp_enqueue_script( 'wp-tiles', $script_path . 'wp-tiles' . $ext, array( "tilesjs", "jquery-dotdotdot" ), WP_TILES_VERSION, true );

            add_action( 'wp_footer', array( &$this, "add_data" ), 1 );
        //}
    }

    /**
     * Look for the stylesheet in a million places
     */
    public function enqueue_styles() {
        $stylesheet_name = "wp-tiles.css";

        if ( file_exists( STYLESHEETPATH . '/' . $stylesheet_name ) ) {
            $located = get_stylesheet_directory_uri() . '/' . $stylesheet_name;
        } else if ( file_exists( STYLESHEETPATH . '/inc/css/' . $stylesheet_name ) ) {
            $located = get_stylesheet_directory_uri() . '/inc/css/' . $stylesheet_name;
        } else if ( file_exists( STYLESHEETPATH . '/inc/' . $stylesheet_name ) ) {
            $located = get_stylesheet_directory_uri() . '/inc/' . $stylesheet_name;
        } else if ( file_exists( STYLESHEETPATH . '/css/' . $stylesheet_name ) ) {
            $located = get_stylesheet_directory_uri() . '/css/' . $stylesheet_name;
        } else if ( file_exists( TEMPLATEPATH . '/' . $stylesheet_name ) ) {
            $located = get_template_directory_uri() . '/' . $stylesheet_name;
        } else if ( file_exists( TEMPLATEPATH . '/inc/css/' . $stylesheet_name ) ) {
            $located = get_template_directory_uri() . '/inc/css/' . $stylesheet_name;
        } else if ( file_exists( TEMPLATEPATH . '/inc/' . $stylesheet_name ) ) {
            $located = get_template_directory_uri() . '/inc/' . $stylesheet_name;
        } else if ( file_exists( TEMPLATEPATH . '/css/' . $stylesheet_name ) ) {
            $located = get_template_directory_uri() . '/css/' . $stylesheet_name;
        } else {
            $located = WP_TILES_ASSETS_URL . '/css/wp-tiles.css';
        }
        wp_enqueue_style( 'wp-tiles', $located, false, WP_TILES_VERSION );
    }

    private function get_the_date( $post, $d = '' ) {
        $the_date = '';

        if ( '' == $d )
            $the_date .= mysql2date( get_option( 'date_format' ), $post->post_date );
        else
            $the_date .= mysql2date( $d, $post->post_date );

        return apply_filters( 'get_the_date', $the_date, $d );
    }

    function get_the_excerpt( $text, $excerpt = '' ) {
        if ( is_a( $text, 'WP_Post' ) ) {
            $excerpt = $text->post_excerpt;
            $text = $text->post_content;
        }

        if ( $excerpt )
            return $excerpt;

        $text = strip_shortcodes( $text );

        $text           = apply_filters( 'the_content', $text );
        $text           = str_replace( ']]>', ']]&gt;', $text );
        $text           = strip_tags( $text );
        $excerpt_length = apply_filters( 'excerpt_length', 55 );
        $excerpt_more   = apply_filters( 'excerpt_more', ' ' . '[...]' );
        $words          = preg_split( "/[\n\r\t ]+/", $text, $excerpt_length + 1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) > $excerpt_length ) {
            array_pop( $words );
            $text = implode( ' ', $words );
            $text = $text . $excerpt_more;
        } else {
            $text = implode( ' ', $words );
        }

        return apply_filters( 'wp_trim_excerpt', $text, $excerpt );
    }

    protected function has_excerpt( $post ) {
        return !empty( $post->post_excerpt );
    }

    /**
     * Returns the first image
     *
     * Uses cache. Plugins can hijack this method by hooking into 'pre_wp_tiles_image'.
     * @param WP_Post $post
     * @return string Image url
     * @todo invalidate cache
     */
    public function get_first_image( $post, $size = false ) {
        $allowed_sizes = get_intermediate_image_sizes();
        $allowed_sizes[] = 'full';

        if ( !in_array( 'size', $allowed_sizes ) || !$size )
            $size = $this->options->get_option( 'image_size' );

        // Also the option *could* in theory be wrong
        if ( !in_array( 'size', $allowed_sizes ) )
            $size = $this->options->get_option_defaults( 'image_size' );

        // @todo legacy filter: wp-tiles-image-size
        $size = apply_filters( 'wp_tiles_image_size', $size, $post );

        // Allow plugins to hijack image loading
        $src = apply_filters( 'pre_wp_tiles_image', false, $post, $size );
        if ( false !== $src )
            return $src;

        if ( !$src = wp_cache_get( 'wp_tiles_image_' . $post->ID . '_' . $size, 'wp-tiles' ) ) {
            $src = $this->_find_the_image( $post, $size );
            wp_cache_set( 'wp_tiles_image_' . $post->ID, $src, 'wp-tiles' );
        }

        return $src;
    }

        /**
         * Finds the first relevant image to a post
         *
         * Searches for a featured image, then the first attached image, then the first image in the source.
         *
         * @param WP_Post $post
         * @return string Source
         * @sice 0.5.2
         * @todo Cache?
         */
        private function _find_the_image( $post, $size ) {
            $tile_image_size = apply_filters( 'wp-tiles-image-size', $size, $post );
            $image_source = $this->options->get_option( 'image_source' );

            if ( 'attachment' === get_post_type( $post->ID ) ) {
                $image = wp_get_attachment_image_src( $post->ID, $tile_image_size, false );
                return $image[0];
            }

            if ( 'attachment_only' == $image_source )
                return '';

            if ( $post_thumbnail_id = get_post_thumbnail_id( $post->ID ) ) {
                $image = wp_get_attachment_image_src( $post_thumbnail_id, $tile_image_size, false );
                return $image[0];
            }

            if ( 'featured_only' == $image_source )
                return '';

            $images = get_children( array(
                'post_parent'    => $post->ID,
                'numberposts'    => 1,
                'post_mime_type' => 'image'
            ) );

            if ( !empty( $images ) ) {
                $images = current( $images );
                $src    = wp_get_attachment_image_src( $images->ID, $tile_image_size );
                return $src[0];
            }

            if ( 'attached_only' == $image_source )
                return '';

            if ( !empty( $post->post_content ) ) {
                $xpath = new \DOMXPath( @\DOMDocument::loadHTML( $post->post_content ) );
                $src   = $xpath->evaluate( "string(//img/@src)" );
                return $src;
            }
            return '';
        }

    /**
     * Allow $atts to be just the post_query as a string or object
     *
     * @param string|array $atts
     * @return array Properly formatted $atts
     * @since 0.4.2
     * @deprecated
     * @todo Make compatible with 1.0
     */
    public function parse_post_query_string( $atts ) {
        if ( is_array( $atts ) ) {
            if ( !isset( $atts['posts_query'] ) )
                $atts['posts_query'] = array( );
        } else {

            $posts_query = array( );
            wp_parse_str( $atts, $posts_query );
            $atts        = array( 'posts_query' => $posts_query );
        }

        /**
         * Backward compatibility
         */
        if ( isset( $atts['posts_query']['numberposts'] ) ) {
            $atts['posts_query']['posts_per_page'] = $atts['posts_query']['numberposts'];
            _doing_it_wrong( 'the_wp_tiles', "WP Tiles doesn't use numberposts anymore. Use posts_per_page instead.", '0.4.2' );
        }

        return $atts;
    }


    public function get_grids( $query = false ) {
        // Is this already a grid?
        // Happens when default is passed through the shortcode
        if ( is_array( $query ) && is_array( reset( $query ) ) )
            return $query;

        $posts = $this->_get_grid_posts( $query );

        $grids = array();
        foreach( $posts as $post ) {
            $grids[$post->post_title] = array_map( 'trim', explode( "\n", $post->post_content ) );
        }

        return $grids;
    }

        protected function _get_grid_posts( $query = false ) {
            if ( $query && 'all' !== $query ) {
                if ( !is_array( $query ) ) {
                    $query = strpos( $query, ',' ) !== false ? explode( ',', $query ) : array( $query );
                }

                // Are we dealing with titles?
                if ( !is_numeric( reset( $query ) ) ) {
                    $query = $this->_get_grid_ids_by_titles( $query );
                }

                if ( $query ) {
                    $query = array(
                        'post_type' => self::GRID_POST_TYPE,
                        'posts_per_page' => -1,
                        'post__in' => $query
                    );
                    $posts = get_posts( $query );

                    if ( $posts )
                        return $posts;
                }
            }

            // If no posts are found, return all of them
            return get_posts( array(
                'post_type' => self::GRID_POST_TYPE,
                'posts_per_page' => -1
            ) );
        }

        /**
         * @todo DB Query. Cache! Can be invalidated on post type save.
         */
        private function _get_grid_ids_by_titles( $titles ) {
            global $wpdb;

            if ( empty( $titles) )
                return false;

            $titles = esc_sql( $titles );
            $post_title_in_string = "'" . implode( "','", $titles ) . "'";

            $sql = $wpdb->prepare( "
                SELECT ID
                FROM $wpdb->posts
                WHERE post_title IN ($post_title_in_string)
                AND post_type = %s
            ", self::GRID_POST_TYPE );

            $ids = $wpdb->get_col( $sql );
            return $ids;

       }

    /**
     * Takes an array of grids and returns a sanitized version that can be passed
     * to the JS
     *
     * Sets a sanitized title for the key and explodes and trims the grid template.
     *
     * @param array $grids
     * @return array
     * @see WPTiles::format_grid()
     */
    public function format_grids( $grids ) {
        $ret = array();
        foreach( $grids as $name => $grid ) {
            $ret[sanitize_title($name)] = $this->format_grid( $grid );
        }

        return $ret;
    }

    /**
     * Takes a grid and formats it for insertion in the JS
     *
     * Explodes the grid on newlines if it's not an array and trims every line
     *
     * @param string|array $grid
     * @return array
     */
    public function format_grid( $grid ) {
        if ( !is_array( $grid ) )
            $grid = explode( "\n", $grid );

        $grid = array_map( 'trim', $grid );

        return $grid;
    }
}