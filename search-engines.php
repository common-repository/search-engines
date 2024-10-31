<?php
/**
 * Plugin Name: Search Engines
 * Plugin URI: https://wordpress.org/plugins/search-engines/
 * Description: Search Engines is a smart plugin that helps you to optimize the blog for SEO purposes by eliminating issues with duplicate content and specifying meta tags and page titles for different posts types and taxonomies.
 * Version: 1.0.2
 * Author: albertochoa
 * Author URI: https://gitlab.com/albertochoa
 */

class Search_Engines {

	/**
	 * PHP5 style Constructor - Initializes the plugin.
	 * 
	 * @since 1.0.0
	 */
	function __construct() {

		/* Load the translation of the plugin. */
		load_plugin_textdomain( 'search-engines', false, 'search-engines/languages' );

		/* Just do it baby! */
		add_action( 'template_redirect', array( &$this, 'search_engines_template_redirect' ), 1 );

		/* Protect Feeds. */
		add_action( 'template_redirect', array( &$this, 'search_engines_feeds_protect' ) );

		/* Load admin files. */
		add_action( 'wp_loaded', array( &$this, 'search_engines_admin' ) );

		/* Activation plugin. */
		register_activation_hook( __FILE__, array( &$this, 'search_engines_activation' ) );

		/* Desactivation plugin. */
		register_deactivation_hook( __FILE__, array( &$this, 'search_engines_desactivation' ) );
	}

	/**
	 * Your life is easy.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_template_redirect() {
		global $wp_query;

		if ( is_singular() && $redirect = get_post_meta( $wp_query->post->ID, '_nifty_redirect', true ) ) {
			wp_redirect( $redirect, 301 );
			exit();
		}

		@ob_start( array( $this, 'search_engines_template_header' ) );
		@ob_end_clean();
		@ob_start( array( $this, 'search_engines_template_content' ) );
	}

	/**
	 * Genera un nuevo encabezado con las meta-tags.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_template_header( $buffer, $flags ) {
		global $search;

		$search->header =
			$this->search_engines_comment() .
			$this->search_engines_document_title() .
			$this->search_engines_meta_description() .
			$this->search_engines_meta_keywords() .
			$this->search_engines_meta_robots() .
			$this->search_engines_meta_canonical() .
			$this->search_engines_meta_webmasters();
	}

	/**
	 * Remplaza el contenido del encabezado por las nuevas meta-tags.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_template_content( $input ) {
		global $search;

		$meta = array();

		if ( search_engines_setting( 'title_enable' ) )
			$input = preg_replace( '#<title>(.*?)</title>#si', '', $input );

		if ( search_engines_setting( 'description_enable' ) )
			$meta[] = 'description';

		if ( search_engines_setting( 'keywords_enable' ) )
			$meta[] = 'keywords';

		if ( search_engines_setting( 'robots_enable' ) )
			$meta[] = 'robots';

		if ( !empty( $meta ) )
			$input = preg_replace( '#<meta(.*?)[\'"](' . join( '|', $meta ) . ')[\'"](.*?)>#', '', $input );

		$output = preg_replace( '#<head(.*?)>(.*?)<meta(.*?)charset=(.*?)>#si', '<head$1>$2<meta$3charset=$4>' . $search->header, $input, 1 );

		return $output;
	}

	/**
	 * Generates the comment for the output.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_comment() {
		$comment = "\n\n<!-- This site is SEO optimized by Search Engines -->\n";
		return $comment;
	}

	/**
	 * Generates the document title.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_document_title() {
		if ( !search_engines_setting( 'title_enable' ) )
			return;

		global $wp_query;

		$doctitle = '';
		$keyword = '';
		$tax = array();
		$terms = array();

		/* Get current area. */
		$area = $this->search_engines_current_area();

		/* Format title. */
		$format = search_engines_setting( "title_format_$area" );

		/* Separator format. */
		if ( $separator = search_engines_setting( 'title_separator' ) )
			$separator = apply_filters( 'search_engines_separator', " $separator " );

		/* Singular. */
		if ( is_singular() ) {
			$title = strip_tags( get_post_meta( $wp_query->post->ID, '_nifty_title', true ) );

			/* Taxonomies. */
			$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

			foreach( $taxonomies as $taxonomy ) {
				$tax[] = '%' . $taxonomy->name . '%';
				$terms[] = $this->search_engines_post_taxonomy( $taxonomy->name );
			}
		}

		elseif ( is_tax() ) {
			$term = $wp_query->get_queried_object();
			$title = $term->name;
		}

		elseif ( is_search() )
			$keyword = wp_specialchars( $_REQUEST['s'], 1 );

		if ( empty( $title ) ) {
			remove_all_filters( 'wp_title' );
			remove_all_filters( 'genesis_title' );
			if ( !$title = wp_title( '', false ) )
				$title = get_bloginfo( 'description' );
		}

		$doctitle = str_replace(
			array_merge( array( '%blogname%', '%separator%', '%title%', '%label%', '%keyword%' ), $tax ),
			array_merge( array( get_bloginfo( 'name' ), $separator, trim( $title ), search_engines_setting( "title_label_$area"), $keyword ), $terms ),
			$format
		);

		if ( ( ( $page = $wp_query->get( 'paged' ) ) || ( $page = $wp_query->get( 'page' ) ) ) && $page > 1 )
			$doctitle = sprintf( __( '%1$sPage %2$s', 'search-engines' ), $doctitle . $separator, $page );

