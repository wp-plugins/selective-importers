<?php
/*
Plugin Name: Selective Importers
Description: Choose posts to import from WordPress, Blogger, or Tumblr.
Author: Stephanie Leary
Version: 1.0
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

// load stuff
include_once(dirname (__FILE__)."/wordpress-importer.php");
include_once(dirname (__FILE__)."/blogger-importer.php");
include_once(dirname (__FILE__)."/tumblr-importer.php");

/* Content Types */

add_action('init', 'post_type_imports');
register_activation_hook( __FILE__, 'activate_import_type' );

function activate_import_type() {
	post_type_imports();
}

function post_type_imports() {
	register_post_type(
		'import', 
			array(
			'labels' => array(
				'name' => __( 'Pending Imports' ),
				'singular_name' => __( 'Import' ),
				'add_new' => __('Add New'),
				'add_new_item' => __('Add New Import'),
				'edit_item' => __('Edit Import'),
				'new_item' => __('New Import'),
				'view_item' => __('View Pending Import'),
				'search_items' => __('Search Pending Imports'),
				'not_found' => __('No pending imports found'),
				'not_found_in_trash' => __('No imports found in Trash'),
				'menu_name' => __('Imports'),
			),
			'description' => __('Posts queued for import from another blog'),
			'public' => false, 
			'hierarchical' => false,
		) 
	);
}

function selective_import_admin_footer() {
	// Hack to add a custom bulk action. Necessary until http://core.trac.wordpress.org/ticket/16031 is resolved.
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function() {
            jQuery('<option>').val('import_wp_selective').text('Import').appendTo("select[name='action']");
            jQuery('<option>').val('import_wp_selective').text('Import').appendTo("select[name='action2']");
        });
/*
	// Activate radio buttons only if checkbox for the row has been checked.
		jQuery(document).ready(function() {
		  jQuery("table input:checkbox").change(function(){
		    if ( jQuery(this).is(":checked") ){
		      jQuery(this).parents("tr").find("input:radio").attr("disabled",null);
		    }
		    else{
		      jQuery(this).parents("tr").find("input:radio").attr("disabled",true);
		    }
		});
		});
/**/		
    </script>
    <?php
}
add_action('admin_footer', 'selective_import_admin_footer');

function process_selective_import() {
/*
	// DEBUG
	echo "<pre>" . print_r($_REQUEST, true) . "</pre>";
	exit;
/**/
	// valid post types
	$post_types = get_post_types(array('public'=>true));
	$post_types = apply_filters('selective_import_show_post_types', $post_types);
	
	extract($_REQUEST);
	if (current_user_can('import')) {
		if ((isset($action) && $action == 'import_wp_selective') || (isset($action2) && $action2 == 'import_wp_selective')) {
			check_admin_referer('bulk-posts');
			foreach ($post as $id => $postvals) {
				if (is_array($postvals) && in_array($postvals['import_post_type'], $post_types))
					wp_update_post(array('ID' => $id, 'post_type' => $postvals['import_post_type']));
			}
		}
	
		elseif (isset($action_single) && $action_single == 'import_wp_selective_single') {
			check_admin_referer('selective_import_nonce', '_selective_import_nonce');
			$single_import = array_flip($single_import);
			$id = $single_import['Import'];
			if (in_array($post[$id]['import_post_type'], $post_types))
				wp_update_post(array('ID' => $id, 'post_type' => $post[$id]['import_post_type']));
		}
	}
}
add_action('load-edit.php', 'process_selective_import');

// Status messages
add_filter('wp_insert_post_data', 'selective_import_status_msg', 99);
function selective_import_status_msg($post) {
	if (!is_wp_error($post))
		add_filter('redirect_post_location', 'selective_import_status_msg_ok', 99);
	else
		add_filter('redirect_post_location', 'selective_import_status_msg_error', 99);
	return $post;
}

function selective_import_status_msg_ok($location) {
	remove_filter('redirect_post_location', __FUNCTION__, 99);
	$location = add_query_arg('message', 99, $location);
	add_filter('post_updated_messages', 'selective_import_status_msg_ok_filter');
	return $location;
}
function selective_import_status_msg_error($location) {
	remove_filter('redirect_post_location', __FUNCTION__, 99);
	$location = add_query_arg('message', 99, $location);
	add_filter('post_updated_messages', 'selective_import_status_msg_error_filter');
	return $location;
}

