<?php
/**
 * Awesome Support.
 *
 * @package   Awesome Support/Custom Fields
 * @author    ThemeAvenue <web@themeavenue.net>
 * @license   GPL-2.0+
 * @link      http://themeavenue.net
 * @copyright 2014 ThemeAvenue
 */

class WPAS_Custom_Fields {

	/**
	 * List of metaboxes to remove.
	 */
	public $remove_mb;

	public function __construct() {

		/**
		 * Array where all custom fields will be stored.
		 */
		$this->options = array();

		/**
		 * Register the taxonomies
		 */
		add_action( 'init', array( $this, 'register_taxonomies' ) );

		/**
		 * Instantiate the class that handles saving the custom fields.
		 */
		$wpas_save = new WPAS_Save_Fields();

		if( is_admin() ) {

			/**
			 * Add custom columns
			 */
			add_action( 'manage_ticket_posts_columns',          array( $this, 'add_custom_column' ), 10, 1 );
			add_action( 'manage_ticket_posts_columns',          array( $this, 'move_status_first' ), 15, 1 );
			add_action( 'manage_ticket_posts_custom_column' ,   array( $this, 'custom_columns_content' ), 10, 2 );
			add_filter( 'manage_edit-ticket_sortable_columns' , array( $this, 'custom_columns_sortable' ), 10, 1 );
			add_action( 'pre_get_posts',                        array( $this, 'custom_column_orderby' ) );

			/**
			 * Add the taxonomies filters
			 */
			add_action( 'restrict_manage_posts', array( $this, 'custom_taxonomy_filter' ), 10, 0 );
			add_action( 'restrict_manage_posts', array( $this, 'status_filter' ), 9, 0 ); // Filter by ticket status
			add_filter( 'parse_query',           array( $this, 'custom_taxonomy_filter_convert_id_term' ), 10, 1 );
			add_filter( 'parse_query',           array( $this, 'status_filter_by_status' ), 10, 1 );

		} else {

			/* Now we can instantiate the save class and save */
			if ( isset( $_POST['wpas_title'] ) && isset( $_POST['wpas_message'] ) ) {

				/* Check for required fields and possibly block the submission. */
				add_filter( 'wpas_before_submit_new_ticket_checks', array( $wpas_save, 'check_required_fields' ) );

				/* Save the custom fields. */
				add_action( 'wpas_open_ticket_after', array( $wpas_save, 'save_submission' ), 10, 2 );

			}

			/* Display the custom fields on the submission form */
			add_action( 'wpas_submission_form_inside_after_subject', array( $this, 'submission_form_fields' ) );
		}

	}

	/**
	 * Add a new custom field to the ticket.
	 *
	 * @param string $name Option name
	 * @param array  $args Field arguments
	 *
	 * @return bool Whether or not the field was added
	 *
	 * @since 3.0.0
	 */
	public function add_field( $name = '', $args = array() ) {

		/* Option name is mandatory */
		if ( empty( $name ) ) {
			return false;
		}

		$name = sanitize_text_field( $name );

		/* Default arguments */
		$defaults = WPAS_Custom_Field::get_field_defaults();

		/* Merge args */
		$arguments = wp_parse_args( $args, $defaults );

		/* Convert the callback for backwards compatibility */
		if ( ! empty( $arguments['callback'] ) ) {

			switch ( $arguments['callback'] ) {

				case 'taxonomy';
					$arguments['field_type'] = 'taxonomy';
					$arguments['callback'] = '';
					break;

				case 'text':
					$arguments['field_type'] = 'text-field';
					$arguments['callback'] = '';
					break;

			}
		}

		/* Field with args */
		$option = array( 'name' => $name, 'args' => $arguments );

		$this->options[ $name ] = apply_filters( 'wpas_add_field', $option );

		return true;

	}

	/**
	 * Remove a custom field.
	 *
	 * @param  string $id ID of the field to remove
	 *
	 * @return void
	 *
	 * @since  3.0.0
	 */
	public function remove_field( $id ) {

		$fields = $this->options;

		if ( isset( $fields[ $id ] ) ) {
			unset( $fields[ $id ] );
		}

		$this->options = $fields;

	}