		if ( !empty( $doctitle ) )
			return '<title>' . trim( $doctitle ) . '</title>' . "\n";
	}

	/**
	 * Generates the meta description.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_meta_description() {
		if ( !search_engines_setting( 'description_enable' ) )
			return;

		global $wp_query;

		$excerpt = '';
		$term = '';
		$posts = '';
		$description = '';

		/* Get current area. */
		$area = $this->search_engines_current_area();

		/* Format title. */
		$format = search_engines_setting( "description_format_$area" );

		/* Default description. */
		$default = search_engines_setting( 'description_default' );

		if ( ( empty( $default ) || 'frontpage' == $area && is_paged() ) && !search_engines_setting( 'description_auto' ) )
			$default = $this->search_engines_post_titles();

		/* Singular. */
		if ( is_singular() ) {
			$excerpt = get_post_meta( $wp_query->post->ID, '_nifty_description', true );

			if ( empty( $excerpt ) )
				$excerpt = get_post_field( 'post_excerpt', $wp_query->post->ID );

			if ( empty( $excerpt ) && !search_engines_setting( 'description_auto' ) )
				$excerpt = get_post_field( 'post_content', $wp_query->post->ID );
		}

		/* Archives */
		elseif ( is_archive() ) {

			if ( is_category() || is_tag() || is_tax() )
				$term = term_description( '', get_query_var( 'taxonomy' ) );

			elseif ( is_author() )
				$term = get_the_author_meta( 'description', get_query_var( 'author' ) );

			elseif ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
				$post_type = get_post_type_object( get_query_var( 'post_type' ) );
				$term = $post_type->description;
			}

			if ( empty( $term ) || is_paged() && !search_engines_setting( 'description_auto' ) )
				$term = $this->search_engines_post_titles();
		}

		/* Listing posts. */
		$posts = $this->search_engines_post_titles();

		/* Remplace description. */
		$description = str_replace( array( '%default%', '%excerpt%', '%description%', '%listed%', '%no%' ), array( $default, $excerpt, $term, $posts, '' ), $format );

		/* Length meta description. */
		$description = $this->search_engines_meta_description_length( $description );

		if ( !empty( $description ) )
			return '<meta name="description" content="' . str_replace( array( "\r", "\n", "\t" ), ' ', esc_attr( $description ) ) . '" />' . "\n";
	}

	/**
	 * Generates the meta description with 20 words for sigular post.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_meta_description_length( $description ) {

		$length = intval( search_engines_setting( 'description_words' ) );

		if ( strpos( $description, '[' ) !== false && strpos( $description, ']' ) !== false )
			$description = strtr( $description, '[]', '<>' );

		$description = strip_tags( $description );

		$array = preg_split( '/[\s"]+/', $description, -1, PREG_SPLIT_NO_EMPTY );
		$array = array_slice( $array, 0, $length );
		$description = join( ' ', $array );

		return $description;
	}

	/**
	 * Generates meta keywords/tags for the site.
	 *
	 * @since 1.0.0
	 */
	function search_engines_meta_keywords() {
		if ( !search_engines_setting( 'keywords_enable' ) )
			return;

		global $wp_query;

		/* Get current area. */
		$area = $this->search_engines_current_area();

		/* Format keywords. */
		$format = search_engines_setting( "keywords_format_$area" );

		$default = search_engines_setting( 'keywords_default' );

		if ( is_singular() ) {
			$terms = get_metadata( 'post', $wp_query->post->ID, '_nifty_keywords', true );

			if ( empty( $terms ) )
				$terms = $this->search_engines_posts_taxonomies();

			if ( search_engines_setting( 'keywords_relevance' ) && $html = $this->search_engines_keywords_relevance( $wp_query->post->ID ) )
				$terms = "$html, $terms";

			if ( empty( $terms ) && is_attachment() )
				$terms = get_post_mime_type();
		}

		if ( is_archive() )
			$terms = $this->search_engines_posts_taxonomies();

		if ( empty( $default ) && is_singular() && !empty( $terms ) )
			$default = $terms;

		if ( empty( $default ) )
			$default = $this->search_engines_posts_taxonomies();

		/* Remplace keywords. */
		$keywords = str_replace( array( '%default%', '%terms%', '%no%' ), array( $default, $terms, '' ), $format );

		if ( !empty( $keywords ) )
			return '<meta name="keywords" content="' . $this->search_engines_keywords_length( $keywords ) . '" />' . "\n";
	}

	function search_engines_keywords_relevance( $post_id ) {

		/* Default variables */
		$keywords = '';
		$keywords_cache = array();

		$key = md5( serialize( $post_id ) );

		$keywords_cache = wp_cache_get( 'keywords_relevance' );

		if ( is_array( $keywords_cache ) && !empty( $keywords_cache[$key] ) )
			return $keywords_cache[$key];

		$content = get_post_field( 'post_content', $post_id );

		preg_match_all( '#<(?:em|strong)>(.*?)</(?:em|strong)>#i', $content, $matches, PREG_PATTERN_ORDER );

		if ( empty( $matches ) || empty( $matches[1] ) )
			return false;

		$labels = array_filter( $matches[1], array( $this, 'search_engines_keywords_characters_length' ) );
		$keywords = join( ', ', $labels );

		if ( !is_array( $keywords_cache ) )
			$keywords_cache = array();

		$keywords_cache[$key] = $keywords;
		wp_cache_set( 'keywords_relevance', $keywords_cache );

		return $keywords;
	}

	function search_engines_keywords_length( $keywords ) {

		$length = intval( search_engines_setting( 'keywords_words' ) );

		$array = explode( ', ', $keywords );
		$array = array_unique( $array );
		$array = array_filter( $array, array( $this, 'search_engines_keywords_characters_length' ) );
		$array = array_slice( $array, 0, $length );
		
		$keywords = join( ', ', $array );

		return $keywords;
	}

	function search_engines_keywords_characters_length( $keyword ) {

		$length = intval( search_engines_setting( 'keywords_short' ) );

		if ( strlen( $keyword ) >= $length )
			return $keyword;
	}

	/**
	 * Sets the default meta robots setting. If private, don't send meta info to the header.
	 *
	 * @since 1.0.0
	 */
	function search_engines_meta_robots() {
		if ( !get_option( 'blog_public' ) || !search_engines_setting( 'title_enable' ) )
			return;

		global $wp_query;

		$robots = '';

		if ( is_singular() )
			$robots = get_metadata( 'post', $wp_query->post->ID, '_nifty_robots', true );

		if ( empty( $robots ) ) {
			$area = $this->search_engines_current_area();
			$robots = search_engines_setting( "robots_$area" );
		}

		if ( search_engines_setting( 'robots_noarchive' ) ) {
			if ( !empty( $robots ) )
				$robots .= ', ';

			$robots .= 'noarchive';
		}

		if ( search_engines_setting( 'robots_odp' ) ) {
			if ( !empty( $robots ) )
				$robots .= ', ';

			$robots .= 'noodp';
		}

		if ( search_engines_setting( 'robots_noydir' ) ) {
			if ( !empty( $robots ) )
				$robots .= ', ';

			$robots .= 'noydir';
		}

		if ( !empty( $robots ) )
			$robots = '<meta name="robots" content="' . $robots . '" />' . "\n";

		return $robots;
	}

	/**
	 * Output rel=canonical for singular queries
	 *
	 * @since 1.0.0
	 */
	function search_engines_meta_canonical() {
		if ( is_404() || is_search() )
			return false;

		global $wp_query;

		$id = $wp_query->get_queried_object_id();

		$canonical = '';

		remove_action( 'wp_head', 'rel_canonical' );

		if ( is_front_page() )
			$canonical = home_url( '/' );

		elseif ( is_singular() )
			$canonical = get_permalink( $id );

		elseif ( is_archive() ) {

			if ( is_category() || is_tag() || is_tax() ) {
				$term = $wp_query->get_queried_object();
				$canonical = get_term_link( $term, $term->taxonomy );
			}

			elseif ( is_author() )
				$canonical = get_author_posts_url( $id );

			elseif ( is_date() ) {

				if ( is_day() )
					$canonical = get_day_link( get_query_var( 'year' ), get_query_var( 'monthnum' ), get_query_var( 'day' ) );

				elseif ( is_month() )
					$canonical = get_month_link( get_query_var( 'year' ), get_query_var( 'monthnum' ) );

				elseif ( is_year() )
					$canonical = get_year_link( get_query_var( 'year' ) );
			}
		}

		if ( ( ( $page = $wp_query->get( 'paged' ) ) || ( $page = $wp_query->get( 'page' ) ) ) && $page > 1 )
			$canonical .= "page/$page/";

		if ( !empty( $canonical ) )
			return '<link rel="canonical" href="' . $canonical . '" />' . "\n";
	}

	/**
	 * Generates meta 'msvalidate.01', 'google-site-verification' and 'y_key'.
	 *
	 * @since 1.0.0
	 */
	function search_engines_meta_webmasters() {
		if ( !get_option( 'blog_public' ) || !search_engines_setting( 'webmasters_enable' ) )
			return;

		$webmasters = '';

		if ( $bing = search_engines_setting( 'webmasters_bing' ) )
			$webmasters[] = sprintf( '<meta name="msvalidate.01" content="%s" />', $bing );

		if ( $google = search_engines_setting( 'webmasters_google' ) )
			$webmasters[] = sprintf( '<meta name="google-site-verification" content="%s" />', $google );

		if ( $yahoo = search_engines_setting( 'webmasters_yahoo' ) )
			$webmasters[] = sprintf( '<meta name="y_key" content="%s" />', $yahoo );

		if ( !empty( $webmasters ) && is_array( $webmasters ) )
			return join( "\n", $webmasters );
	}

	/**
	 * Get public areas.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_public_areas() {
		$area = array();

		/* Front page */
		$area['frontpage'] = __( 'Front page', 'search-engines' );

		/* Gets available public post types. */
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $type )
			$area[$type->name] = $type->labels->singular_name;

		/* Gets available public taxonomies. */
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach( $taxonomies as $taxonomy )
			$area[$taxonomy->name] = $taxonomy->labels->singular_name;

		/* Author */
		$area['authors'] = __( 'Authors', 'search-engines' );

		/* Arvhives */
		$area['archives'] = __( 'Archives', 'search-engines' );

		/* Search */
		$area['search'] = __( 'Search', 'search-engines' );

		/* Error 404 */
		$area['error'] = __( 'Error', 'search-engines' );

		/* Remove 'nav_menu' and 'attachment'. */
		unset( $area['nav_menu'] );

		return $area;
	}

	/**
	 * Get current area of the site.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_current_area() {
		global $wp_query;

		$current = '';

		if ( is_front_page() && is_home() )
			$current = 'frontpage';

		elseif ( is_singular() || is_home() ) {
			$current = $wp_query->post->post_type;

			if ( is_front_page() )
				$current = 'frontpage';
		}

		elseif ( is_archive() ) {

			if ( is_tax() || is_category() || is_tag() ) {
				$term = $wp_query->get_queried_object();
				$current = $term->taxonomy;
			}

			elseif ( is_author() )
				$current = 'authors';

			else
				$current = 'archives';
		}

		elseif ( is_search() )
			$current = 'search';

		elseif ( is_404() )
			$current = 'error';

		return $current;
	}

	/**
	 * Format titles.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_format_title( $area ) {
		global $wp_query;

		/* Default formats. */
		$formats = array(
			'%title%' => __( 'Title', 'search-engines' ),
			'%title%%separator%%blogname%' => __( 'Title Separator Blogname', 'search-engines' ),
			'%title%%separator%%label%' => __( 'Title Separator Label', 'search-engines' ),
			'%title%%separator%%label%%separator%%blogname%' => __( 'Title Separator Label Separator Blogname', 'search-engines' ),
			'%blogname%' => __( 'Blogname', 'search-engines' ),
			'%blogname%%separator%%title%' => __( 'Blogname Separator Title', 'search-engines' ),
			'%blogname%%separator%%label%' => __( 'Blogname Separator Label', 'search-engines' ),
			'%blogname%%separator%%label%%separator%%title%' => __( 'Blogname Separator Label Separator Title', 'search-engines' ),
			'%label%%separator%%blogname%' => __( 'Label Separator Blogname', 'search-engines' )
		);

		/* Area formats. */
		switch( $area ) {

			case 'search':
				$area_formats = array(
					'%keyword%%separator%%blogname%' => __( 'Keyword Separator Blogname', 'search-engines' ),
					'%label%%keyword%' => __( 'Label Keyword', 'search-engines' ),
					'%label%%keyword%%separator%%blogname%' => __( 'Label Keyword Separator Blogname', 'search-engines' )
				);
				break;

			default:
				if ( post_type_exists( $area ) ) {
					$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

					foreach( $taxonomies as $taxonomy ) {
						if ( is_object_in_taxonomy( $area, $taxonomy->name ) ) {
							$area_formats["%blogname%%separator%%$taxonomy->name%"] = sprintf( __( 'Blogname Separator %1$s', 'search-engines' ), $taxonomy->labels->singular_name );
							$area_formats["%blogname%%separator%%$taxonomy->name%%separator%%title%"] = sprintf( __( 'Blogname Separator %1$s Separator Title', 'search-engines' ), $taxonomy->labels->singular_name );
							$area_formats["%blogname%%separator%%$taxonomy->name%%separator%%label%"] = sprintf( __( 'Blogname Separator %1$s Separator Label', 'search-engines' ), $taxonomy->labels->singular_name );
							$area_formats["%title%%separator%%$taxonomy->name%"] = sprintf( __( 'Title Separator %1$s', 'search-engines' ), $taxonomy->labels->singular_name );
							$area_formats["%title%%separator%%$taxonomy->name%%separator%%blogname%"] = sprintf( __( 'Title Separator %1$s Separator Blogname', 'search-engines' ), $taxonomy->labels->singular_name );
							$area_formats["%title%%separator%%$taxonomy->name%%separator%%label%"] = sprintf( __( 'Title Separator %1$s Separator Label', 'search-engines' ), $taxonomy->labels->singular_name );
						}
					}
				}

				break;
		}

		if ( !empty( $area_formats ) )
			$formats = array_merge( $formats, $area_formats );

		asort( $formats );

		return $formats;
	}

	/**
	 * Format descriptions.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_format_description( $area ) {
		global $wp_query;

		/* Default formats. */
		$formats = array(
			'%default%' => __( 'Default', 'search-engines' ),
		);

		/* Singular */
		if ( post_type_exists( $area ) )
			$area_formats['%excerpt%'] = __( 'Excerpt of the current post', 'search-engines' );

		/* Taxonomies and Archives */
		elseif ( taxonomy_exists( $area ) || 'authors' == $area )
			$area_formats['%description%'] = __( 'Description' , 'search-engines' );

		/* Search */
		elseif ( 'search' == $area || 'archives' == $area )
			$area_formats['%listed%'] = __( 'Titles of all listed posts', 'search-engines' );

		/* Error */
		elseif ( 'error' == $area )
			$area_formats['%no%'] = __( 'Nothing', 'search-engines' );

		if ( !empty( $area_formats ) )
			$formats = array_merge( $formats, $area_formats );

		asort( $formats );

		return $formats;
	}

	/**
	 * Format keywords.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_format_keywords( $area ) {
		global $wp_query;

		/* Default formats. */
		$formats = array(
			'%default%' => __( 'Default', 'search-engines' ),
		);

		/* Singular */
		if ( post_type_exists( $area ) && 'attachment' !== $area )
			$area_formats['%terms%'] = __( 'Terms of the current post', 'search-engines' );

		elseif ( 'attachment' === $area )
			$area_formats['%terms%'] = __( 'Post Mime Type', 'search-engines' );

		/* Taxonomies and Archives */
		elseif ( taxonomy_exists( $area ) || 'archives' == $area || 'search' == $area || 'authors' == $area )
			$area_formats['%terms%'] = __( 'Terms of all listed posts' , 'search-engines' );

		/* Error */
		elseif ( 'error' == $area )
			$area_formats['%no%'] = __( 'Nothing', 'search-engines' );

		if ( !empty( $area_formats ) )
			$formats = array_merge( $formats, $area_formats );

		asort( $formats );

		return $formats;
	}

	/**
	 * Format robots.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_format_robots() {
		$robots = array(
			'index',
			'index, follow',
			'index, nofollow',
			'noindex',
			'noindex, follow',
			'noindex, nofollow',
		);

		return $robots;
	}

	/**
	 * Retrieve a post's titles.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_post_titles() {
		global $posts;

		$titles = array();

		foreach ( $posts as $post )
			$titles[] = $post->post_title;

		if ( !empty( $titles ) )
			return join( ', ', $titles );
	}

	/**
	 * Retrieve a post's terms by taxonomy.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_post_taxonomy( $taxonomy ) {
		global $wp_query;

		if ( $terms = get_the_term_list( $wp_query->post->ID, $taxonomy, '', ', ', '' ) )
			$list[] = $terms;

		if ( !empty( $list ) )
			return strip_tags( join( ', ', $list ) );
	}

	/**
	 * Retrieve a post's terms.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_posts_taxonomies() {
		global $posts;

		$tax = '';

		foreach( $posts as $post ) {
			$taxonomies = get_object_taxonomies( $post->post_type );

			foreach( $taxonomies as $taxonomy ) {
				if ( $terms = get_the_term_list( $post->ID, $taxonomy, '', ', ', '' ) )
					$list[] = $terms;
			}

			if ( !empty( $list ) )
				$tax[] = strip_tags( join( ', ', $list ) );
		}

		if ( !empty( $tax ) )
			$tax = join( ', ', $tax );

		return $tax;

	}

	/**
	 * Protect RSS Feeds.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_feeds_protect() {
		if ( !search_engines_setting( 'robots_feeds' ) )
			return;

		add_action( 'rss_head', array( $this, 'search_engines_feed_noindex' ) );
		add_action( 'rss2_head', array( $this,'search_engines_feed_noindex' ) );
		add_action( 'atom_head', array( $this,'search_engines_feed_noindex' ) );
		add_action( 'rdf_header', array( $this,'search_engines_feed_noindex' ) );
		add_action( 'comments_atom_head', array( $this,'search_engines_feed_noindex' ) );
		add_action( 'commentsrss2_head', array( $this,'search_engines_feed_noindex' ) );
	}

	/**
	 * Protect Feeds.
	 *  
	 * @since 1.0.0
	 */
	function search_engines_feed_noindex() {
		echo '<xhtml:meta xmlns:xhtml="http://www.w3.org/1999/xhtml" name="robots" content="noindex" />' . "\n";
	}

	/**
	 * Loads the admin functions.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_admin() {

		if ( is_admin() ) {

			/* Add a link to the settings page to the plugins list. */
			add_filter( 'plugin_action_links_search-engines/search-engines.php', 'search_engines_action_link' );

			/* Crea la página de configuración. */
			add_action( 'admin_menu', array( $this, 'search_engines_admin_setup' ) );

			/* Crea meta-box para cada post type. */
			add_action( 'admin_menu', array( $this, 'search_engines_post_meta_box' ) );
		}
	}

	/**
	 * Add the settings page.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_admin_setup() {

		/* Register the plugin settings. */
		register_setting( 'search_engines_settings', 'search_engines_settings', 'search_engines_validate_settings' );

		/* Create the plugin settings page. */
		add_options_page( __( 'Search Engines Settings', 'search-engines' ), 'Search Engines', 'manage_options', 'search-engines-settings', 'search_engines_settings_page' );

		/* Contextual Help! */
		add_contextual_help( 'settings_page_search-engines-settings', sprintf( '<p>%s</p><p>%s</p>', __( 'If you operate your website for hobby or profit, SEO can be an important tool in making your website popular. SEO is not rocket science (or anywhere close to it). But it certainly can get as technical and detailed as you want to make it.', 'search-engines' ), __( 'This plugin is here to help you get your job done!', 'search-engines' ) ) );

		/* Register the default plugin settings meta boxes. */
		add_action( 'load-settings_page_search-engines-settings', 'search_engines_settings_meta_box' );

		/* Load the JavaScript and stylehsheets needed for the plugin settings. */
		add_action( 'load-settings_page_search-engines-settings', 'search_engines_page_enqueue_script' );
		add_action( 'load-settings_page_search-engines-settings', 'search_engines_admin_enqueue_style' );
		add_action( 'admin_head-settings_page_search-engines-settings', 'search_engines_page_load_scripts' );
	}

	/**
	 * Creates a meta box on the post (page, other post types).
	 *
	 * @since 1.0.0
	 */
	function search_engines_post_meta_box() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		/* For each available post type, create a meta box on its edit page. */
		foreach ( $post_types as $type )
			add_meta_box( "search-engines-{$type->name}-meta-box", __( 'Search Engine Optimization', 'search-engines' ), 'search_engines_post_meta_box', $type->name, 'normal', 'high' );

		add_action( 'save_post', 'search_engines_save_post_meta_box', 10, 2 );

		/* Delete the cache when a post is updated. */
		add_action( 'save_post', array( &$this, 'search_engines_delete_cache' ) );
		add_action( 'added_post_meta', array( &$this, 'search_engines_delete_cache' ) );
		add_action( 'updated_post_meta', array( &$this, 'search_engines_delete_cache' ) );
		add_action( 'deleted_post_meta', array( &$this, 'search_engines_delete_cache' ) );
	}

	/**
	 * Activation plugin.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_activation() {
		$settings = search_engines_default_settings();
		add_option( 'search_engines_settings', $settings, '', 'yes' );
	}

	/**
	 * Desactivation plugin.
	 * 
	 * @since 1.0.0
	 */
	function search_engines_desactivation() {
		delete_option( 'search_engines_settings' );
	}

	/**
	 * Delete the keywords relevance cache.
	 * 
	 * @since 1.0.1
	 */
	function search_engines_delete_cache() {
		wp_cache_delete( 'keywords_relevance' );
	}
}

