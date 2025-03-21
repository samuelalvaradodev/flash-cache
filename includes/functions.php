<?php

function flash_cache_get_advanced_settings() {
	static $advanced_settings = null;
	if (empty($advanced_settings)) {
		$advanced_settings = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
	}
	return $advanced_settings;
}

function flash_cache_get_general_settings() {
	static $general_settings = null;
	if (empty($general_settings)) {
		$general_settings = wp_parse_args(get_option('flash_cache_settings', array()), flash_cache_settings::default_general_options());
	}
	return $general_settings;
}

function flash_cache_get_server_name() {
	if (!empty($_SERVER['SERVER_NAME'])) {
		$server_name = sanitize_text_field($_SERVER['SERVER_NAME']);
	} else {
		$server_name = parse_url(get_bloginfo('url'));
		$server_name = $server_name['host'];
		$server_name = sanitize_text_field($server_name);
	}

	return $server_name;
}

function flash_cache_sanitize_origin_url($origin_url) {
	$parsed_url	 = parse_url($origin_url);
	$scheme		 = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : 'https://';
	$host		 = isset($parsed_url['host']) ? $parsed_url['host'] : '';
	$origin_url	 = $scheme . $host . '/';
	return $origin_url;
}

function flash_cache_sanitize_settings_deep($default_values, $post_values) {
	if (!empty($post_values)) {
		foreach ($default_values as $key => $default_value) {
			if (!empty($post_values[$key])) {
				if (is_bool($default_value) === true) {
					$post_values[$key] = ($post_values[$key] ? true : false);
				} elseif (is_string($default_value)) {
					$post_values[$key] = sanitize_text_field($post_values[$key]);
				} else if (is_numeric($default_value)) {
					$post_values[$key] = absint(sanitize_text_field($post_values[$key]));
				} else if (is_array($default_value)) {
					foreach ($post_values[$key] as $key_array => $value_array) {
						$post_values[$key][$key_array] = sanitize_text_field($post_values[$key][$key_array]);
					}
				}
			}
		}
	}
	return $post_values;
}

function flash_cache_sanitize_querystring_cache($get_queries_vars) {
	global $wp_query;
	$new_queries_vars = array();
	if (!empty($get_queries_vars)) {
		if (!empty($wp_query->query_vars)) {
			foreach ($wp_query->query_vars as $key => $default_value) {
				if (!empty($get_queries_vars[$key])) {
					$new_queries_vars[$key] = sanitize_text_field($get_queries_vars[$key]);
				}
			}
		}
	}
	return $new_queries_vars;
}

function flash_cache_admin_bar_render() {
	global $wp_admin_bar;
	if (!is_user_logged_in() || is_admin()) {
		return false;
	}

	if (function_exists('current_user_can') && false == current_user_can('delete_others_posts')) {
		return false;
	}
	$root_url			 = get_site_url(null, '/');
	$path_cache			 = str_replace($root_url, '', flash_cache_get_current_url());
	$advanced_settings	 = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
	$cache_dir			 = flash_cache_get_home_path() . $advanced_settings['cache_dir'];
	$page_cache_dir		 = trailingslashit($cache_dir . flash_cache_get_server_name() . '/' . str_replace('..', '', preg_replace('/:.*$/', '', $path_cache)));
	$cache_path			 = realpath($page_cache_dir) . '/';

	if ($cache_path != '/') {
		$wp_admin_bar->add_menu(array(
			'parent' => '',
			'id'	 => 'delete-cache',
			'title'	 => __('Delete Cache', 'flash-cache'),
			'meta'	 => array('title' => __('Delete cache of the current page', 'flash-cache')),
			'href'	 => wp_nonce_url(admin_url('admin-post.php?action=flash_cache_delete&path=' . urlencode(preg_replace('/[ <>\'\"\r\n\t\(\)]/', '', $path_cache))), 'delete-cache')
		));
	}
}

add_action('wp_before_admin_bar_render', 'flash_cache_admin_bar_render');

function flash_cache_delete_action() {
	flash_cache_process::get_db_lock('/');
	// Delete cache for a specific page
	if (function_exists('current_user_can') && false == current_user_can('delete_others_posts')) {
		wp_die('You are not authorized to do this action');
	}
	$nonce_verify = isset($_GET['_wpnonce']) ? wp_verify_nonce($_REQUEST['_wpnonce'], 'delete-cache') : false;
	if ($nonce_verify && isset($_GET['path'])) {

		$advanced_settings	 = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
		$cache_dir			 = flash_cache_get_home_path() . $advanced_settings['cache_dir'];
		$page_cache_dir		 = trailingslashit($cache_dir . flash_cache_get_server_name() . '/' . str_replace('..', '', preg_replace('/:.*$/', '', $_GET['path'])));
		$cache_path			 = realpath($page_cache_dir) . '/';
		if ($cache_path != '/') {

			flash_cache_delete_cache_files($cache_dir, $cache_path);
		}
		wp_redirect(get_site_url(null, '/') . preg_replace('/[ <>\'\"\r\n\t\(\)]/', '', $_GET['path']));
		die();
	} else {
		wp_die('You are not authorized to do this action');
	}
}

add_action('admin_post_flash_cache_delete', 'flash_cache_delete_action');

function flash_cache_delete_cache_from_url($url) {
	$path = str_ireplace(home_url(''), '', $url);

	$advanced_settings	 = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
	$cache_dir			 = flash_cache_get_home_path() . $advanced_settings['cache_dir'];
	$page_cache_dir		 = trailingslashit($cache_dir . flash_cache_get_server_name() . '/' . str_replace('..', '', preg_replace('/:.*$/', '', $path)));
	$cache_path			 = realpath($page_cache_dir) . '/';

	if ($cache_path != '/') {
		flash_cache_delete_cache_files($cache_dir, $cache_path);
	}
}

function flash_cache_default_query() {
	$default_query = array(
		'is_search'		 => 0,
		'is_page'		 => 0,
		'is_archive'	 => 0,
		'is_tag'		 => 0,
		'is_single'		 => 0,
		'is_category'	 => 0,
		'is_front_page'	 => 0,
		'is_home'		 => 0,
		'is_author'		 => 0,
		'is_feed'		 => 0,
	);
	return $default_query;
}

function flash_cache_get_content($url, $args = array()) {
	/**
	 * Filter to allow change the default parameters for wp_remote_request below.
	 */
	$args = apply_filters('flash_cache_contents_request_params', $args, $url);

	/**
	 * Filter to allow change the $data or any other action before make the URL request
	 */
	$data = apply_filters('flash_cache_before_get_content', false, $args, $url);

	$defaults = array(
		'timeout'	 => 30,
		'cookies'	 => array(
			'flash_cache' => 'cookie'
		)
	);

	$args	 = wp_parse_args($args, $defaults);
	$return	 = array();

	if (!$data) { // if stil getting error on get file content try WP func, this may give timeouts 
		$response = wp_remote_request($url, $args);

		if (!is_wp_error($response)) {
			if (isset($response['response']['code']) && 200 === $response['response']['code']) {
				$data					 = wp_remote_retrieve_body($response);
				$headers				 = wp_remote_retrieve_headers($response);
				$return['response']		 = $data;
				$return['content_type']	 = (!empty($headers['content-type']) ? $headers['content-type'] : 'text/html; charset=UTF-8');
			} else {
				flash_cache_process::debug('Error with wp_remote_request:' . print_r($response, 1));
			}
		} else {
			flash_cache_process::debug('Error with wp_remote_request:' . $response->get_error_message());
		}
	}

	return $return;
}