	/**
	 * Register all custom taxonomies.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function register_taxonomies() {

		$options         = $this->options;
		$this->remove_mb = array();

		foreach ( $options as $option ) {

			if ( 'taxonomy' == $option['args']['field_type'] ) {

				$name         = ! empty( $option['args']['label'] ) ? sanitize_text_field( $option['args']['label'] ) : ucwords( str_replace( array( '_', '-' ), ' ', $option['name'] ) );
				$plural       = ! empty( $option['args']['label_plural'] ) ? sanitize_text_field( $option['args']['label_plural'] ) : $name . 's';
				$column       = true === $option['args']['taxo_std'] ? true : false;
				$hierarchical = $option['args']['taxo_hierarchical'];

				$labels = array(
					'name'              => $plural,
					'singular_name'     => $name,
					'search_items'      => sprintf( __( 'Search %s', 'wpas' ), $plural ),
					'all_items'         => sprintf( __( 'All %s', 'wpas' ), $plural ),
					'parent_item'       => sprintf( __( 'Parent %s', 'wpas' ), $name ),
					'parent_item_colon' => sprintf( _x( 'Parent %s:', 'Parent term in a taxonomy where %s is dynamically replaced by the taxonomy (eg. "book")', 'wpas' ), $name ),
					'edit_item'         => sprintf( __( 'Edit %s', 'wpas' ), $name ),
					'update_item'       => sprintf( __( 'Update %s', 'wpas' ), $name ),
					'add_new_item'      => sprintf( __( 'Add New %s', 'wpas' ), $name ),
					'new_item_name'     => sprintf( _x( 'New %s Name', 'A new taxonomy term name where %s is dynamically replaced by the taxonomy (eg. "book")', 'wpas' ), $name ),
					'menu_name'         => $plural,
				);

				$args = array(
					'hierarchical'      => $hierarchical,
					'labels'            => $labels,
					'show_ui'           => true,
					'show_admin_column' => $column,
					'query_var'         => true,
					'rewrite'           => array( 'slug' => $option['name'] ),
					'capabilities'      => array(
						'manage_terms' => 'create_ticket',
						'edit_terms'   => 'settings_tickets',
						'delete_terms' => 'settings_tickets',
						'assign_terms' => 'create_ticket'
					)
				);

				if ( false !== $option['args']['update_count_callback'] && function_exists( $option['args']['update_count_callback'] ) ) {
					$args['update_count_callback'] = $option['args']['update_count_callback'];
				}

				register_taxonomy( $option['name'], array( 'ticket' ), $args );

				if ( false === $option['args']['taxo_std'] ) {
					array_push( $this->remove_mb, $option['name'] );
				}

			}

		}

		/* Remove metaboxes that won't be used */
		if ( ! empty( $this->remove_mb ) ) {
			add_action( 'admin_menu', array( $this, 'remove_taxonomy_metabox' ) );
		}

	}

	/**
	 * Remove taxonomies metaboxes.
	 *
	 * In some cases taxonomies are used as select.
	 * Hence, we don't need the standard taxonomy metabox.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function remove_taxonomy_metabox() {

		foreach ( $this->remove_mb as $key => $mb ) {
			remove_meta_box( $mb . 'div', 'ticket', 'side' );
		}

	}

	/**
	 * Return the list of fields
	 *
	 * @return array List of custom fields
	 * @since 3.0.0
	 */
	public function get_custom_fields() {
		return apply_filters( 'wpas_get_custom_fields', $this->options );
	}

	/**
	 * Check if custom fields are registered.
	 *
	 * If there are registered custom fields, the method returns true.
	 * Core fields are not considered registered custom fields by default
	 * but that can be overridden with the $core parameter.
	 *
	 * @since  3.0.0
	 *
	 * @param  boolean $core True if core fields should be counted as registered custom fields.
	 *
	 * @return boolean       True if custom fields are present, false otherwise
	 */
	public function have_custom_fields( $core = false ) {
		$fields = $this->get_custom_fields();
		$have   = false;

		foreach ( $fields as $key => $field ) {
			if ( false === boolval( $field['args']['core'] ) || true === $core && true === boolval( $field['args']['core'] ) ) {
				$have = true;
			}
		}

		return $have;
	}

	/**
	 * Add possible custom columns to tickets list.
	 *
	 * @param  array $columns List of default columns
	 *
	 * @return array          Updated list of columns
	 * @since  3.0.0
	 */
	public function add_custom_column( $columns ) {

		$new    = array();
		$custom = array();
		$fields = $this->get_custom_fields();

		/**
		 * Prepare all custom fields that are supposed to show up
		 * in the admin columns.
		 */
		foreach ( $fields as $field ) {

			/* If CF is a regular taxonomy we don't handle it, WordPress does */
			if ( 'taxonomy' == $field['args']['field_type'] && true === $field['args']['taxo_std'] ) {
				continue;
			}

			if ( true === $field['args']['show_column'] ) {
				$id            = $field['name'];
				$title         = wpas_get_field_title( $field );
				$custom[ $id ] = $title;
			}

		}

		/**
		 * Parse the old columns and add the new ones.
		 */
		foreach ( $columns as $col_id => $col_label ) {

			/* Merge all custom columns right before the date column */
			if ( 'date' == $col_id ) {
				$new = array_merge( $new, $custom );
			}

			$new[ $col_id ] = $col_label;

		}

		return $new;
	}

	/**
	 * Reorder the admin columns.
	 *
	 * @since  3.0.0
	 *
	 * @param  array $columns List of admin columns
	 *
	 * @return array          Re-ordered list
	 */
	public function move_status_first( $columns ) {

		if ( isset( $columns['status'] ) ) {
			$status_content = $columns['status'];
			unset( $columns['status'] );
		} else {
			return $columns;
		}

		$new = array();

		foreach ( $columns as $column => $content ) {

			if ( 'title' === $column ) {
				$new['status'] = $status_content;
			}

			$new[ $column ] = $content;

		}

		return $new;

	}

	/**
	 * Manage custom columns content
	 *
	 * @param  string  $column  The name of the column to display
	 * @param  integer $post_id ID of the post being processed
	 *
	 * @return void
	 *
	 * @since  3.0.0
	 */
	public function custom_columns_content( $column, $post_id ) {

		$fields = $this->get_custom_fields();

		if ( isset( $fields[ $column ] ) ) {

			if ( true === $fields[ $column ]['args']['show_column'] ) {

				/* In case a custom callback is specified we use it */
				if ( function_exists( $fields[ $column ]['args']['column_callback'] ) ) {
					call_user_func( $fields[ $column ]['args']['column_callback'], $fields[ $column ]['name'], $post_id );
				}

				/* Otherwise we use the default rendering options */
				else {
					wpas_cf_value( $fields[ $column ]['name'], $post_id );
				}

			}

		}

	}

	/**
	 * Make custom columns sortable
	 *
	 * @param  array $columns Already sortable columns
	 *
	 * @return array          New sortable columns
	 * @since  3.0.0
	 */
	public function custom_columns_sortable( $columns ) {

		$new    = array();
		$fields = $this->get_custom_fields();

		foreach ( $fields as $field ) {

			/* If CF is a regular taxonomy we don't handle it, WordPress does */
			if ( 'taxonomy' == $field['args']['field_type'] && true === $field['args']['taxo_std'] ) {
				continue;
			}

			if ( true === $field['args']['show_column'] && true === $field['args']['sortable_column'] ) {
				$id         = $field['name'];
				$new[ $id ] = $id;
			}

		}

		return array_merge( $columns, $new );

	}

	/**
	 * Reorder custom columns based on custom values.
	 *
	 * @param  object $query Main query
	 *
	 * @return void
	 *
	 * @since  3.0.0
	 */
	public function custom_column_orderby( $query ) {

		$fields  = $this->get_custom_fields();
		$orderby = $query->get( 'orderby' );

		if ( array_key_exists( $orderby, $fields ) ) {

			if ( 'taxonomy' != $fields[ $orderby ]['args']['field_type'] ) {
				$query->set( 'meta_key', '_wpas_' . $orderby );
				$query->set( 'orderby', 'meta_value' );
			}

		}

	}

	/**
	 * Add filters for custom taxonomies
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function custom_taxonomy_filter() {

		global $typenow;

		if ( 'ticket' != $typenow ) {
			echo '';
		}

		$post_types = get_post_types( array( '_builtin' => false ) );

		if ( in_array( $typenow, $post_types ) ) {

			$filters = get_object_taxonomies( $typenow );

			/* Get all custom fields */
			$fields = $this->get_custom_fields();

			foreach ( $filters as $tax_slug ) {

				if ( ! array_key_exists( $tax_slug, $fields ) ) {
					continue;
				}

				if ( true !== $fields[ $tax_slug ]['args']['filterable'] ) {
					continue;
				}

				$tax_obj = get_taxonomy( $tax_slug );

				$args = array(
					'show_option_all' => __( 'Show All ' . $tax_obj->label ),
					'taxonomy'        => $tax_slug,
					'name'            => $tax_obj->name,
					'orderby'         => 'name',
					'hierarchical'    => $tax_obj->hierarchical,
					'show_count'      => true,
					'hide_empty'      => true,
					'hide_if_empty'   => true,
				);

				if ( isset( $_GET[ $tax_slug ] ) ) {
					$args['selected'] = $_GET[ $tax_slug ];
				}

				wp_dropdown_categories( $args );

			}
		}

	}

	/**
	 * Add status dropdown in the filters bar.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function status_filter() {

		global $typenow;

		if ( 'ticket' != $typenow ) {
			echo '';
		}

		if ( isset( $_GET['post_status'] ) ) {
			echo '';
		}

		$this_sort       = isset( $_GET['wpas_status'] ) ? $_GET['wpas_status'] : '';
		$all_selected    = ( '' === $this_sort ) ? 'selected="selected"' : '';
		$open_selected   = ( 'open' === $this_sort ) ? 'selected="selected"' : '';
		$closed_selected = ( 'closed' === $this_sort ) ? 'selected="selected"' : '';
		$dropdown        = '<select id="wpas_status" name="wpas_status">';
		$dropdown        .= "<option value='' $all_selected>" . __( 'Any Status', 'wpas' ) . "</option>";
		$dropdown        .= "<option value='open' $open_selected>" . __( 'Open', 'wpas' ) . "</option>";
		$dropdown        .= "<option value='closed' $closed_selected>" . __( 'Closed', 'wpas' ) . "</option>";
		$dropdown        .= '</select>';

		echo $dropdown;

	}

	/**
	 * Convert taxonomy ID into term.
	 *
	 * When filtering, WordPress uses the ID by default in the query but
	 * that doesn't work. We need to convert it to the taxonomy term.
	 *
	 * @param  object $query WordPress current main query
	 *
	 * @return void
	 *
	 * @since  2.0.0
	 * @link   http://wordpress.stackexchange.com/questions/578/adding-a-taxonomy-filter-to-admin-list-for-a-custom-post-type
	 */
	public function custom_taxonomy_filter_convert_id_term( $query ) {

		global $pagenow;

		/* Check if we are in the correct post type */
		if ( is_admin() && 'edit.php' == $pagenow && isset( $_GET['post_type'] ) && 'ticket' === $_GET['post_type'] && $query->is_main_query() ) {

			/* Get all custom fields */
			$fields = $this->get_custom_fields();

			/* Filter custom fields that are taxonomies */
			foreach ( $query->query_vars as $arg => $value ) {

				if ( array_key_exists( $arg, $fields ) && 'taxonomy' === $fields[ $arg ]['args']['field_type'] && true === $fields[ $arg ]['args']['filterable'] ) {

					$term = get_term_by( 'id', $value, $arg );

					if ( false !== $term ) {
						$query->query_vars[ $arg ] = $term->slug;
					}

				}

			}

		}
	}

	/**
	 * Filter tickets by status.
	 *
	 * When filtering, WordPress uses the ID by default in the query but
	 * that doesn't work. We need to convert it to the taxonomy term.
	 *
	 * @since  3.0.0
	 *
	 * @param  object $query WordPress current main query
	 *
	 * @return void
	 */
	public function status_filter_by_status( $query ) {

		global $pagenow;

		/* Check if we are in the correct post type */
		if ( is_admin()
		     && 'edit.php' == $pagenow
		     && isset( $_GET['post_type'] )
		     && 'ticket' == $_GET['post_type']
		     && isset( $_GET['wpas_status'] )
		     && ! empty( $_GET['wpas_status'] )
		     && $query->is_main_query()
		) {

			$query->query_vars['meta_key']     = '_wpas_status';
			$query->query_vars['meta_value']   = sanitize_text_field( $_GET['wpas_status'] );
			$query->query_vars['meta_compare'] = '=';
		}

	}

	/**
	 * Display the custom fields on submission form.
	 *
	 * @since 3.2.0
	 * @return void
	 */
	public function submission_form_fields() {

		$fields = $this->get_custom_fields();

		if ( ! empty( $fields ) ) {

			foreach ( $fields as $name => $field ) {

				/* Do not display core fields */
				if ( true === $field['args']['core'] ) {
					continue;
				}

				$this_field = new WPAS_Custom_Field( $name, $field );
				$output     = $this_field->get_output();

				echo $output;

			}

		}

	}

	/**
	 * Save all custom fields given in $data to the database.
	 *
	 * @since 3.2.0
	 *
	 * @param int   $post_id ID of the post being saved
	 * @param array $data    Array of data that might contain custom fields values.
	 *
	 * @return array Array of custom field / value saved to the database
	 */
	public function save_custom_fields( $post_id, $data = array() ) {

		/* We store all the data to log in here */
		$log = array();

		/* Store all fields saved to DB and the value saved */
		$saved = array();

		$fields = $this->get_custom_fields();

		/**
		 * wpas_save_custom_fields_before hook
		 *
		 * @since  3.0.0
		 */
		do_action( 'wpas_save_custom_fields_before', $post_id );

		foreach ( $fields as $field_id => $field ) {

			/**
			 * All name attributes are prefixed with wpas_
			 * so we need to add it to get the real field ID.
			 */
			$field_form_id = "wpas_$field_id";

			/* Process core fields differently. */
			if ( true === $field['args']['core'] ) {

				if ( isset( $data[ $field_form_id ] ) ) {
					$this->save_core_field( $post_id, $field, $data[ $field_form_id ] );
				}

				continue;
			}

			/**
			 * Get the custom field object.
			 */
			$custom_field = new WPAS_Custom_Field( $field_id, $field );

			if ( isset( $data[ $field_form_id ] ) ) {

				$value  = $custom_field->get_sanitized_value( $data[ $field_form_id ] );
				$result = $custom_field->update_value( $value, $post_id );

			} else {
				/**
				 * This is actually important as passing an empty value
				 * for custom fields that aren't set in the form allows
				 * for deleting values that aren't used from the database.
				 * An unchecked checkbox for instance will not be set
				 * in the form even though the value has to be deleted.
				 */
				$value  = '';
				$result = $custom_field->update_value( $value, $post_id );
			}

			if ( 1 === $result || 2 === $result ) {
				$saved[ $field['name'] ] = $value;
			}

			if ( true === $field['args']['log'] ) {

				/**
				 * If the custom field is a taxonomy we need to convert the term ID into its name.
				 *
				 * By checking if $result is different from 0 we make sure that the term actually exists.
				 * If the term didn't exist the save function would have seen it and returned 0.
				 */
				if ( 'taxonomy' === $field['args']['field_type'] && 0 !== $result ) {
					$term  = get_term( (int) $value, $field['name'] );
					$value = $term->name;
				}

				$tmp = array(
					'action'   => '',
					'label'    => wpas_get_field_title( $field ),
					'value'    => $value,
					'field_id' => $field['name']
				);

				switch ( (int) $result ) {

					case 1:
						$tmp['action'] = 'added';
						break;

					case 2:
						$tmp['action'] = 'updated';
						break;

					case 3:
						$tmp['action'] = 'deleted';
						break;

				}

				/* Only add this to the log if something was done to the field value */
				if ( ! empty( $tmp['action'] ) ) {
					$log[] = $tmp;
				}

			}

		}

		/**
		 * Log the changes if any.
		 */
		if ( ! empty( $log ) ) {
			wpas_log( $post_id, $log );
		}

		/**
		 * wpas_save_custom_fields_before hook
		 *
		 * @since  3.0.0
		 */
		do_action( 'wpas_save_custom_fields_after', $post_id );

		return $saved;

	}

	/**
	 * Save the core fields.
	 *
	 * Core fields are processed differently and won't use the same
	 * saving function as the standard custom fields of the same type.
	 *
	 * @since 3.2.0
	 *
	 * @param int   $post_id ID of the post being saved
	 * @param array $field   Field array
	 * @param mixed $value   Field new value
	 *
	 * @return void
	 */
	public function save_core_field( $post_id, $field, $value ) {

		switch ( $field['name'] ) {

			case 'assignee':

				if ( $value !== get_post_meta( $post_id, '_wpas_assignee', true ) ) {
					wpas_assign_ticket( $post_id, $value, $field['args']['log'] );
				}

				break;

		}

	}

}