$search_engines = new Search_Engines();

/**
 * Loads the plugin settings once and allows the input of the specific field the user would 
 * like to show.
 *
 * @since 1.0.0
 */
function search_engines_setting( $option = '' ) {
	$settings = array();

	if ( empty( $option ) )
		return false;

	$settings = get_option( 'search_engines_settings' );

	if ( !is_array( $settings ) || empty( $settings[$option] ) )
		return false;

	return $settings[$option];
}

/**
 * Validates the plugin settings.
 * 
 * @since 1.0.0
 */
function search_engines_validate_settings( $settings ) {
	return $settings;
}

/**
 * Settings page HTML.
 * 
 * @since 1.0.0
 */
function search_engines_settings_page() { ?>

	<div class="wrap">

		<?php screen_icon( 'search-engines' ); ?>

		<h2><?php _e( 'Search Engines Settings', 'search-engines' ); ?></h2>

		<form id="search_engines_options_form" action="<?php echo admin_url( 'options.php' ); ?>" method="post">

			<?php settings_fields( 'search_engines_settings' ); ?>
			<?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
			<?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>

			<div class="metabox-holder">
				<div class="post-box-container column-1 normal"><?php do_meta_boxes( 'settings_page_search-engines-settings', 'normal', null ); ?></div>
			</div>

			<p class="submit">
				<input class="button-primary" type="submit" name="Submit" value="<?php esc_attr_e( 'Save Changes' ); ?>" />
			</p> <!-- .submit -->

		</form>

	</div> <?php
}