function flash_cache_get_nginx_conf_info() {

	$general_settings		 = wp_parse_args(get_option('flash_cache_settings', array()), flash_cache_settings::default_general_options());
	$advanced_settings		 = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
	$cache_dir				 = $advanced_settings['cache_dir'];
	$wp_cache_disable_utf8	 = 0;

	if (isset($_SERVER['PHP_DOCUMENT_ROOT'])) {
		$document_root	 = sanitize_text_field($_SERVER['PHP_DOCUMENT_ROOT']);
		$apache_root	 = $document_root;
	} else {
		$document_root	 = sanitize_text_field($_SERVER['DOCUMENT_ROOT']);
		$apache_root	 = '%{DOCUMENT_ROOT}';
	}

	$server_name = flash_cache_get_server_name();

	$advanced_settings['dont_cache_cookie'][]	 = 'flash_cache';
	$content_dir_root							 = $document_root;

	$home_path		 = get_home_path();
	$home_root		 = parse_url(get_bloginfo('url'));
	$home_root		 = isset($home_root['path']) ? trailingslashit($home_root['path']) : '/';
	$home_root_lc	 = str_replace('//', '/', strtolower($home_root));
	$inst_root		 = $home_root_lc;

	$current_wp_rules			 = implode("\n", extract_from_markers($home_path . 'nginx.conf', 'WordPress'));
	$current_cache_rules		 = implode("\n", extract_from_markers($home_path . 'nginx.conf', 'FlashCache Page Cache'));
	$current_utils_rules		 = implode("\n", extract_from_markers($home_path . 'nginx.conf', 'FlashCache Utils'));
	$current_optimization_rules	 = implode("\n", extract_from_markers($home_path . 'nginx.conf', 'FlashCache Optimizations'));

	// Escapes all cookies set by users.
	$dont_cache_cookies = array();
	foreach ($advanced_settings['dont_cache_cookie'] as $cookie_name) {
		$dont_cache_cookies[] = esc_attr($cookie_name);
	}


	$cache_rules = array();
	if ($general_settings['activate']) {

		$cache_rules[]	 = 'set $cache_uri $request_uri;';
		$cache_rules[]	 = 'set $cache_uri_php $request_uri;';

		$cache_rules[]	 = 'if ($request_method = POST) {';
		$cache_rules[]	 = 'set $cache_uri \'null cache\';';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if ($query_string != "") {';
		$cache_rules[]	 = 'set $cache_uri \'null cache\';';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if ($request_uri ~ "([^\?]*)\?(.*)") {';
		$cache_rules[]	 = 'set $cache_uri_php $1;';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if ($http_cookie ~* "' . implode('|', $dont_cache_cookies) . '") {';
		$cache_rules[]	 = 'set $cache_uri \'null cache\';';
		$cache_rules[]	 = 'set $cache_uri_php \'null cache\';';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'set $fc_enc "";';
		$cache_rules[]	 = 'if ($http_accept_encoding ~ gzip) {';
		$cache_rules[]	 = 'set $fc_enc .gz;';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if (!-f "$document_root/' . esc_attr($cache_dir) . esc_attr($server_name) . '/${cache_uri}/index-cache.html") {';
		$cache_rules[]	 = 'set $cache_uri \'null cache\';';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if ($cache_uri != "null cache") {';
		$cache_rules[]	 = 'rewrite .* "/' . esc_attr($cache_dir) . esc_attr($server_name) . '/${cache_uri}/index-cache.html" last;';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if (!-f "$document_root/' . esc_attr($cache_dir) . esc_attr($server_name) . '/${cache_uri_php}/index-cache.php") {';
		$cache_rules[]	 = 'set $cache_uri_php \'null cache\';';
		$cache_rules[]	 = '}';

		$cache_rules[]	 = 'if ($cache_uri_php != "null cache") {';
		$cache_rules[]	 = 'rewrite .* "/' . esc_attr($cache_dir) . esc_attr($server_name) . '/${cache_uri_php}/index-cache.php" last;';
		$cache_rules[]	 = '}';
	}
	$cache_rules		 = apply_filters('flash_cache_nginx_cache_rules', $cache_rules);
	$final_cache_rules	 = apply_filters('flash_cache_nginx_final_cache_rules', implode("\n", $cache_rules));

	$utils_rules = array();
	if ($advanced_settings['viewer_protocol_policy'] == 'redirect_http_to_https') {
		# Force HTTPS connection. This rules is domain agnostic
		$utils_rules[]	 = 'if ($scheme != "https") {';
		$utils_rules[]	 = 'rewrite ^ https://$host$uri permanent;';
		$utils_rules[]	 = '}';
		$utils_rules[]	 = 'add_header Strict-Transport-Security "max-age=' . absint(esc_attr($advanced_settings['ttl_default'])) . '; includeSubDomains; preload env=HTTPS";';
	}
	$utils_rules		 = apply_filters('flash_cache_nginx_utils_rules', $utils_rules);
	$final_utils_rules	 = apply_filters('flash_cache_nginx_final_utils_rules', implode("\n", $utils_rules));

	$optimization_rules			 = array();
	$optimization_rules			 = apply_filters('flash_cache_nginx_optimization_rules', $optimization_rules);
	$final_optimization_rules	 = apply_filters('flash_cache_nginx_final_optimization_rules', implode("\n", $optimization_rules));

	return array("home_path" => $home_path, "current_wp_rules" => $current_wp_rules, "current_cache_rules" => $current_cache_rules, "current_utils_rules" => $current_utils_rules, "current_optimization_rules" => $current_optimization_rules, "cache_rules" => $final_cache_rules, "utils_rules" => $final_utils_rules, "optimization_rules" => $final_optimization_rules);
}

function flash_cache_get_htaccess_info() {
	$general_settings		 = wp_parse_args(get_option('flash_cache_settings', array()), flash_cache_settings::default_general_options());
	$advanced_settings		 = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
	$cache_dir				 = $advanced_settings['cache_dir'];
	$wp_cache_disable_utf8	 = 0;
	if (isset($_SERVER['PHP_DOCUMENT_ROOT'])) {
		$document_root	 = sanitize_text_field($_SERVER['PHP_DOCUMENT_ROOT']);
		$apache_root	 = sanitize_text_field($_SERVER['PHP_DOCUMENT_ROOT']);
	} else {
		$document_root	 = sanitize_text_field($_SERVER['DOCUMENT_ROOT']);
		$apache_root	 = '%{DOCUMENT_ROOT}';
	}
	$advanced_settings['dont_cache_cookie'][]	 = 'flash_cache';
	$content_dir_root							 = $document_root;
	if (strpos($document_root, '/kunden/homepages/') === 0) {
		$content_dir_root	 = substr($content_dir_root, 7);
		$apache_root		 = $document_root;
	}
	$home_path		 = get_home_path();
	$home_root		 = parse_url(get_bloginfo('url'));
	$home_root		 = isset($home_root['path']) ? trailingslashit($home_root['path']) : '/';
	$home_root_lc	 = str_replace('//', '/', strtolower($home_root));
	$inst_root		 = $home_root_lc;
	$wprules		 = implode("\n", extract_from_markers($home_path . '.htaccess', 'WordPress'));

	$current_wp_rules			 = str_replace("RewriteBase $home_root\n", '', $wprules);
	$current_cache_rules		 = implode("\n", extract_from_markers($home_path . '.htaccess', 'FlashCache Page Cache'));
	$current_utils_rules		 = implode("\n", extract_from_markers($home_path . '.htaccess', 'FlashCache Utils'));
	$current_optimization_rules	 = implode("\n", extract_from_markers($home_path . '.htaccess', 'FlashCache Optimizations'));

	$permalink_structure = get_option('permalink_structure');

	$cache_rules = array();
	if ($general_settings['activate']) {


		// Escapes all cookies set by users.
		$dont_cache_cookies = array();
		foreach ($advanced_settings['dont_cache_cookie'] as $cookie_name) {
			$dont_cache_cookies[] = esc_attr($cookie_name);
		}


		$cache_rules[]	 = '<IfModule mod_rewrite.c>';
		$cache_rules[]	 = 'RewriteEngine On';
		$cache_rules[]	 = 'RewriteBase ' . esc_attr($home_root) . '';

		if (isset($wp_cache_disable_utf8) == false || $wp_cache_disable_utf8 == 0) {
			$charset		 = get_option('blog_charset') == '' ? 'UTF-8' : get_option('blog_charset');
			$cache_rules[]	 = 'AddDefaultCharset ' . esc_attr($charset) . '';
		}


		/* Cache static pages with gzip */
		if (substr($permalink_structure, -1) == '/') {
			$cache_rules[]	 = 'RewriteCond %{REQUEST_URI} !^.*[^/]$';
			$cache_rules[]	 = 'RewriteCond %{REQUEST_URI} !^.*//.*$';
		}
		$cache_rules[]	 = 'RewriteCond %{REQUEST_METHOD} !POST';
		$cache_rules[]	 = 'RewriteCond %{QUERY_STRING} !.*=.*';
		$cache_rules[]	 = 'RewriteCond %{HTTP:Cookie} !^.*(' . implode('|', $dont_cache_cookies) . ').*$';
		$cache_rules[]	 = 'RewriteCond %{HTTP:X-Wap-Profile} !^[a-z0-9\"]+ [NC]';
		$cache_rules[]	 = 'RewriteCond %{HTTP:Profile} !^[a-z0-9\"]+ [NC]';
		$cache_rules[]	 = 'RewriteCond %{HTTP:Accept-Encoding} gzip';
		$cache_rules[]	 = 'RewriteCond ' . esc_attr($apache_root) . esc_attr($inst_root) . esc_attr($cache_dir) . '%{SERVER_NAME}/$1/index-cache.html.gz -f';
		$cache_rules[]	 = 'RewriteRule ^(.*) "' . esc_attr($inst_root) . esc_attr($cache_dir) . '%{SERVER_NAME}/$1/index-cache.html.gz" [L]';

		/* Cache static pages with plain html */
		if (substr($permalink_structure, -1) == '/') {
			$cache_rules[]	 = 'RewriteCond %{REQUEST_URI} !^.*[^/]$';
			$cache_rules[]	 = 'RewriteCond %{REQUEST_URI} !^.*//.*$';
		}
		$cache_rules[]	 = 'RewriteCond %{REQUEST_METHOD} !POST';
		$cache_rules[]	 = 'RewriteCond %{QUERY_STRING} !.*=.*';
		$cache_rules[]	 = 'RewriteCond %{HTTP:Cookie} !^.*(' . implode('|', $dont_cache_cookies) . ').*$';
		$cache_rules[]	 = 'RewriteCond %{HTTP:X-Wap-Profile} !^[a-z0-9\"]+ [NC]';
		$cache_rules[]	 = 'RewriteCond %{HTTP:Profile} !^[a-z0-9\"]+ [NC]';
		$cache_rules[]	 = 'RewriteCond ' . esc_attr($apache_root) . esc_attr($inst_root) . esc_attr($cache_dir) . '%{SERVER_NAME}/$1/index-cache.html -f';
		$cache_rules[]	 = 'RewriteRule ^(.*) "' . esc_attr($inst_root) . esc_attr($cache_dir) . '%{SERVER_NAME}/$1/index-cache.html" [L]';

		/* Cache dynamic pages with PHP */
		if (substr($permalink_structure, -1) == '/') {
			$cache_rules[]	 = 'RewriteCond %{REQUEST_URI} !^.*[^/]$';
			$cache_rules[]	 = 'RewriteCond %{REQUEST_URI} !^.*//.*$';
		}

		$cache_rules[]	 = 'RewriteCond %{HTTP:Cookie} !^.*(' . implode('|', $dont_cache_cookies) . ').*$';
		$cache_rules[]	 = 'RewriteCond %{HTTP:X-Wap-Profile} !^[a-z0-9\"]+ [NC]';
		$cache_rules[]	 = 'RewriteCond %{HTTP:Profile} !^[a-z0-9\"]+ [NC]';
		$cache_rules[]	 = 'RewriteCond ' . esc_attr($apache_root) . esc_attr($inst_root) . esc_attr($cache_dir) . '%{SERVER_NAME}/$1/index-cache.php -f';
		$cache_rules[]	 = 'RewriteRule ^(.*) "' . esc_attr($inst_root) . esc_attr($cache_dir) . '%{SERVER_NAME}/$1/index-cache.php" [L]';

		/** Optimizations for page cached */
		$cache_rules[]	 = '<IfModule mod_mime.c>';
		$cache_rules[]	 = '<FilesMatch "\.html\.gz$">';
		$cache_rules[]	 = 'ForceType text/html';
		$cache_rules[]	 = 'FileETag None';
		$cache_rules[]	 = '</FilesMatch>';
		$cache_rules[]	 = 'AddEncoding gzip .gz';
		$cache_rules[]	 = 'AddType text/html .gz';
		$cache_rules[]	 = '</IfModule>';

		$cache_rules[]	 = '<IfModule mod_deflate.c>';
		$cache_rules[]	 = 'SetEnvIfNoCase Request_URI \.gz$ no-gzip';
		$cache_rules[]	 = '</IfModule>';

		$cache_rules[]	 = '<FilesMatch "index-cache">';
		$cache_rules[]	 = '<IfModule mod_headers.c>';
		$cache_rules[]	 = 'Header set Vary "Accept-Encoding"';
		$cache_rules[]	 = 'Header set Cache-Control "max-age=' . absint($advanced_settings['ttl_default']) . ', public"';
		$cache_rules[]	 = 'Header set Cached-By "Flash Cache"';
		$cache_rules[]	 = '</IfModule>';
		$cache_rules[]	 = '</FilesMatch>';
	}
	$cache_rules		 = apply_filters('flash_cache_apache_cache_rules', $cache_rules);
	$final_cache_rules	 = apply_filters('flash_cache_apache_final_cache_rules', implode("\n", $cache_rules));

	$utils_rules = array();
	if ($advanced_settings['viewer_protocol_policy'] == 'redirect_http_to_https') {
		$utils_rules[]	 = '<IfModule mod_rewrite.c>';
		$utils_rules[]	 = 'RewriteCond %{HTTPS} off';
		$utils_rules[]	 = 'RewriteRule .* https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]';
		$utils_rules[]	 = '</IfModule>';
		$utils_rules[]	 = '<IfModule mod_expires.c>';
		$utils_rules[]	 = 'Header set Strict-Transport-Security "max-age=' . absint($advanced_settings['ttl_default']) . '; includeSubDomains; preload" env=HTTPS';
		$utils_rules[]	 = "</IfModule>";
	}
	$utils_rules		 = apply_filters('flash_cache_apache_utils_rules', $utils_rules);
	$final_utils_rules	 = apply_filters('flash_cache_apache_final_utils_rules', implode("\n", $utils_rules));

	$optimization_rules			 = array();
	$optimization_rules			 = apply_filters('flash_cache_apache_optimization_rules', $optimization_rules);
	$final_optimization_rules	 = apply_filters('flash_cache_apache_final_optimization_rules', implode("\n", $optimization_rules));

	return array("home_path" => $home_path, "current_wp_rules" => $current_wp_rules, "current_cache_rules" => $current_cache_rules, "current_utils_rules" => $current_utils_rules, "current_optimization_rules" => $current_optimization_rules, "cache_rules" => $final_cache_rules, "utils_rules" => $final_utils_rules, "optimization_rules" => $final_optimization_rules);
}

function flash_cache_seconds_2_time($seconds) {
	//if you dont need php5 support, just remove the is_int check and make the input argument type int.
	if (!\is_int($seconds)) {
		$seconds = intval($seconds);
	}
	$dtF = new \DateTime('@0');
	$dtT = new \DateTime("@$seconds");
	$ret = '';
	if ($seconds === 0) {
		// special case
		return '0 seconds';
	}
	$diff = $dtF->diff($dtT);
	foreach (array(
		'y'	 => 'year',
		'm'	 => 'month',
		'd'	 => 'day',
		'h'	 => 'hour',
		'i'	 => 'minute',
		's'	 => 'second'
	) as $time => $timename) {
		if ($diff->$time !== 0) {
			$ret .= $diff->$time . ' ' . $timename;
			if ($diff->$time !== 1 && $diff->$time !== -1) {
				$ret .= 's';
			}
			$ret .= ' ';
		}
	}
	return substr($ret, 0, - 1);
}

function flash_cache_get_logged_in_cookie() {
	$logged_in_cookie	 = 'wordpress_logged_in';
	if (defined('LOGGED_IN_COOKIE') && substr(constant('LOGGED_IN_COOKIE'), 0, 19) != 'wordpress_logged_in')
		$logged_in_cookie	 = constant('LOGGED_IN_COOKIE');
	return $logged_in_cookie;
}

function flash_cache_update_htaccess() {
	if (flash_cache_enviroment::is_nginx()) {
		extract(flash_cache_get_nginx_conf_info());
		flash_cache_remove_marker($home_path . 'nginx.conf', 'WordPress'); // remove original WP rules so Flash Cache rules go on top

		$cache_inserted			 = insert_with_markers($home_path . 'nginx.conf', 'FlashCache Page Cache', explode("\n", $cache_rules));
		$utils_inserted			 = insert_with_markers($home_path . 'nginx.conf', 'FlashCache Utils', explode("\n", $utils_rules));
		$optimization_inserted	 = insert_with_markers($home_path . 'nginx.conf', 'FlashCache Optimizations', explode("\n", $optimization_rules));
		$wp_inserted			 = insert_with_markers($home_path . 'nginx.conf', 'WordPress', explode("\n", $current_wp_rules));

		if ($cache_inserted && $wp_inserted && $utils_inserted && $optimization_inserted) {
			return flash_cache_remove_breakspaces($home_path . 'nginx.conf');
		} else {
			return false;
		}
	}
	// By default process with apache webserver.
	extract(flash_cache_get_htaccess_info());
	flash_cache_remove_marker($home_path . '.htaccess', 'WordPress'); // remove original WP rules so Flash Cache rules go on top

	$cache_inserted			 = insert_with_markers($home_path . '.htaccess', 'FlashCache Page Cache', explode("\n", $cache_rules));
	$utils_inserted			 = insert_with_markers($home_path . '.htaccess', 'FlashCache Utils', explode("\n", $utils_rules));
	$optimization_inserted	 = insert_with_markers($home_path . '.htaccess', 'FlashCache Optimizations', explode("\n", $optimization_rules));
	$wp_inserted			 = insert_with_markers($home_path . '.htaccess', 'WordPress', explode("\n", $current_wp_rules));

	if ($cache_inserted && $wp_inserted && $utils_inserted && $optimization_inserted) {
		return flash_cache_remove_breakspaces($home_path . '.htaccess');
	} else {
		return false;
	}
}

function flash_cache_remove_rules() {
	if (flash_cache_enviroment::is_nginx()) {
		extract(flash_cache_get_nginx_conf_info());
		flash_cache_remove_marker($home_path . 'nginx.conf', 'FlashCache Page Cache');
		flash_cache_remove_marker($home_path . 'nginx.conf', 'FlashCache Utils');
		flash_cache_remove_marker($home_path . 'nginx.conf', 'FlashCache Optimizations');
		return;
	}
	// By default process with apache webserver.
	extract(flash_cache_get_htaccess_info());
	flash_cache_remove_marker($home_path . '.htaccess', 'FlashCache Page Cache');
	flash_cache_remove_marker($home_path . '.htaccess', 'FlashCache Utils');
	flash_cache_remove_marker($home_path . '.htaccess', 'FlashCache Optimizations');
}

function flash_cache_remove_marker($filename, $marker) {
	if (!file_exists($filename) || flash_cache_is_writeable_ACLSafe($filename)) {
		if (!file_exists($filename)) {
			return '';
		} else {
			$markerdata = explode("\n", implode('', file($filename)));
		}

		$f		 = fopen($filename, 'w');
		$foundit = false;
		if ($markerdata) {
			$state = true;
			foreach ($markerdata as $n => $markerline) {
				if (strpos($markerline, '# BEGIN ' . $marker) !== false)
					$state = false;
				if ($state) {
					if ($n + 1 < count($markerdata))
						fwrite($f, "{$markerline}\n");
					else
						fwrite($f, "{$markerline}");
				}
				if (strpos($markerline, '# END ' . $marker) !== false) {
					$state = true;
				}
			}
		}
		return true;
	} else {
		return false;
	}
}

function flash_cache_is_writeable_ACLSafe($path) {
	if ($path[strlen($path) - 1] == '/') // recursively return a temporary file path
		return flash_cache_is_writeable_ACLSafe($path . uniqid(mt_rand()) . '.tmp');
	else if (is_dir($path))
		return flash_cache_is_writeable_ACLSafe($path . '/' . uniqid(mt_rand()) . '.tmp');
	// check tmp file for read/write capabilities
	$rm	 = file_exists($path);
	$f	 = @fopen($path, 'a');
	if ($f === false)
		return false;
	fclose($f);
	if (!$rm)
		unlink($path);
	return true;
}

function flash_cache_get_current_url() {
	global $wp;
	$current_url = home_url(add_query_arg(array(), $wp->request));
	$current_url = rtrim($current_url, '/') . '/';
	return $current_url;
}

function flash_cache_get_home_path() {
	$home	 = set_url_scheme(get_option('home'), 'http');
	$siteurl = set_url_scheme(get_option('siteurl'), 'http');

	$script_filename = sanitize_text_field($_SERVER['SCRIPT_FILENAME']);

	if (!empty($home) && 0 !== strcasecmp($home, $siteurl)) {
		$wp_path_rel_to_home = str_ireplace($home, '', $siteurl); /* $siteurl - $home */
		$pos				 = strripos(str_replace('\\', '/', $script_filename), trailingslashit($wp_path_rel_to_home));
		$home_path			 = substr($script_filename, 0, $pos);
		$home_path			 = trailingslashit($home_path);
	} else {
		$home_path = ABSPATH;
	}
	if ($home_path == '/') {
		$home_path = trailingslashit(dirname($script_filename));
	}
	return str_replace('\\', '/', $home_path);
}

function flash_cache_get_content_to_php($url) {
	if (!empty($_GET)) {
		$url = $url . '?' . http_build_query(flash_cache_sanitize_querystring_cache($_GET));
	}
	$args = array(
		'timeout'	 => 15,
		'cookies'	 => array(
			'flash_cache'			 => 'cookie',
			'flash_cache_backend'	 => 'cookie',
		)
	);

	$args = apply_filters('flash_cache_get_content_to_php_args', $args);

	return flash_cache_get_content($url, $args);
}

function flash_cache_get_var_javascript($var, $string) {
	$pos_var		 = strpos($string, $var . ' = ');
	$pos_offset		 = strpos($string, ';', $pos_var);
	$offset_substr	 = ($pos_offset - ($pos_var + strlen($var . ' = ')));
	$current_var	 = substr($string, $pos_var + strlen($var . ' = '), $offset_substr);
	$current_var	 = str_replace('"', '', $current_var);
	$current_var	 = str_replace("'", '', $current_var);
	return $current_var;
}

function flash_cache_delete_dir($path, $delete_option = false) {
	global $wpdb;

	$advanced_settings	 = wp_parse_args(get_option('flash_cache_advanced_settings', array()));

	if(isset($advanced_settings['lock_type']) && $advanced_settings['lock_type'] == 'db'){
		$results = $wpdb->query(
			'TRUNCATE TABLE '. flash_cache_settings::$flash_cache_table
		);
	}
	

	if ($delete_option) {
		delete_option('flash_cache_disk_usage');
	}
	return is_file($path) ?
			@unlink($path) :
			array_map(__FUNCTION__, glob($path . '/*')) == @rmdir($path);
}

function flash_cache_delete_cache_files($cache_dir, $cache_path) {
	$html_file = $cache_path . 'index-cache.html';

	if (file_exists($html_file)) {
		$size_file = mb_strlen(file_get_contents($html_file), '8bit');
		flash_cache_decrement_disk_usage($size_file);
		@unlink($html_file);
	}
	$gzip_file = $cache_path . 'index-cache.html.gz';
	if (file_exists($gzip_file)) {
		$size_file = mb_strlen(file_get_contents($gzip_file), '8bit');
		flash_cache_decrement_disk_usage($size_file);
		@unlink($gzip_file);
	}
	$php_file = $cache_path . 'index-cache.php';
	if (file_exists($php_file)) {
		$size_file = mb_strlen(file_get_contents($php_file), '8bit');
		flash_cache_decrement_disk_usage($size_file);
		@unlink($php_file);
	}
	$requests_folder = $cache_path . 'requests/';
	if (file_exists($requests_folder)) {
		flash_cache_delete_dir($requests_folder);
	}
	if (trailingslashit(realpath($cache_dir . flash_cache_get_server_name())) != $cache_path) {
		flash_cache_delete_dir($cache_path);
	}
}

function flash_cache_get_path($url) {

	$url = apply_filters('flash_cache_get_path_url', $url);

	if (false !== strpos($url, '%')) {
		$url = urldecode($url);
	}

	return $url;
}

function flash_cache_increment_disk_usage($bytes) {
	$disk_usage = get_option('flash_cache_disk_usage', 0);
	update_option('flash_cache_disk_usage', (int) $disk_usage + $bytes);
}

function flash_cache_decrement_disk_usage($bytes) {
	$disk_usage = get_option('flash_cache_disk_usage', 0);
	update_option('flash_cache_disk_usage', (int) $disk_usage - $bytes);
}

function flash_cache_delete_all_options() {
	global $wpdb;
	$results = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s", 'flash_cache_%'
		),
	);

	// SQL query to drop the table
	$sql = "DROP TABLE IF EXISTS ".  $wpdb->prefix . "flash_lock";

	// Execute the query
	$result_flash_lock = $wpdb->query($sql);
}

/**
 * Function for erase the breakspaces in the file specificated.
 *
 * @author @Gerarjos14 https://github.com/gerarjos14
 * @param string   $filename       The filename to search and modify.
 * @return bool
 */
function flash_cache_remove_breakspaces($filename): bool {
	$file_array		 = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$first_code_line = array_search(true, array_map('trim', $file_array));
	$file_array		 = array_slice($file_array, $first_code_line);
	$file_string	 = implode("\n", $file_array);
	$file_string	 = preg_replace('/^# END.*/m', '$0' . PHP_EOL, $file_string);
	$file_string	 = rtrim($file_string);

	return (bool) file_put_contents($filename, $file_string);
}

function flash_cache_settings_icons() {
	$flash_cache_icons = [
		// Menu
		'icon_menu'			 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M436 124H12c-6.627 0-12-5.373-12-12V80c0-6.627 5.373-12 12-12h424c6.627 0 12 5.373 12 12v32c0 6.627-5.373 12-12 12zm0 160H12c-6.627 0-12-5.373-12-12v-32c0-6.627 5.373-12 12-12h424c6.627 0 12 5.373 12 12v32c0 6.627-5.373 12-12 12zm0 160H12c-6.627 0-12-5.373-12-12v-32c0-6.627 5.373-12 12-12h424c6.627 0 12 5.373 12 12v32c0 6.627-5.373 12-12 12z"/></svg>',
		'icon_settings'		 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M452.515 237l31.843-18.382c9.426-5.441 13.996-16.542 11.177-27.054-11.404-42.531-33.842-80.547-64.058-110.797-7.68-7.688-19.575-9.246-28.985-3.811l-31.785 18.358a196.276 196.276 0 0 0-32.899-19.02V39.541a24.016 24.016 0 0 0-17.842-23.206c-41.761-11.107-86.117-11.121-127.93-.001-10.519 2.798-17.844 12.321-17.844 23.206v36.753a196.276 196.276 0 0 0-32.899 19.02l-31.785-18.358c-9.41-5.435-21.305-3.877-28.985 3.811-30.216 30.25-52.654 68.265-64.058 110.797-2.819 10.512 1.751 21.613 11.177 27.054L59.485 237a197.715 197.715 0 0 0 0 37.999l-31.843 18.382c-9.426 5.441-13.996 16.542-11.177 27.054 11.404 42.531 33.842 80.547 64.058 110.797 7.68 7.688 19.575 9.246 28.985 3.811l31.785-18.358a196.202 196.202 0 0 0 32.899 19.019v36.753a24.016 24.016 0 0 0 17.842 23.206c41.761 11.107 86.117 11.122 127.93.001 10.519-2.798 17.844-12.321 17.844-23.206v-36.753a196.34 196.34 0 0 0 32.899-19.019l31.785 18.358c9.41 5.435 21.305 3.877 28.985-3.811 30.216-30.25 52.654-68.266 64.058-110.797 2.819-10.512-1.751-21.613-11.177-27.054L452.515 275c1.22-12.65 1.22-25.35 0-38zm-52.679 63.019l43.819 25.289a200.138 200.138 0 0 1-33.849 58.528l-43.829-25.309c-31.984 27.397-36.659 30.077-76.168 44.029v50.599a200.917 200.917 0 0 1-67.618 0v-50.599c-39.504-13.95-44.196-16.642-76.168-44.029l-43.829 25.309a200.15 200.15 0 0 1-33.849-58.528l43.819-25.289c-7.63-41.299-7.634-46.719 0-88.038l-43.819-25.289c7.85-21.229 19.31-41.049 33.849-58.529l43.829 25.309c31.984-27.397 36.66-30.078 76.168-44.029V58.845a200.917 200.917 0 0 1 67.618 0v50.599c39.504 13.95 44.196 16.642 76.168 44.029l43.829-25.309a200.143 200.143 0 0 1 33.849 58.529l-43.819 25.289c7.631 41.3 7.634 46.718 0 88.037zM256 160c-52.935 0-96 43.065-96 96s43.065 96 96 96 96-43.065 96-96-43.065-96-96-96zm0 144c-26.468 0-48-21.532-48-48 0-26.467 21.532-48 48-48s48 21.533 48 48c0 26.468-21.532 48-48 48z"/></svg>',
		'icon_advanced'		 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M496 72H288V48c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v24H16C7.2 72 0 79.2 0 88v16c0 8.8 7.2 16 16 16h208v24c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16v-24h208c8.8 0 16-7.2 16-16V88c0-8.8-7.2-16-16-16zm0 320H160v-24c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v24H16c-8.8 0-16 7.2-16 16v16c0 8.8 7.2 16 16 16h80v24c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16v-24h336c8.8 0 16-7.2 16-16v-16c0-8.8-7.2-16-16-16zm0-160h-80v-24c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v24H16c-8.8 0-16 7.2-16 16v16c0 8.8 7.2 16 16 16h336v24c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16v-24h80c8.8 0 16-7.2 16-16v-16c0-8.8-7.2-16-16-16z"/></svg>',
		'icon_patterns'		 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path d="M568 368c-19.1 0-36.3 7.6-49.2 19.7L440.6 343c4.5-12.2 7.4-25.2 7.4-39 0-61.9-50.1-112-112-112-8.4 0-16.6 1.1-24.4 2.9l-32.2-69c15-13.2 24.6-32.3 24.6-53.8 0-39.8-32.2-72-72-72s-72 32.2-72 72 32.2 72 72 72c.9 0 1.8-.2 2.7-.3l33.5 71.7C241.5 235.9 224 267.8 224 304c0 61.9 50.1 112 112 112 30.7 0 58.6-12.4 78.8-32.5l82.2 47c-.4 3.1-1 6.3-1 9.5 0 39.8 32.2 72 72 72s72-32.2 72-72-32.2-72-72-72zM232 96c-13.2 0-24-10.8-24-24s10.8-24 24-24 24 10.8 24 24-10.8 24-24 24zm104 272c-35.3 0-64-28.7-64-64s28.7-64 64-64 64 28.7 64 64-28.7 64-64 64zm232 96c-13.2 0-24-10.8-24-24s10.8-24 24-24 24 10.8 24 24-10.8 24-24 24zm-54.4-261.2l-19.2-25.6-48 36 19.2 25.6 48-36zM576 192c35.3 0 64-28.7 64-64s-28.7-64-64-64-64 28.7-64 64 28.7 64 64 64zM152 320h48v-32h-48v32zm-88-80c-35.3 0-64 28.7-64 64s28.7 64 64 64 64-28.7 64-64-28.7-64-64-64z"/></svg>',
		'icon_addpattern'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M464 32H48C21.49 32 0 53.49 0 80v352c0 26.51 21.49 48 48 48h416c26.51 0 48-21.49 48-48V80c0-26.51-21.49-48-48-48zm-6 400H54a6 6 0 0 1-6-6V86a6 6 0 0 1 6-6h404a6 6 0 0 1 6 6v340a6 6 0 0 1-6 6zm-42-92v24c0 6.627-5.373 12-12 12H204c-6.627 0-12-5.373-12-12v-24c0-6.627 5.373-12 12-12h200c6.627 0 12 5.373 12 12zm0-96v24c0 6.627-5.373 12-12 12H204c-6.627 0-12-5.373-12-12v-24c0-6.627 5.373-12 12-12h200c6.627 0 12 5.373 12 12zm0-96v24c0 6.627-5.373 12-12 12H204c-6.627 0-12-5.373-12-12v-24c0-6.627 5.373-12 12-12h200c6.627 0 12 5.373 12 12zm-252 12c0 19.882-16.118 36-36 36s-36-16.118-36-36 16.118-36 36-36 36 16.118 36 36zm0 96c0 19.882-16.118 36-36 36s-36-16.118-36-36 16.118-36 36-36 36 16.118 36 36zm0 96c0 19.882-16.118 36-36 36s-36-16.118-36-36 16.118-36 36-36 36 16.118 36 36z"/></svg>',
		'icon_preload'		 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path d="M377.8 167.9c-8.2-14.3-23.1-22.9-39.6-22.9h-94.4l28.7-87.5c3.7-13.8.8-28.3-7.9-39.7C255.8 6.5 242.5 0 228.2 0H97.7C74.9 0 55.4 17.1 52.9 37.1L.5 249.3c-1.9 13.8 2.2 27.7 11.3 38.2C20.9 298 34.1 304 48 304h98.1l-34.9 151.7c-3.2 13.7-.1 27.9 8.6 38.9 8.7 11.1 21.8 17.4 35.9 17.4 16.3 0 31.5-8.8 38.8-21.6l183.2-276.7c8.4-14.3 8.4-31.5.1-45.8zM160.1 457.4L206.4 256H47.5L97.7 48l127.6-.9L177.5 193H334L160.1 457.4z"/></svg>',
		// Socials
		'social_twitter'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 1227"><path d="M714.163 519.284L1160.89 0H1055.03L667.137 450.887L357.328 0H0L468.492 681.821L0 1226.37H105.866L515.491 750.218L842.672 1226.37H1200L714.137 519.284H714.163ZM569.165 687.828L521.697 619.934L144.011 79.6944H306.615L611.412 515.685L658.88 583.579L1055.08 1150.3H892.476L569.165 687.854V687.828Z" fill="white"/></svg>',
		'social_facebook'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M448 56.7v398.5c0 13.7-11.1 24.7-24.7 24.7H309.1V306.5h58.2l8.7-67.6h-67v-43.2c0-19.6 5.4-32.9 33.5-32.9h35.8v-60.5c-6.2-.8-27.4-2.7-52.2-2.7-51.6 0-87 31.5-87 89.4v49.9h-58.4v67.6h58.4V480H24.7C11.1 480 0 468.9 0 455.3V56.7C0 43.1 11.1 32 24.7 32h398.5c13.7 0 24.8 11.1 24.8 24.7z"/></svg>',
		'social_github'		 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 496 512"><path d="M165.9 397.4c0 2-2.3 3.6-5.2 3.6-3.3.3-5.6-1.3-5.6-3.6 0-2 2.3-3.6 5.2-3.6 3-.3 5.6 1.3 5.6 3.6zm-31.1-4.5c-.7 2 1.3 4.3 4.3 4.9 2.6 1 5.6 0 6.2-2s-1.3-4.3-4.3-5.2c-2.6-.7-5.5.3-6.2 2.3zm44.2-1.7c-2.9.7-4.9 2.6-4.6 4.9.3 2 2.9 3.3 5.9 2.6 2.9-.7 4.9-2.6 4.6-4.6-.3-1.9-3-3.2-5.9-2.9zM244.8 8C106.1 8 0 113.3 0 252c0 110.9 69.8 205.8 169.5 239.2 12.8 2.3 17.3-5.6 17.3-12.1 0-6.2-.3-40.4-.3-61.4 0 0-70 15-84.7-29.8 0 0-11.4-29.1-27.8-36.6 0 0-22.9-15.7 1.6-15.4 0 0 24.9 2 38.6 25.8 21.9 38.6 58.6 27.5 72.9 20.9 2.3-16 8.8-27.1 16-33.7-55.9-6.2-112.3-14.3-112.3-110.5 0-27.5 7.6-41.3 23.6-58.9-2.6-6.5-11.1-33.3 2.6-67.9 20.9-6.5 69 27 69 27 20-5.6 41.5-8.5 62.8-8.5s42.8 2.9 62.8 8.5c0 0 48.1-33.6 69-27 13.7 34.7 5.2 61.4 2.6 67.9 16 17.7 25.8 31.5 25.8 58.9 0 96.5-58.9 104.2-114.8 110.5 9.2 7.9 17 22.9 17 46.4 0 33.7-.3 75.4-.3 83.6 0 6.5 4.6 14.4 17.3 12.1C428.2 457.8 496 362.9 496 252 496 113.3 383.5 8 244.8 8zM97.2 352.9c-1.3 1-1 3.3.7 5.2 1.6 1.6 3.9 2.3 5.2 1 1.3-1 1-3.3-.7-5.2-1.6-1.6-3.9-2.3-5.2-1zm-10.8-8.1c-.7 1.3.3 2.9 2.3 3.9 1.6 1 3.6.7 4.3-.7.7-1.3-.3-2.9-2.3-3.9-2-.6-3.6-.3-4.3.7zm32.4 35.6c-1.6 1.3-1 4.3 1.3 6.2 2.3 2.3 5.2 2.6 6.5 1 1.3-1.3.7-4.3-1.3-6.2-2.2-2.3-5.2-2.6-6.5-1zm-11.4-14.7c-1.6 1-1.6 3.6 0 5.9 1.6 2.3 4.3 3.3 5.6 2.3 1.6-1.3 1.6-3.9 0-6.2-1.4-2.3-4-3.3-5.6-2z"/></svg>',
		'social_instagram'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>',
		'social_linkedin'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448.1 512"><path d="M100.3 448H7.4V148.9h92.9V448zM53.8 108.1C24.1 108.1 0 83.5 0 53.8S24.1 0 53.8 0s53.8 24.1 53.8 53.8-24.1 54.3-53.8 54.3zM448 448h-92.7V302.4c0-34.7-.7-79.2-48.3-79.2-48.3 0-55.7 37.7-55.7 76.7V448h-92.8V148.9h89.1v40.8h1.3c12.4-23.5 42.7-48.3 87.9-48.3 94 0 111.3 61.9 111.3 142.3V448h-.1z"/></svg>',
		'social_wordpress'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M61.7 169.4l101.5 278C92.2 413 43.3 340.2 43.3 256c0-30.9 6.6-60.1 18.4-86.6zm337.9 75.9c0-26.3-9.4-44.5-17.5-58.7-10.8-17.5-20.9-32.4-20.9-49.9 0-19.6 14.8-37.8 35.7-37.8.9 0 1.8.1 2.8.2-37.9-34.7-88.3-55.9-143.7-55.9-74.3 0-139.7 38.1-177.8 95.9 5 .2 9.7.3 13.7.3 22.2 0 56.7-2.7 56.7-2.7 11.5-.7 12.8 16.2 1.4 17.5 0 0-11.5 1.3-24.3 2l77.5 230.4L249.8 247l-33.1-90.8c-11.5-.7-22.3-2-22.3-2-11.5-.7-10.1-18.2 1.3-17.5 0 0 35.1 2.7 56 2.7 22.2 0 56.7-2.7 56.7-2.7 11.5-.7 12.8 16.2 1.4 17.5 0 0-11.5 1.3-24.3 2l76.9 228.7 21.2-70.9c9-29.4 16-50.5 16-68.7zm-139.9 29.3l-63.8 185.5c19.1 5.6 39.2 8.7 60.1 8.7 24.8 0 48.5-4.3 70.6-12.1-.6-.9-1.1-1.9-1.5-2.9l-65.4-179.2zm183-120.7c.9 6.8 1.4 14 1.4 21.9 0 21.6-4 45.8-16.2 76.2l-65 187.9C426.2 403 468.7 334.5 468.7 256c0-37-9.4-71.8-26-102.1zM504 256c0 136.8-111.3 248-248 248C119.2 504 8 392.7 8 256 8 119.2 119.2 8 256 8c136.7 0 248 111.2 248 248zm-11.4 0c0-130.5-106.2-236.6-236.6-236.6C125.5 19.4 19.4 125.5 19.4 256S125.6 492.6 256 492.6c130.5 0 236.6-106.1 236.6-236.6z"/></svg>',
		'social_youtube'	 => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"/></svg>',
	];
	return apply_filters('flash_cache_settings_icons', $flash_cache_icons);
}

function flash_cache_get_menus() {
	global $current_screen;
	$flash_cache_icons = flash_cache_settings_icons();

	return apply_filters('flash_cache_get_menus', '
	<div class="wpm_menu">
		<a class="wpm_menu_close' . ( ( $current_screen->id != 'flash-cache_page_flash_cache_advanced_setting' ) ? " border-right" : "" ) . '" title="' . __('Hide/Show menu.', 'flash-cache') . '" href="#"><span class="wpm_link_icon">' . $flash_cache_icons['icon_menu'] . '</span> <span class="wpm_link_text">' . __('MENU', 'flash-cache') . '</span></a>
		<a class="wpm_menu_link' . ( ( $current_screen->id == 'toplevel_page_flash_cache_setting' ) ? " active" : "" ) . '" href="admin.php?page=flash_cache_setting"><span class="wpm_link_icon">' . $flash_cache_icons['icon_settings'] . '</span> <span class="wpm_link_text">' . __('General Settings', 'flash-cache') . '</span></a>
		<a class="wpm_menu_link' . ( ( $current_screen->id == 'flash-cache_page_flash_cache_advanced_setting' ) ? " active" : "" ) . '" href="admin.php?page=flash_cache_advanced_setting"><span class="wpm_link_icon">' . $flash_cache_icons['icon_advanced'] . '</span> <span class="wpm_link_text">' .__('Advanced options', 'flash-cache') . '</span></a>
		<a class="wpm_menu_link' . ( ( $current_screen->id == 'edit-flash_cache_patterns' ) ? " active" : "" ) . '" href="edit.php?post_type=flash_cache_patterns"><span class="wpm_link_icon">' . $flash_cache_icons['icon_patterns'] . '</span> <span class="wpm_link_text">' . __('Patterns', 'flash-cache') . '</span></a>
		<a class="wpm_menu_link' . ( ( $current_screen->id == 'flash_cache_patterns' ) ? " active" : "" ) . '" href="post-new.php?post_type=flash_cache_patterns"><span class="wpm_link_icon">' . $flash_cache_icons['icon_addpattern'] . '</span> <span class="wpm_link_text">' . __('Add New Pattern', 'flash-cache') . '</span></a>
		<a class="wpm_menu_link' . ( ( $current_screen->id == 'flash-cache_page_flash_cache_preload' ) ? " active" : "" ) . '" href="admin.php?page=flash_cache_preload"><span class="wpm_link_icon">' . $flash_cache_icons['icon_preload'] . '</span> <span class="wpm_link_text">' . __('Preload', 'flash-cache') . '</span></a>
		' . apply_filters('flash_cache_add_menus', '', $flash_cache_icons) . '
	</div>'
	);
}

function flash_cache_get_menus_social_footer() {
	$flash_cache_icons = flash_cache_settings_icons();

	return apply_filters('flash_cache_get_menus_social_footer', '
		<div class="wpm_share">
			<a href="https://twitter.com/etruel_com" title="Visit us on twitter" target="_blank">
				' . $flash_cache_icons['social_twitter'] . '
			</a>
			<a href="https://www.facebook.com/etruel.store" title="Visit us on Facebook" target="_blank">
				' . $flash_cache_icons['social_facebook'] . '
			</a>
			<a href="https://github.com/etruel/" title="Visit us on Github" target="_blank">
				' . $flash_cache_icons['social_github'] . '
			</a>
			<a href="https://www.instagram.com/etruel_com/" title="Visit us on Instagram" target="_blank">
				' . $flash_cache_icons['social_instagram'] . '
			</a>
			<a href="https://www.linkedin.com/in/etruel/" title="Visit us on LinkedIn" target="_blank">
				' . $flash_cache_icons['social_linkedin'] . '
			</a>
			<a href="https://profiles.wordpress.org/etruel#content-plugins" title="Visit us on WordPress" target="_blank">
				' . $flash_cache_icons['social_wordpress'] . '
			</a>
			<a href="https://youtube.com/playlist?list=PLA9cHUykgn2pmv331oNGvHTFx_AIwCzww" title="Flash Cache video Tutorials" target="_blank">
				' . $flash_cache_icons['social_youtube'] . '
			</a>
		</div>'
	);
}

function flash_cache_changes_permalinks($old_permalink_structure, $permalink_structure) {
	if ($old_permalink_structure != $permalink_structure) {
		$advanced_settings	 = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
		$cache_dir			 = flash_cache_get_home_path() . $advanced_settings['cache_dir'];
		flash_cache_delete_dir($cache_dir, true);
		flash_cache_notices::add(['text' => __('Cache files have been removed to fit the new permalinks structure.', 'flash-cache')]);
	}
	if ($permalink_structure == '') {
		$post_values			 = flash_cache_settings::default_general_options();
		$post_values['activate'] = 0;
		$new_options			 = wp_parse_args($post_values, flash_cache_settings::default_general_options());
		$new_options			 = apply_filters('flash_cache_check_general_settings', $new_options);
		update_option('flash_cache_settings', $new_options);
		flash_cache_update_htaccess();
	}
}
function flash_cache_get_option($option_name, $default = false) {
	$options_advance = get_option('flash_cache_advanced_settings', array());
	$options_general = get_option('flash_cache_settings', array());
	$completeArray = array_merge($options_advance, $options_general);
	return isset($completeArray[$option_name]) ? $completeArray[$option_name] : $default;
}

function flash_cache_set_option($option_name, $value = false) {
	$advanced_settings = get_option('flash_cache_advanced_settings', array());
	$general_settings = get_option('flash_cache_settings', array());

	if (array_key_exists($option_name, $advanced_settings)) {
		$advanced_settings[$option_name] = $value;
		$updated = update_option('flash_cache_advanced_settings', $advanced_settings);
	} elseif (array_key_exists($option_name, $general_settings)) {
		$general_settings[$option_name] = $value;
		$updated = update_option('flash_cache_settings', $general_settings);
	}

	return isset($updated) ? $updated : false;
}