/**
 * Return a custom field value.
 *
 * @param  string  $name    Option name
 * @param  integer $post_id Post ID
 * @param  mixed   $default Default value
 *
 * @return mixed)            Meta value
 * @since  3.0.0
 */
function wpas_get_cf_value( $name, $post_id, $default = false ) {

	$field = new WPAS_Custom_Field( $name );

	return $field->get_field_value( $default, $post_id );
}

/**
 * Echo a custom field value.
 *
 * This function is just a wrapper function for wpas_get_cf_value()
 * that echoes the result instead of returning it.
 *
 * @param  string  $name    Option name
 * @param  integer $post_id Post ID
 * @param  mixed   $default Default value
 *
 * @return mixed)            Meta value
 * @since  3.0.0
 */
function wpas_cf_value( $name, $post_id, $default = false ) {
	echo wpas_get_cf_value( $name, $post_id, $default );
}

/**
 * Add a new custom field.
 *
 * @since  3.0.0
 *
 * @param  string $name The ID of the custom field to add
 * @param  array  $args Additional arguments for the custom field
 *
 * @return boolean        Returns true on success or false on failure
 */
function wpas_add_custom_field( $name, $args = array() ) {

	global $wpas_cf;

	if ( ! isset( $wpas_cf ) || ! class_exists( 'WPAS_Custom_Fields' ) ) {
		return false;
	}

	return $wpas_cf->add_field( $name, $args );

}