/**
 * Create an array of the defaults settings.
 * 
 * @since 1.0.0
 */
function search_engines_default_settings() {

	$settings = array();

	/* Titles */
	$settings['title_enable'] = 'true';
	$settings['title_format_frontpage'] = '%blogname%%separator%%title%';

	foreach( Search_Engines::search_engines_public_areas() as $area => $name ) {
		if ( 'frontpage' !== $area )
			$settings['title_format_' . $area] = '%title%%separator%%blogname%';
	}

	$settings['title_separator'] = '»';

	/* Description */
	$settings['description_enable'] = 'true';
	$settings['description_default'] = get_bloginfo( 'description' );
	$settings['description_words'] = 20;

	foreach( Search_Engines::search_engines_public_areas() as $area => $name ) {
		if ( 'frontpage' == $area )
			$settings['description_format_' . $area] = '%default%';

		elseif ( post_type_exists( $area ) )
			$settings['description_format_' . $area] = '%excerpt%';

		elseif ( taxonomy_exists( $area ) || 'authors' == $area )
			$settings['description_format_' . $area] = '%description%';

		elseif ( 'archives' == $area || 'search' == $area )
			$settings['description_format_' . $area] = '%listed%';

		elseif ( 'error' == $area )
			$settings['description_format_' . $area] = '%no%';
	}

	/* Keywords */
	$settings['keywords_enable'] = 'true';
	$settings['keywords_words'] = 8;
	$settings['keywords_short'] = 3;

	$settings['keywords_format_frontpage'] = '%default%';
	$settings['keywords_format_error'] = '%no%';

	foreach( Search_Engines::search_engines_public_areas() as $area => $name ) {
		if ( 'frontpage' !== $area && 'error' !== $area )
			$settings['keywords_format_' . $area] = '%terms%';
	}

	/* Robots */
	$settings['robots_enable'] = 'true';
	$settings['robots_error'] = 'noindex, nofollow';

	foreach( Search_Engines::search_engines_public_areas() as $area => $name ) {
		if ( 'error' !== $area )
			$settings['robots_' . $area] = 'index, nofollow';
	}

	/* Webmasters */
	$settings['webmasters_enable'] = 'true';

	return $settings;
}