function selective_import_status_msg_ok_filter($messages) {
	$messages['post'][99] = 'The selected posts have been imported.';
	return $messages;
}
function selective_import_status_msg_error_filter($messages) {
	$messages['post'][99] = 'There was a problem with some of the posts you tried to import.';
	return $messages;
}

/* Custom Edit Columns */

add_filter("manage_edit-import_columns", "selective_import_columns");

// rearrange the columns on the Edit screens
function selective_import_columns($defaults) {
	// preserve the default date column
	if (isset($defaults['date'])) $date = $defaults['date'];

	// remove default date 
	unset($defaults['date']);

	// insert new columns
	$defaults['author'] = __('Author');
	
	// restore date
	$defaults['date'] = $date;
	
	// insert new columns, one for each public post type
	$post_types = get_post_types(array('public'=>true), 'objects');
	$post_types = apply_filters('selective_import_show_post_type_columns', $post_types);
	foreach ($post_types as $type) {
		$defaults[$type->name] = $type->label;
	}
	// we probably don't want to allow attachments here
	unset($defaults['attachment']);
	$defaults['selective_import'] = __('Import');

	return $defaults;
}

// Add new column data for non-hierarchical content types:
add_action("manage_posts_custom_column", "selective_import_custom_column", 10, 2);

function selective_import_custom_column($column, $id) {
	global $post;
	$default_type = apply_filters('selective_import_default_post_type', 'post');

	$post_types = get_post_types(array('public'=>true));
	$post_types = apply_filters('selective_import_show_post_types', $post_types);
	if (in_array($column, $post_types)) {
		$checked = '';
		if ($column == $default_type) 
			$checked = ' checked="checked" ';
		echo '<input type="radio" name="post['.$post->ID.'][import_post_type]" value="'. $column .'" '.$checked.' />';	
	}
	
	elseif ($column == 'selective_import') {
		if (function_exists('wp_nonce_field')) wp_nonce_field( 'selective_import_nonce', '_selective_import_nonce');
		echo '<input type="hidden" name="action_single" value="import_wp_selective_single" />';
		echo '<input type="submit" class="button-secondary action" name="single_import['.$post->ID.']" value="'.__('Import').'" />';
	}
}

// add filter dropdowns
add_action('restrict_manage_posts', 'selective_import_restrict_content_authors');

// print a dropdown to filter posts by author
function selective_import_restrict_content_authors()
{
	if (isset($_GET['post_type']) && $_GET['post_type'] == 'import') {
		wp_dropdown_users(
			array(
				'who' => 'authors',
				'show_option_all' => __('Show all authors', 'selective-import'),
				'name' => 'author',
				'selected' => isset($_GET['author']) ? $_GET['author'] : 0
			)
		);
	}
}

/* CSS */
add_action("admin_head-edit.php", 'selective_import_column_css');

function selective_import_column_css() {
	$columns = array();
	$post_types = get_post_types(array('public'=>true));
	foreach ($post_types as $type) {
		$columns[] = '.column-'.$type;
	}
	$columns = implode(', ', $columns);
	echo '<style type="text/css">';
	echo $columns . '{ width: 8em; }';
	echo '</style>'; 
}


/* CRON */
// set up scheduled jobs
register_activation_hook(__FILE__, 'selective_import_cron_activate');
register_deactivation_hook(__FILE__, 'selective_import_cron_deactivate');

function selective_import_cron_activate() {
	// prevent this from happening immediately
	$start = strtotime('+1 day');
	if (!wp_next_scheduled('selective_import_check_queue'))
		wp_schedule_event($start, 'daily', 'selective_import_check_queue');
}

function selective_import_cron_deactivate() {
	wp_clear_scheduled_hook('selective_import_check_queue');
}

add_action('selective_import_check_queue', 'selective_import_clean_queue');

function selective_import_clean_queue() {
	global $wpdb;
	$expdate = strtotime("-1 week");
	$result = $wpdb->get_results('select post_id, meta_value from ' . $wpdb->postmeta . ' as postmeta, '.$wpdb->posts.' as posts where postmeta.post_id = posts.ID AND posts.post_status IN ("publish", "draft", "pending", "future") AND posts.post_type = "import" AND postmeta.meta_key = "_import_expiration" AND postmeta.meta_value <= "' . $expdate . '"');
  	if (!empty($result)) {
		foreach ($result as $apost) {
			wp_trash_post($apost->post_id);  // move to trash
		}
	}
}
?>