<?php
/**
 * Escape NGG command
 *
 * Based on Kovshenin's Escape NGG (http://github.com/kovshenin/escape-ngg) this WP-CLI command is useful
 * When you have a lot of posts and a lot of NGG galleries
 * 
 * @package wp-cli
 * @subpackage commands/community
 * @maintainer Rinat Khaziev (http://github.com/rinatkhaziev)
 * 
 */
class Escape_NGG_Command extends WP_CLI_Command {

	/**
	 * Convert NGG galleries to core WP galleries ( args don't really matter as of now, pull requests welcome :) )
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show more information about the process on STDOUT.
	 *
	 * ## FILTERS
	 *
	 * [--start_date=<date>]
	 * : Export only posts newer than this date, in format YYYY-MM-DD.
	 *
	 * [--end_date=<date>]
	 * : Export only posts older than this date, in format YYYY-MM-DD.
	 *
	 * [--post__in=<pid>]
	 * : Export all posts specified as a comma-separated list of IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp escape-ngg
	 */
	public function __invoke( $_, $assoc_args ) {
		$defaults = array(
			'start_date'      => NULL,
			'end_date'        => NULL,
			'post_type'       => NULL,
			'author'          => NULL,
			'category'        => NULL,
			'post_status'     => NULL,
			'post__in'        => NULL,
			'verbose'         => false,
		);

		$args = wp_parse_args( $assoc_args, $defaults );

		$has_errors = false;

		foreach ( $args as $key => $value ) {
			if ( is_callable( array( $this, 'check_' . $key ) ) ) {
				$result = call_user_func( array( $this, 'check_' . $key ), $value );
				if ( false === $result )
					$has_errors = true;
			}
		}

		if ( $has_errors ) {
			exit(1);
		}

		WP_CLI::line( 'Running for our lives...' );
		WP_CLI::line();
		$this->escape_ngg( $args );
	}

	private function stop_the_insanity() {
		global $wpdb, $wp_object_cache;
		$wpdb->queries = array(); // or define( 'WP_IMPORTING', true );
		if ( !is_object( $wp_object_cache ) )
			return;
		$wp_object_cache->group_ops = array();
		$wp_object_cache->stats = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache = array();
		if ( method_exists( $wp_object_cache, '__remoteset' ) )
			$wp_object_cache->__remoteset();
	}


	function escape_ngg( $args ) {
		global $wpdb;
		$uploads = wp_upload_dir();
		$baseurl = $uploads['baseurl'];
		$count = array(
			'posts' => 0,
			'images' => 0,
		);

		$query = array(
			's' => '[nggallery',
			'post_type' => array( 'post', 'page' ),
			'post_status' => 'any',
			'posts_per_page' => 50,
			'offset' => 0,
		);

		while ( $posts = get_posts( $query ) ) {
			foreach ( $posts as $post ) {
				$query['offset']++;
				$matches = null;

				preg_match( '#nggallery id(\s)*="?(\s)*(?P<id>\d+)#i', $post->post_content, $matches );
				if ( ! isset( $matches['id'] ) ) {
					WP_CLI::line( sprintf( "Could not match gallery id in %d<br />", $post->ID ) );
					continue;
				}

				// If there are existing images attached the post, 
				// let's remember to exclude them from our new gallery.
				$existing_attachments_ids = get_posts( array(
					'post_type' => 'attachment',
					'post_status' => 'inherit',
					'post_parent' => $post->ID,
					'post_mime_type' => 'image',
					'fields' => 'ids',
				) );

				$gallery_id = $matches['id'];
				$path = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ngg_gallery WHERE gid = ". intval( $gallery_id ), ARRAY_A  );
				$images = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE galleryid = ". intval( $gallery_id ) . " ORDER BY sortorder, pid ASC" );

				if ( ! $path || ! $images ) {
					WP_CLI::warning( sprintf( "Could not find images for nggallery %d<br />", $gallery_id ) );
					continue;
				}

				foreach ( $images as $image ) {
					$url = home_url( trailingslashit( $path['path'] ) . $image->filename );

					// Let's use a hash trick here to find our attachment post after it's been sideloaded.
					$hash = md5( 'attachment-hash' . $url . $image->description . time() . rand( 1, 999 ) );

					media_sideload_image( $url, $post->ID, $hash );
					$attachments = get_posts( array(
						'post_parent' => $post->ID,
						's' => $hash,
						'post_type' => 'attachment',
						'posts_per_page' => -1,
					) );

					if ( ! $attachments || ! is_array( $attachments ) || count( $attachments ) != 1 ) {
						WP_CLI::warning( "Could not insert attachment for " . $post->ID  );
						continue;
					}

					// Titles should fallback to the filename.
					if ( ! trim( $image->alttext ) ) {
						$image->alttext = $image->filename;
					}

					$attachment = $attachments[0];
					$attachment->post_title = $image->alttext;
					$attachment->post_content = $image->description;
					$attachment->menu_order = $image->sortorder;

					update_post_meta( $attachment->ID, '_wp_attachment_image_alt', $image->alttext );

					wp_update_post( $attachment );
					$count['images']++;
					WP_CLI::line( "Added attachment for " . $post->ID );
				}

				// Construct the [gallery] shortcode
				$attr = array();
				if ( $existing_attachments_ids )
					$attr['exclude'] = implode( ',', $existing_attachments_ids );

				$gallery = '[gallery';
				foreach ( $attr as $key => $value )
					$gallery .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
				$gallery .= ']';

				// Booyaga!
				$post->post_content = preg_replace( '#\[nggallery[^\]]*\]#i', $gallery, $post->post_content );
				wp_update_post( $post );
				$query['offset']--; // Since this post will no longer contain the [nggallery] it won't count against our offset
				$count['posts']++;
				WP_CLI::line( "Updated post " . $post->ID );
			}
			$this->stop_the_insanity();
		}
		WP_CLI::success( "All done!" );
	}
}

WP_CLI::add_command( 'escape-ngg', 'Escape_NGG_Command' );