/**
 * Creates the default meta boxes for the plugin settings page.
 *
 * @since 1.0.0
 */
function search_engines_settings_meta_box() {

	/* Creates a meta box for the Document Title settings. */
	add_meta_box( 'document-title-meta-box', __( 'Document Title', 'search-engines' ), 'search_engines_document_title_meta_box', 'settings_page_search-engines-settings', 'normal', 'high' );

	/* Creates a meta box for the Meta Description content settings. */
	add_meta_box( 'edit-meta-description-meta-box', __( 'Edit meta description', 'search-engines' ), 'search_engines_edit_meta_description_meta_box', 'settings_page_search-engines-settings', 'normal', 'high' );

	/* Creates a meta box for the Meta Description content settings. */
	add_meta_box( 'edit-meta-keywords-meta-box', __( 'Edit meta keywords', 'search-engines' ), 'search_engines_edit_meta_keywords_meta_box', 'settings_page_search-engines-settings', 'normal', 'high' );

	/* Creates a meta box for the Avoid duplicate content settings. */
	add_meta_box( 'duplicate-content-meta-box', __( 'Avoid duplicate content', 'search-engines' ), 'search_engines_duplicate_content_meta_box', 'settings_page_search-engines-settings', 'normal', 'high' );

	/* Creates a meta box for the Webmasters Tools content settings. */
	add_meta_box( 'webmasters-tools-meta-box', __( 'Webmasters Tools Verification', 'search-engines' ), 'search_engines_webmasters_tools_meta_box', 'settings_page_search-engines-settings', 'normal', 'high' );
}