/**
 * Add a new custom taxonomy.
 *
 * @since  3.0.0
 *
 * @param  string $name The ID of the custom field to add
 * @param  array  $args Additional arguments for the custom field
 *
 * @return boolean        Returns true on success or false on failure
 */
function wpas_add_custom_taxonomy( $name, $args = array() ) {

	global $wpas_cf;

	if ( ! isset( $wpas_cf ) || ! class_exists( 'WPAS_Custom_Fields' ) ) {
		return false;
	}

	/* Force the custom fields type to be a taxonomy. */
	$args['field_type']      = 'taxonomy';
	$args['column_callback'] = 'wpas_show_taxonomy_column';

	/* Add the taxonomy. */
	$wpas_cf->add_field( $name, $args );

	return true;

}

add_action( 'init', 'wpas_register_core_fields' );
/**
 * Register the cure custom fields.
 *
 * @since  3.0.0
 * @return void
 */
function wpas_register_core_fields() {

	global $wpas_cf;

	if ( ! isset( $wpas_cf ) ) {
		return;
	}

	$wpas_cf->add_field( 'assignee',   array( 'core' => true, 'show_column' => false, 'log' => true, 'title' => __( 'Support Staff', 'wpas' ) ) );
	// $wpas_cf->add_field( 'ccs',        array( 'core' => true, 'show_column' => false, 'log' => true ) );
	$wpas_cf->add_field( 'status',     array( 'core' => true, 'show_column' => true, 'log' => false, 'field_type' => false, 'column_callback' => 'wpas_cf_display_status', 'save_callback' => null ) );
	$wpas_cf->add_field( 'ticket-tag', array(
		'core'                  => true,
		'show_column'           => true,
		'log'                   => true,
		'field_type'            => 'taxonomy',
		'taxo_std'              => true,
		'column_callback'       => 'wpas_cf_display_status',
		'save_callback'         => null,
		'label'                 => __( 'Tag', 'wpas' ),
		'name'                  => __( 'Tag', 'wpas' ),
		'label_plural'          => __( 'Tags', 'wpas' ),
		'taxo_hierarchical'     => false,
		'update_count_callback' => 'wpas_update_ticket_tag_terms_count'
		)
	);

	$options = maybe_unserialize( get_option( 'wpas_options', array() ) );

	if ( isset( $options['support_products'] ) && true === boolval( $options['support_products'] ) ) {

		$slug = defined( 'WPAS_PRODUCT_SLUG' ) ? WPAS_PRODUCT_SLUG : 'product';

		/* Filter the taxonomy labels */
		$labels = apply_filters( 'wpas_product_taxonomy_labels', array(
			'label'        => __( 'Product', 'wpas' ),
			'name'         => __( 'Product', 'wpas' ),
			'label_plural' => __( 'Products', 'wpas' )
			)
		);

		$wpas_cf->add_field( 'product', array(
				'core'                  => false,
				'show_column'           => true,
				'log'                   => true,
				'field_type'            => 'taxonomy',
				'taxo_std'              => false,
				'column_callback'       => 'wpas_show_taxonomy_column',
				'label'                 => $labels['label'],
				'name'                  => $labels['name'],
				'label_plural'          => $labels['label_plural'],
				'taxo_hierarchical'     => true,
				'update_count_callback' => 'wpas_update_ticket_tag_terms_count',
				'rewrite'               => array( 'slug' => $slug )
			)
		);

	}

}