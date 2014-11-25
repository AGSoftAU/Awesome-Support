<?php
/**
 * Admin Tickets List.
 *
 * @package   Admin/Tickets List
 * @author    Julien Liabeuf <julien@liabeuf.fr>
 * @license   GPL-2.0+
 * @link      http://themeavenue.net
 * @copyright 2014 ThemeAvenue
 */

class WPAS_Tickets_List {

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 * @var      object
	 */
	protected static $instance = null;

	public function __construct() {
		add_action( 'manage_' . WPAS_PT_SLUG . '_posts_columns',        array( $this, 'add_core_custom_columns' ), 16, 1 );
		add_action( 'manage_' . WPAS_PT_SLUG . '_posts_custom_column' , array( $this, 'core_custom_columns_content' ), 10, 2 );
		add_action( 'restrict_manage_posts',                            array( $this, 'unreplied_filter' ), 9, 0 );
		add_filter( 'the_excerpt',                                      array( $this, 'remove_excerpt' ) );
		// add_filter( 'update_user_metadata',                             array( $this, 'set_list_mode' ), 10, 5 );
		// add_filter( 'parse_query',                                      array( $this, 'filter_by_replies' ), 10, 1 );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     3.0.0
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Add age custom column.
	 *
	 * Add this column after the date.
	 *
	 * @since  3.0.0
	 * @param  array $columns List of default columns
	 * @return array          Updated list of columns
	 */
	public function add_core_custom_columns( $columns ) {

		$new = array();

		/**
		 * Parse the old columns and add the new ones.
		 */
		foreach ( $columns as $col_id => $col_label ) {

			/* Remove the date column that's replaced by the activity column */
			if ( 'date' !== $col_id ) {
				$new[$col_id] = $col_label;
			} else {
				/* Add the activity column */
				$new = array_merge( $new, array( 'wpas-activity' => __( 'Activity', 'wpas' ) ) );
			}

		}

		return $new;
	}

	/**
	 * Manage core column content.
	 *
	 * @since  3.0.0
	 * @param  array   $column  Column currently processed
	 * @param  integer $post_id ID of the post being processed
	 */
	public function core_custom_columns_content( $column, $post_id ) {

		$mode = get_user_setting( 'tickets_list_mode', 'details' );

		switch ( $column ) {

			case 'wpas-activity':

				$latest        = null;
				$tags          = array();
				$activity_meta = get_transient( "wpas_activity_meta_post_$post_id" );

				if ( false === $activity_meta ) {

					$post                         = get_post( $post_id );
					$activity_meta                = array();
					$activity_meta['ticket_date'] = $post->post_date;

					/* Get the last reply if any */
					$latest = new WP_Query(  array(
						'posts_per_page'         =>	1,
						'orderby'                =>	'post_date',
						'order'                  =>	'DESC',
						'post_type'              =>	'ticket_reply',
						'post_parent'            =>	$post_id,
						'post_status'            =>	array( 'unread', 'read' ),
						'no_found_rows'          => true,
						'cache_results'          => false,
						'update_post_term_cache' => false,
						'update_post_meta_cache' => false,
						)
					);

					if ( !empty( $latest->posts ) ) {
						$user_data                      = get_user_by( 'id', $latest->post->post_author );
						$activity_meta['user_link']     = add_query_arg( array( 'user_id' => $latest->post->post_author ), admin_url( 'user-edit.php' ) );
						$activity_meta['user_id']       = $latest->post->post_author;
						$activity_meta['user_nicename'] = $user_data->user_nicename;
						$activity_meta['reply_date']    = $latest->post->post_date;
					}

					set_transient( "wpas_activity_meta_post_$post_id", $activity_meta, apply_filters( 'wpas_activity_meta_transient_lifetime', 60*60*1 ) ); // Set to 1 hour by default

				}

				echo '<ul>';

				// if ( isset( $mode ) && 'details' == $mode ):
				if ( 1 === 1 ):

					?><li><?php printf( _x( 'Created %s ago.', 'Ticket created on', 'wpas' ), human_time_diff( get_the_time( 'U', $post_id ), current_time( 'timestamp' ) ) ); ?></li><?php

					/**
					 * We check when was the last reply (if there was a reply).
					 * Then, we compute the ticket age and if it is considered as 
					 * old, we display an informational tag.
					 */
					if ( !isset( $activity_meta['reply_date'] ) ) {
						echo '<li>';
						echo _x( 'No reply yet.', 'No last reply', 'wpas' );
						echo '</li>';
					} else {

						$args = array(
							'post_parent'            => $post_id,
							'post_type'              => 'ticket_reply',
							'post_status'            => array( 'unread', 'read' ),
							'posts_per_page'         => -1,
							'no_found_rows'          => true,
							'cache_results'          => false,
							'update_post_term_cache' => false,
							'update_post_meta_cache' => false,	
						);
					
						$query = new WP_Query( $args );
						$role  = true === user_can( $activity_meta['user_id'], 'edit_ticket' ) ? _x( 'agent', 'User role', 'wpas' ) : _x( 'client', 'User role', 'wpas' );

						?><li><?php echo _x( sprintf( _n( '%s reply.', '%s replies.', $query->post_count, 'wpas' ), $query->post_count ), 'Number of replies to a ticket', 'wpas' ); ?></li><?php
						?><li><?php printf( _x( 'Last replied %s ago by %s (%s).', 'Last reply ago', 'wpas' ), human_time_diff( strtotime( $activity_meta['reply_date'] ), current_time( 'timestamp' ) ), '<a href="' . $activity_meta['user_link'] . '">' . $activity_meta['user_nicename'] . '</a>', $role ); ?></li><?php
						?><li><?php //printf( _x( 'Last replied by %s.', 'Last reply author', 'wpas' ), '<a href="' . $activity_meta['user_link'] . '">' . $activity_meta['user_nicename'] . '</a>' ); ?></li><?php	
					}

				endif;

				/**
				 * Add tags
				 */
				if ( true === wpas_is_reply_needed( $post_id, $latest ) ) {
					array_push( $tags, "<span class='wpas-label' style='background-color:#0074a2;'>" . __( 'Awaiting Reply', 'wpas' ) . "</span>" );
				}
				

				if ( true === wpas_is_ticket_old( $post_id, $latest ) ) {
					$old_color = wpas_get_option( 'color_old' );
					array_push( $tags, "<span class='wpas-label' style='background-color:$old_color;'>" . __( 'Old', 'wpas' ) . "</span>" );
				}

				if ( !empty( $tags ) ) {
					echo '<li>' . implode( ' ', $tags ) . '</li>';
				}

				echo '</ul>';

			break;

		}

	}

	/**
	 * Add status dropdown in the filters bar.
	 *
	 * @since  2.0.0
	 */
	public function unreplied_filter() {

		global $typenow;

		if ( WPAS_PT_SLUG != $typenow ) {
			return;
		}

		$this_sort       = isset( $_GET['wpas_replied'] ) ? $_GET['wpas_replied'] : '';
		$all_selected    = ( '' === $this_sort ) ? 'selected="selected"' : '';
		$replied_selected   = ( 'replied' === $this_sort ) ? 'selected="selected"' : '';
		$unreplied_selected = ( 'unreplied' === $this_sort ) ? 'selected="selected"' : '';
		$dropdown        = '<select id="wpas_status" name="wpas_replied">';
		$dropdown        .= "<option value='' $all_selected>" . __( 'Any Reply Status', 'wpas' ) . "</option>";
		$dropdown        .= "<option value='replied' $replied_selected>" . __( 'Replied', 'wpas' ) . "</option>";
		$dropdown        .= "<option value='unreplied' $unreplied_selected>" . __( 'Unreplied', 'wpas' ) . "</option>";
		$dropdown        .= '</select>';

		echo $dropdown;

	}

	/**
	 * Filter tickets by status.
	 *
	 * When filtering, WordPress uses the ID by default in the query but
	 * that doesn't work. We need to convert it to the taxonomy term.
	 *
	 * @since  3.0.0
	 * @param  object $query WordPress current main query
	 */
	function filter_by_replies( $query ) {

		global $pagenow;

		/* Check if we are in the correct post type */
		if ( is_admin()
			&& 'edit.php' == $pagenow
			&& isset( $_GET['post_type'] )
			&& WPAS_PT_SLUG == $_GET['post_type']
			&& isset( $_GET['wpas_replied'] )
			&& !empty( $_GET['wpas_replied'] )
			&& $query->is_main_query() ) {

			print_r( $query );
			// $query->query_vars['meta_key']     = '_wpas_status';
			// $query->query_vars['meta_value']   = sanitize_text_field( $_GET['wpas_status'] );
			// $query->query_vars['meta_compare'] = '=';
		}

	}

	/**
	 * Remove the ticket excerpt.
	 *
	 * We don't want ot display the ticket excerpt in the tickets list
	 * when the excerpt mode is selected.
	 * 
	 * @param  string $content Ticket excerpt
	 * @return string          Excerpt if applicable or empty string otherwise
	 */
	public function remove_excerpt( $content ) {

		global $mode;

		if ( !is_admin() ||! isset( $_GET['post_type'] ) || WPAS_PT_SLUG !== $_GET['post_type'] ) {
			return $content;
		}

		global $mode;

		if ( 'excerpt' === $mode ) {
			return '';
		}

		return $content;
	}

	/**
	 * Update tickets list view.
	 * 
	 * @param  [type] $check      [description]
	 * @param  [type] $object_id  [description]
	 * @param  [type] $meta_key   [description]
	 * @param  [type] $meta_value [description]
	 * @param  [type] $prev       [description]
	 * @return [type]             [description]
	 */
	public function set_list_mode( $check, $object_id, $meta_key, $meta_value, $prev_value ) {

		if ( isset( $_GET['post_type'] ) && WPAS_PT_SLUG === $_GET['post_type'] ) {

			if ( 'wp_user-settings' === $meta_key ) {
				
				parse_str( $meta_value, $values );

				/* Check if the option being updated is the list view mode */
				if ( array_key_exists( 'posts_list_mode', $values ) && isset( $_REQUEST['mode'] ) ) {

					$val = 'excerpt' === $_REQUEST['mode'] ? 'details' : 'list';
					remove_filter( 'update_user_metadata', 'wpas_set_list_mode', 10 );
					set_user_setting( 'tickets_list_mode', $val );

					return false;

				}

			}

		}

		return $check;

		/**
		 * Set the ticket list mode.
		 */
		// global $mode;

		// if ( ! empty( $_REQUEST['mode'] ) ) {

		// 	$mode = $_REQUEST['mode'];

		// 	if ( isset( $_GET['post_type'] ) && WPAS_PT_SLUG === $_GET['post_type'] ) {

		// 		if ( 'excerpt' === $mode ) {
		// 			$mode = 'details';
		// 			set_user_setting ( 'tickets_list_mode', $mode );
		// 			delete_user_setting( 'posts_list_mode' );
		// 		}

		// 		if ( 'list' === $mode ) {
		// 			set_user_setting ( 'tickets_list_mode', $mode );
		// 		}

		// 	}

		// 	$mode = $_REQUEST['mode'] == 'excerpt' ? 'excerpt' : 'list';
		// 	set_user_setting ( 'posts_list_mode', $mode );
		// } else {
		// 	$mode = get_user_setting ( 'posts_list_mode', 'list' );
		// }

	}

}