/**
 * Adds a title settings suite suitable for the plugin.
 *
 * @since 1.0.0
 */
function search_engines_document_title_meta_box() { ?>

	<table class="form-table">
		<tr>
			<th colspan="2" scope="row">
				<input name="search_engines_settings[title_enable]" id="search_engines_settings-title_enable" type="checkbox" <?php checked( search_engines_setting( 'title_enable' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-title_enable"><?php _e( 'Activate formatting of the title', 'search-engines' ); ?></label>
			</th>
		</tr>
		<tr>
			<th><?php _e( 'Format', 'search-engines' ); ?></th>
			<td>
				<?php foreach( Search_Engines::search_engines_public_areas() as $area => $name ) : ?>
					<label class="area" for="search_engines_settings-title_format_<?php echo $area; ?>"><?php echo $name; ?></label>
					<select id="search_engines_settings-title_format_<?php echo $area; ?>" name="search_engines_settings[title_format_<?php echo $area; ?>]">
					<?php foreach( Search_Engines::search_engines_format_title( $area ) as $title => $title_text ) : ?>
						<option value="<?php echo $title; ?>" <?php selected( search_engines_setting( "title_format_$area" ), $title ); ?>><?php echo $title_text; ?></option>						
					<?php endforeach; ?>
					</select>
					<br />
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Separator', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-title_separator" class="full-width-setting" name="search_engines_settings[title_separator]" type="text" value="<?php echo search_engines_setting( 'title_separator' ); ?>" /><br />
				<label for="search_engines_settings-title_separator"><?php _e( 'Special signs can be entered as HTML. Example: &amp;raquo; becomes &raquo;', 'search-engines' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Labels', 'search-engines' ); ?></th>
			<td>
				<?php foreach( Search_Engines::search_engines_public_areas() as $area => $name ) : ?>
					<label class="area" for="search_engines_settings-title_label_<?php echo $area; ?>"><?php echo $name; ?></label>
					<input id="search_engines_settings-title_label_<?php echo $area; ?>" name="search_engines_settings[title_label_<?php echo $area; ?>]" type="text" value="<?php echo search_engines_setting( "title_label_$area" ); ?>" /><br />
				<?php endforeach; ?>
			</td>
		</tr>
	</table><!-- .form-table --> <?php
}

/**
 * Adds a meta description settings suite suitable for the plugin.
 *
 * @since 1.0.0
 */
function search_engines_edit_meta_description_meta_box() { ?>

	<table class="form-table">
		<tr>
			<th colspan="2" scope="row">
				<input name="search_engines_settings[description_enable]" id="search_engines_settings-description_enable" type="checkbox" <?php checked( search_engines_setting( 'description_enable' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-description_enable"><?php _e( 'Activate formatting of the meta description', 'search-engines' ); ?></label>
			</th>
		</tr>
		<tr>
			<th><?php _e( 'Default', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-description_default" class="full-width-setting" name="search_engines_settings[description_default]" type="text" value="<?php echo search_engines_setting( 'description_default' ); ?>" /><br />
				<label for="search_engines_settings-description_default"><?php _e( 'Only used if "Default" selected in the "Dynamic value"', 'search-engines' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Dynamic value', 'search-engines' ); ?></th>
			<td>
				<?php foreach( Search_Engines::search_engines_public_areas() as $area => $name ) : ?>
					<label class="area" for="search_engines_settings-description_format_<?php echo $area; ?>"><?php echo $name; ?></label>
					<select id="search_engines_settings-description_format_<?php echo $area; ?>" name="search_engines_settings[description_format_<?php echo $area; ?>]">
					<?php foreach( Search_Engines::search_engines_format_description( $area ) as $description => $description_text ) : ?>
						<option value="<?php echo $description; ?>" <?php selected( search_engines_setting( "description_format_$area" ), $description ); ?>><?php echo $description_text; ?></option>						
					<?php endforeach; ?>
					</select>
					<br />
				<?php endforeach; ?>
				<input id="search_engines_settings-description_auto" name="search_engines_settings[description_auto]" type="checkbox" <?php checked( search_engines_setting( 'description_auto' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-description_auto"><?php _e( 'Don\'t generate automatically the descriptions', 'search-engines' ) ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Number of words', 'search-engines' ); ?></th>
			<td>
				<select id="search_engines_settings-description_words" class="full-width-setting" name="search_engines_settings[description_words]">
				<?php for( $i = 5; $i <= 25; $i = $i + 5 ) : ?>
					<option value="<?php echo $i; ?>" <?php selected( search_engines_setting( 'description_words' ), $i ); ?>><?php echo $i; ?></option>
				<?php endfor; ?>
				</select>
				<br />
				<label for="search_engines_settings-description_words"><?php _e( 'Maximum count of words, after that it will cut', 'search-engines' ) ?></label>
			</td>
		</tr>
	</table><!-- .form-table --> <?php
}

/**
 * Adds a meta keywords settings suite suitable for the plugin.
 *
 * @since 1.0.0
 */
function search_engines_edit_meta_keywords_meta_box() { ?>

	<table class="form-table">
		<tr>
			<th colspan="2" scope="row">
				<input name="search_engines_settings[keywords_enable]" id="search_engines_settings-keywords_enable" type="checkbox" <?php checked( search_engines_setting( 'keywords_enable' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-keywords_enable"><?php _e( 'Activate formatting of the meta keywords', 'search-engines' ); ?></label>
			</th>
		</tr>
		<tr>
			<th><?php _e( 'Default', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-keywords_default" class="full-width-setting" name="search_engines_settings[keywords_default]" type="text" value="<?php echo search_engines_setting( 'keywords_default' ); ?>" /><br />
				<label for="search_engines_settings-keywords_default"><?php _e( 'Only used if "Default" selected in the "Dynamic value"', 'search-engines' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Dynamic value', 'search-engines' ); ?></th>
			<td>
				<?php foreach( Search_Engines::search_engines_public_areas() as $area => $name ) : ?>
					<label class="area" for="search_engines_settings-keywords_format_<?php echo $area; ?>"><?php echo $name; ?></label>
					<select id="search_engines_settings-keywords_format_<?php echo $area; ?>" name="search_engines_settings[keywords_format_<?php echo $area; ?>]">
					<?php foreach( Search_Engines::search_engines_format_keywords( $area ) as $keywords => $keywords_text ) : ?>
						<option value="<?php echo $keywords; ?>" <?php selected( search_engines_setting( "keywords_format_$area" ), $keywords ); ?>><?php echo $keywords_text; ?></option>						
					<?php endforeach; ?>
					</select>
					<br />
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Number of words', 'search-engines' ); ?></th>
			<td>
				<select id="search_engines_settings-keywords_words" class="full-width-setting" name="search_engines_settings[keywords_words]">
				<?php for( $i = 6; $i <= 20; $i = $i + 2 ) : ?>
					<option value="<?php echo $i; ?>" <?php selected( search_engines_setting( 'keywords_words' ), $i ) ?>><?php echo $i; ?></option>
				<?php endfor; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Short words', 'search-engines' ); ?></th>
			<td>
				<select id="search_engines_settings-keywords_short" class="full-width-setting" name="search_engines_settings[keywords_short]">
				<?php for( $i = 1; $i <= 10; $i++ ) : ?>
					<option value="<?php echo $i; ?>" <?php selected( search_engines_setting( 'keywords_short' ), $i ) ?>><?php echo $i; ?></option>
				<?php endfor; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Relevance', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-keywords_relevance" name="search_engines_settings[keywords_relevance]" type="checkbox" <?php checked( search_engines_setting( 'keywords_relevance' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-keywords_relevance"><?php printf( '%1$s <br /> %2$s', __( 'Words between HTML tags will be interpreted as most relevant keywords', 'search-engines' ), __( 'Detects the following XHTML tags: &lt;em>...&lt;/em>, &lt;strong>...&lt;/strong>', 'search-engines' ) ); ?></label>
			</td>
		</tr>
	</table><!-- .form-table --> <?php
}

/**
 * Adds a meta robots settings suite suitable for the plugin.
 *
 * @since 1.0.0
 */
function search_engines_duplicate_content_meta_box() { ?>

	<table class="form-table">
		<tr>
			<th colspan="2" scope="row">
				<input name="search_engines_settings[robots_enable]" id="search_engines_settings-robots_enable" type="checkbox" <?php checked( search_engines_setting( 'robots_enable' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-robots_enable"><?php _e( 'Activate integration of the Robots tag', 'search-engines' ); ?></label>
			</th>
		</tr>
		<tr>
			<th><?php _e( 'Robots', 'search-engines' ); ?></th>
			<td>
				<?php foreach( Search_Engines::search_engines_public_areas() as $area => $name ) : ?>
					<label class="area" for="search_engines_settings-robots_<?php echo $area; ?>"><?php echo $name; ?></label>
					<select id="search_engines_settings-robots_<?php echo $area; ?>" name="search_engines_settings[robots_<?php echo $area; ?>]">
						<?php foreach( Search_Engines::search_engines_format_robots() as $robots ) : ?>
							<option value="<?php echo $robots; ?>" <?php selected( search_engines_setting( "robots_$area" ), $robots ); ?>><?php echo $robots; ?></option>
						<?php endforeach; ?>
					</select> <br />
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Protect RSS Feeds', 'search-engines' ) ?></th>
			<td>
				<input id="search_engines_settings-robots_feeds" name="search_engines_settings[robots_feeds]" type="checkbox" <?php checked( search_engines_setting( 'robots_feeds' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-robots_feeds"><?php _e( 'Prevent indexing of RSS Feeds by search engines', 'search-engines' ) ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'ODP snippet', 'search-engines' ) ?></th>
			<td>
				<input id="search_engines_settings-robots_odp" name="search_engines_settings[robots_odp]" type="checkbox" <?php checked( search_engines_setting( 'robots_odp' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-robots_odp"><?php _e( 'Add the &lt;meta name="robots" content="noodp" /> meta tag to the source code', 'search-engines' ) ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Noarchive snippet', 'search-engines' ) ?></th>
			<td>
				<input id="search_engines_settings-robots_noarchive" name="search_engines_settings[robots_noarchive]" type="checkbox" <?php checked( search_engines_setting( 'robots_noarchive' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-robots_noarchive"><?php _e( 'Add the &lt;meta name="robots" content="noarchive" /> meta tag to the source code', 'search-engines' ) ?></label>
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Yahoo! Directory', 'search-engines' ) ?></th>
			<td>
				<input id="search_engines_settings-robots_noydir" name="search_engines_settings[robots_noydir]" type="checkbox" <?php checked( search_engines_setting( 'robots_noydir' ), 'true' ); ?> value="true" />
				<label for="search_engines_settings-robots_noydir"><?php _e( 'Add the &lt;meta name="robots" content="noydir" /> meta tag to the source code', 'search-engines' ) ?></label>
			</td>
		</tr>
	</table><!-- .form-table --> <?php
}

/**
 * Adds a webmasters tools settings suite suitable for the plugin.
 *
 * @since 1.0.0
 */
function search_engines_webmasters_tools_meta_box() { ?>

	<table class="form-table">
	<tr>
		<th colspan="2" scope="row">
			<input name="search_engines_settings[webmasters_enable]" id="search_engines_settings-webmasters_enable" type="checkbox" <?php checked( search_engines_setting( 'webmasters_enable' ), 'true' ); ?> value="true" />
			<label for="search_engines_settings-webmasters_enable"><?php _e( 'Activate integration of the Webmasters Tools. If your site is already verified, you can just forget about these', 'search-engines' ); ?></label>
		</th>
	</tr>
		<tr>
			<th><?php _e( 'Bing', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-webmasters_bing" class="full-width-setting" name="search_engines_settings[webmasters_bing]" type="text" value="<?php echo search_engines_setting( 'webmasters_bing' ); ?>" />
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Google', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-webmasters_google" class="full-width-setting" name="search_engines_settings[webmasters_google]" type="text" value="<?php echo search_engines_setting( 'webmasters_google' ); ?>" />
			</td>
		</tr>
		<tr>
			<th><?php _e( 'Yahoo!', 'search-engines' ); ?></th>
			<td>
				<input id="search_engines_settings-webmasters_yahoo" class="full-width-setting" name="search_engines_settings[webmasters_yahoo]" type="text" value="<?php echo search_engines_setting( 'webmasters_yahoo' ); ?>" />
			</td>
		</tr>
	</table><!-- .form-table --> <?php	
}

/**
 * Loads the JavaScript files required for managing the meta boxes on the plugin settings
 * page, which allows users to arrange the boxes to their liking.
 *
 * @since 1.0.0
 */
function search_engines_page_enqueue_script() {
	wp_enqueue_script( 'common' );
	wp_enqueue_script( 'wp-lists' );
	wp_enqueue_script( 'postbox' );
}

/**
 * Loads the admin.css stylesheet for admin-related features.
 *
 * @since 1.0.0
 */
function search_engines_admin_enqueue_style() {
	wp_enqueue_style( 'search-engines-admin', plugin_dir_url( __FILE__ ) . 'css/search-engines.css', false, '1.0.1', 'screen' );
}

/**
 * Loads the JavaScript required for toggling the meta boxes on the plugin settings page.
 *
 * @since 1.0.0
 */
function search_engines_page_load_scripts() { ?>
	<script type="text/javascript">
		//<![CDATA[
		jQuery(document).ready( function($) {
			$('.if-js-closed').removeClass('if-js-closed').addClass('closed');
			postboxes.add_postbox_toggles( 'settings_page_search-engines-settings' );
			$('#search_engines_options_form input[id$=_enable]').click(search_engines_enable_form);
			$('#search_engines_options_form input[id$=_enable]').each(search_engines_enable_form);
			function search_engines_enable_form() {
				var status = !$(this).attr('checked');
				$('#search_engines_options_form [id^=' + this.id.replace('_enable', '') + ']').not('[id$=_enable]').each(function() {
					$(this).attr('disabled', status);
				});
			}
		});
		//]]>
	</script><?php
}

/**
 * Displays the post meta box on the edit post page.
 *
 * @since 1.0.0
 */
function search_engines_post_meta_box( $object ) { ?>

	<input type="hidden" name="<?php echo "search_engines_{$object->post_type}_meta_box_nonce"; ?>" value="<?php echo wp_create_nonce( basename( __FILE__ ) ); ?>" />

	<div class="search-engines-post-settings">

		<p>
			<label for="_nifty_title"><?php _e( 'Document Title:', 'search-engines' ); ?></label>
			<input name="_nifty_title" id="_nifty_title" value="<?php echo get_post_meta( $object->ID, '_nifty_title', true ); ?>" size="30" tabindex="30" style="width: 99%;" type="text">
		</p>
		<p>
			<label for="_nifty_description"><?php _e( 'Meta Description:', 'search-engines' ); ?></label>
			<input name="_nifty_description" id="_nifty_description" value="<?php echo get_post_meta( $object->ID, '_nifty_description', true ); ?>" size="30" tabindex="30" style="width: 99%;" type="text">
		</p>
		<p>
			<label for="_nifty_keywords"><?php _e( 'Meta Keywords:', 'search-engines' ); ?></label>
			<input name="_nifty_keywords" id="_nifty_keywords" value="<?php echo get_post_meta( $object->ID, '_nifty_keywords', true ); ?>" size="30" tabindex="30" style="width: 99%;" type="text">
		</p>
		<p>
			<label for="_nifty_robots"><?php _e( 'Meta Robots:', 'search-engines' ); ?></label>
			<input name="_nifty_robots" id="_nifty_robots" value="<?php echo get_post_meta( $object->ID, '_nifty_robots', true ); ?>" size="30" tabindex="30" style="width: 99%;" type="text">
		</p>
		<p>
			<label for="_nifty_redirect"><?php _e( '301 Redirect:', 'search-engines' ); ?></label>
			<input name="_nifty_redirect" id="_nifty_redirect" value="<?php echo get_post_meta( $object->ID, '_nifty_redirect', true ); ?>" size="30" tabindex="30" style="width: 99%;" type="text">
		</p>

	</div> <!-- .search-engines-post-settings --><?php
}

/**
 * The function for saving the plugins's post meta box settings.
 *
 * @since 1.0.0
 */
function search_engines_save_post_meta_box( $post_id, $post ) {

	if ( !isset( $_POST["search_engines_{$post->post_type}_meta_box_nonce"] ) || !wp_verify_nonce( $_POST["search_engines_{$post->post_type}_meta_box_nonce"], basename( __FILE__ ) ) )
		return $post_id;

	$post_type = get_post_type_object( $post->post_type );

	if ( !current_user_can( $post_type->cap->edit_post, $post_id ) )
		return $post_id;

	$metadata = array( '_nifty_title', '_nifty_description', '_nifty_keywords', '_nifty_robots', '_nifty_redirect' );

	foreach ( $metadata as $meta ) {

		$meta_value = get_post_meta( $post_id, $meta, true );

		$new_meta_value = stripslashes( $_POST[ preg_replace( "/[^A-Za-z_-]/", '-', $meta ) ] );

		if ( $new_meta_value && '' == $meta_value )
			add_post_meta( $post_id, $meta, $new_meta_value, true );

		elseif ( $new_meta_value && $new_meta_value != $meta_value )
			update_post_meta( $post_id, $meta, $new_meta_value );

		elseif ( '' == $new_meta_value && $meta_value )
			delete_post_meta( $post_id, $meta, $meta_value );
	}
}

/**
 * Add a link to the settings page to the plugins list.
 * 
 * @since 1.0.1
 */
function search_engines_action_link( $links ) {
	$settings = '<a href="' . admin_url( 'options-general.php?page=search-engines-settings' ) . '">' . __( 'Settings', 'search-engines' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
}
