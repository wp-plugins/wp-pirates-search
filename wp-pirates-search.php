<?php
/*
Plugin Name: WP Pirates Search
Plugin URI: http://khrolenok.ru/en/wp-pirates-search/
Description: This plugin allows you to find the pirates who coping articles from your website.
Version: 2.0.0
Author: Andrey Khrolenok & SedLex
Author URI: http://khrolenok.ru/en/
License: GPL3
TextDomain:	wp-pirates-search
DomainPath: /lang
*/

if(file_exists(dirname(__FILE__) . '/flag-DEBUG')){
	define('wpPiratesSearch_DEBUG', 1);
	error_reporting(E_ALL ^ E_NOTICE);
}



function plagiatSearch_get_options(){
	// Fake function to prevent simultaneous activation of both plugins
}

function postscompare_get_options(){
	// Fake function to prevent simultaneous activation of both plugins
}



if(!class_exists('wpPiratesSearch')){

class wpPiratesSearch {
	const DB_VERSION	= 2;

	var $db_table		= '';
	var $searchengines	= NULL;

  /**
   * wpPiratesSearch::wpPiratesSearch()
   * Class constructor
   *
   * @param string $loader The fully qualified filename of the loader script that WP identifies as the "main" plugin file.
   * @param blcConfigurationManager $conf An instance of the configuration manager
   * @return void
   */
    function wpPiratesSearch(){
        global $wpdb;

		$this->db_table = $wpdb->prefix . 'pirates_search';
		
		$opt = $this->get_options();

		// *** Actions ***********************************************
		add_action('init',			array(&$this, 'init_plugin'));
		add_action('plugins_loaded',	array(&$this, 'update_check'));
		add_action('shutdown',		array(&$this, 'cron_hook'));
		add_action('admin_init',	array(&$this, 'admin_init'));	// Admin Panel Init
		add_action('admin_menu',	array(&$this, 'admin_menu'));	// Admin Panel Page

		// *** Filters ***********************************************
		add_filter('the_content',	array(&$this, 'hidden_marker')) ;
		//
		if(!$opt['disable_internal_search']){
			add_filter('wpps_init_search',		array(&$this, 'google_init')) ;
			add_filter('wpps_do_search',		array(&$this, 'google_search'), 10, 4) ;
		}

		// *** Install and Uninstall *********************************
		register_activation_hook(__FILE__, array(&$this, 'install'));
		// register_deactivation_hook(__FILE__, array(&$this, 'uninstall'));

		if(false){
			// Plugin Name of the plugin/theme
			__('WP Pirates Search', 'wp-pirates-search');
			// Plugin URI of the plugin/theme
			__('http://khrolenok.ru/en/wp-pirates-search/', 'wp-pirates-search');
			// Description of the plugin/theme
			__('This plugin allows you to find the pirates who coping articles from your website.', 'wp-pirates-search');
			// Author of the plugin/theme
			__('Andrey Khrolenok & Eric Gruson', 'wp-pirates-search');
			// Author URI of the plugin/theme
			__('http://khrolenok.ru/en/', 'wp-pirates-search');
		}
    }

	/** =====================================================================================================
	* Translate the plugin...
	*
	* @return void
	*/
	function init_plugin(){
		if(function_exists('load_plugin_textdomain')){
			if(!defined('WP_PLUGIN_DIR')){
				load_plugin_textdomain('wp-pirates-search', str_replace(ABSPATH, '', dirname(__FILE__)) . '/lang');
			}else{
				load_plugin_textdomain('wp-pirates-search', false, dirname(plugin_basename(__FILE__)) . '/lang');
			}
		}
		
		// Initialize search engines
		$this->searchengines = apply_filters('wpps_init_search', array());
	}

	/** =====================================================================================================
	* In order to install the plugin, few things are to be done ...
	*
	* @return void
	*/
	function install(){
		global $wpdb;
		static $statuses = array('found', 'warned', 'legal', 'ignore');

		$installed_ver = get_option('wpPiratesSearch_db_version');

		if($wpdb->get_var("SHOW TABLES LIKE '{$this->db_table}'") != $this->db_table) {
			// Creating new DB table for plugin
			$sql = "CREATE TABLE {$this->db_table} (
				id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
				post_id BIGINT(11) DEFAULT '0' NOT NULL,
				found_url VARCHAR(255) NOT NULL,
				query_text VARCHAR(255) NOT NULL,
				found_time BIGINT(11) DEFAULT '0' NOT NULL,
				status ENUM('found', 'warned', 'legal', 'ignore') DEFAULT 'found' NOT NULL,
				service_url VARCHAR(255),
				found_text TEXT,
				PRIMARY KEY (id),
				UNIQUE KEY found_url (post_id, found_url, query_text)
			) DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ;";

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);

			// Convert info from old versions of plugin
			$converted = false;
			if($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}plagiat_search'") == "{$wpdb->prefix}plagiat_search"){
				if(!$converted){
					$opt = get_option('plagiatSearch_options', $this->get_options());
					update_option('wpPiratesSearch_options', array(
						'post_at_once'			=> $opt['settings_post_at_once'],
						'sentence_from_post'	=> $opt['settings_sentence_from_post'],
						'words_in_result'		=> $opt['settings_words_in_result'],
						'check_cache_time'		=> $opt['settings_check_cache_time'],
						'post_status_pending'	=> $opt['settings_post_status_pending'],
						'ignore_sites'			=> $opt['settings_sites_filter'],
					));

					$select = "SELECT * FROM {$wpdb->prefix}plagiat_search WHERE validate <> 0 OR searchtext <> ''";
					$results = $wpdb->get_results($select);
					foreach($results as $row){
						$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (postid, status, searchengine, searchtext, resulttext, url, cacheurl, time) " .
							"VALUES (%d, %s, 'Google', %s, %s, %s, %s, %d)", $row->postid, $statuses[$row->validate], $row->searchtext, $row->resulttext, $row->url, $row->cacheurl, $row->time);
						$wpdb->query($insert);
					}
					$converted = true;
				}

				delete_option('plagiatSearch_db_version');
				delete_option('plagiatSearch_options');
				$wpdb->query("DROP TABLE {$wpdb->prefix}plagiat_search");
			}
			if($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}postscompare_search_result'") == "{$wpdb->prefix}postscompare_search_result"){
				if(!$converted){
					$opt = get_option('postscompare_options', $this->get_options());
					update_option('wpPiratesSearch_options', array(
						'post_at_once'			=> $opt['settings_post_at_once'],
						'sentence_from_post'	=> $opt['settings_sentance_from_post'],
						'words_in_result'		=> $opt['settings_words_in_result'],
						'check_cache_time'		=> $opt['settings_check_cache_time'],
						'post_status_pending'	=> $opt['settings_post_status_pending'],
						'searchengine_yandex'	=> $opt['settings_searchengine_yandex'],
						'ignore_sites'			=> $opt['settings_sites_filter'],
					));

					$select = "SELECT * FROM {$wpdb->prefix}postscompare_search_result WHERE searchtext <> ''";
					$results = $wpdb->get_results($select);
					foreach($results as $row){
						$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (postid, searchengine, searchtext, resulttext, url, cacheurl, time) " .
							"VALUES (%d, %s, %s, %s, %s, %s, %d)", $row->postid, $row->searchengine, $row->searchtext, $row->resulttext, $row->url, $row->cacheurl, $row->time);
						$wpdb->query($insert);
					}
					$converted = true;
				}

				delete_option('postscompare_db_version');
				delete_option('postscompare_options');
				$wpdb->query("DROP TABLE {$wpdb->prefix}postscompare_search_result");
			}

		}elseif($installed_ver != self::DB_VERSION){
			// Updating plugin DB table
				// id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
				// postid BIGINT(11) DEFAULT '0' NOT NULL,
				// status ENUM('none', 'pirated', 'legal', 'ignore') DEFAULT 'none' NOT NULL,
				// searchtext TINYTEXT,
				// searchengine VARCHAR(50),
				// resulttext TEXT,
				// url VARCHAR(255) NOT NULL,
				// cacheurl VARCHAR(255),
				// time BIGINT(11) DEFAULT '0' NOT NULL,
				// UNIQUE KEY id (id)
			$update = array(
				"DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci",
				//
				"CHANGE postid post_id BIGINT(11) DEFAULT '0' NOT NULL",
				"CHANGE url found_url VARCHAR(255) NOT NULL AFTER post_id",
				"CHANGE query_text searchtext VARCHAR(255) NOT NULL AFTER post_id",
				"CHANGE time found_time BIGINT(11) DEFAULT '0' NOT NULL AFTER query_text",
				"CHANGE status status ENUM('none', 'pirated', 'found', 'warned', 'legal', 'ignore') DEFAULT 'found' NOT NULL AFTER found_time",
				"CHANGE cacheurl service_url VARCHAR(255) AFTER status",
				"CHANGE resulttext found_text TEXT AFTER service_url",
				"DROP searchengine",
				//
				"ADD PRIMARY KEY (id)",
				"ADD UNIQUE KEY found_url (post_id, found_url, query_text)",
			);
			foreach($update as $sql){
				$wpdb->query("ALTER TABLE {$this->db_table} " . $sql);
			}
			$wpdb->query("UPDATE {$this->db_table} SET status = 'found' WHERE status = 'none'");
			$wpdb->query("UPDATE {$this->db_table} SET status = 'warned' WHERE status = 'pirated'");
			$wpdb->query("ALTER TABLE {$this->db_table} CHANGE status status ENUM('found', 'warned', 'legal', 'ignore') DEFAULT 'found' NOT NULL");
			$wpdb->query("ALTER TABLE {$this->db_table} DROP INDEX `id`");
		}

		update_option('wpPiratesSearch_db_version', self::DB_VERSION);
	}

	/** =====================================================================================================
	*
	*
	* @return void
	*/
	function update_check(){
		if(get_option('wpPiratesSearch_db_version') != self::DB_VERSION){
			$this->install();
		}
	}

	/** =====================================================================================================
	* In order to uninstall the plugin, few things are to be done ...
	*
	* @return void
	*/
	function plagiatSearch_uninstall(){ /* Void function */ }

	/** =====================================================================================================
	* Load options of the plugin
	*
	* @return array list of options
	*/
	function get_options(){
		$opt = get_option('wpPiratesSearch_options');

		if(!isset($opt['post_at_once']))			$opt['post_at_once'] = 1;
		if(!isset($opt['words_in_result']))			$opt['words_in_result'] = 7;
		if(!isset($opt['check_cache_time']))		$opt['check_cache_time'] = 48;
		if(!isset($opt['expiration_cache_days']))	$opt['expiration_cache_days'] = 30;
		if(!isset($opt['post_status_pending']))		$opt['post_status_pending'] = 0;
		if(!isset($opt['searchengine_google']))		$opt['searchengine_google'] = 1;
		if(!isset($opt['searchengine_yandex']))		$opt['searchengine_yandex'] = 0;
		if(!isset($opt['auto_processing']))			$opt['auto_processing'] = 0;
		if(!isset($opt['place_hidden_marker']))		$opt['place_hidden_marker'] = false;
		if(!isset($opt['disable_internal_search']))	$opt['disable_internal_search'] = false;

		return $opt;
	}

	/** =====================================================================================================
	* Initialize the plugin
	*
	* @return void
	*/
	function admin_init(){
		wp_register_style('wpPiratesSearch-css',
			WP_PLUGIN_URL . '/' . substr(plugin_basename(__FILE__), 0, -3) . 'css'
		);

		register_setting('wpPiratesSearch_options', 'wpPiratesSearch_options', array(&$this, 'options_validate'));
	}

	/** =====================================================================================================
	* Create the submenus in "Dashboard" and "Options" menus with the number of possible plagiaries found
	*
	* @return void
	*/
	function admin_menu(){
		if(current_user_can('delete_pages') || current_user_can('delete_posts') || current_user_can('edit_pages') || current_user_can('edit_posts')){
			global $wpdb;

			$select = "SELECT post_id, COUNT(DISTINCT query_text) AS cnt FROM {$this->db_table} WHERE status = 'found' GROUP BY post_id";
			$results = $wpdb->get_results($select);
			$num_of_queries = array();
			foreach($results as $res){
				$num_of_queries[$res->post_id] = $res->cnt;
			}

			$select = "SELECT post_id, found_url, COUNT(DISTINCT query_text) AS rank FROM {$this->db_table} WHERE found_url <> '' AND status = 'found' GROUP BY post_id, found_url";
			$results = $wpdb->get_results($select);
			$nbPlagiat = 0;
			foreach($results as $res){
				if(($num_of_queries[$res->post_id] >= 5) && (($res->rank * 100 / $num_of_queries[$res->post_id]) > 50))
					$nbPlagiat++;
			}

			if($nbPlagiat == 0){
				$results_page_hook = add_submenu_page('index.php', __('Search for pirates', 'wp-pirates-search'), __('Search for pirates', 'wp-pirates-search'), 6, 'wpPiratesSearch', array(&$this, 'results_page'));
			} else {
				$results_page_hook = add_submenu_page('index.php', __('Search for pirates', 'wp-pirates-search'), __('Search for pirates', 'wp-pirates-search') . ' <span class=\'update-plugins count-1\' title=\'' . sprintf( _n('%d pirated copy possible', '%d pirated copies possibles', $nbPlagiat, 'wp-pirates-search'), $nbPlagiat) . '\'><span class=\'update-count\'>'.$nbPlagiat.'</span></span>', 6, 'wpPiratesSearch', array(&$this, 'results_page'));
			}
		}

		$options_page_hook = add_options_page(__('Search for pirates', 'wp-pirates-search'), __('Search for pirates', 'wp-pirates-search'), 'manage_options', 'wpPiratesSearch', array(&$this, 'options_page'));

		// Using registered $page handle to hook stylesheet loading
		add_action('admin_print_styles-' . $results_page_hook, array(&$this, 'admin_styles'));

		// Make the Settings page link to the results page, and vice versa
		if(!function_exists('add_screen_meta_link'))
			require_once(dirname(__FILE__) . '\screen-meta-links.php');

		add_screen_meta_link(
			'wpPiratesSearch_settings_link',
			__('Go to Settings', 'wp-pirates-search'),
			admin_url('options-general.php?page=wpPiratesSearch'),
			$results_page_hook,
			array('style' => 'background-color: #FF9;')
		);
		add_screen_meta_link(
			'wpPiratesSearch_results_link',
			__('Go to Search Results', 'wp-pirates-search'),
			admin_url('index.php?page=wpPiratesSearch'),
			$options_page_hook,
			array('style' => 'background-color: #FF9;')
		);
	}

	/** =====================================================================================================
	* Inject CSS and JS script into the head of the page (only admin one)
	*
	* @return void
	*/
	function admin_styles(){
		wp_enqueue_style('wpPiratesSearch-css');
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_script('jquery-ui-tabs');
		echo '<script type="text/javascript"> addLoadEvent = function(func){if(typeof jQuery!="undefined")jQuery(document).ready(func);else if(typeof wpOnload!="function"){wpOnload=func;}else{var oldonload=wpOnload;wpOnload=function(){oldonload();func();}}};</script>' ;
	}

	/** =====================================================================================================
	*
	*
	* @return void
	*/
	function options_page(){
		$recomended = __('(Recomended value is %s)', 'wp-pirates-search');
		add_settings_section('wpPiratesSearch', __('Main Settings', 'wp-pirates-search'), create_function('', ''), 'wpPiratesSearch');
		add_settings_field('post_at_once', __('Check posts at once for one time', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'post_at_once',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf($recomended, 1) . '<br/>' . __('Please note that the increase in this value increases the load on the server of your website.', 'wp-pirates-search'),
		));
		add_settings_field('words_in_result', __('Amount of words one by one in result for compare', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'words_in_result',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf($recomended, '5­-8') . '<br/>' . __('The smaller the value, the greater is the number of results, but less accurate search.', 'wp-pirates-search'),
		));
		add_settings_field('check_cache_time', __('Check posts again every (hours)', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'check_cache_time',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf($recomended, 48),
		));
		add_settings_field('expiration_cache_days', __('Expire cache after (days)', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'expiration_cache_days',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf($recomended, 30) . '<br/>' . __('How many days search results will be considered relevant.', 'wp-pirates-search'),
		));
		add_settings_field('ignore_sites', __('Filter sites (one site in one line) which should not be displayed', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'textarea',
			'id'		=> 'ignore_sites',
			'rows'		=> 4,
			'cols'		=> 50,
		));
		add_settings_field('auto_processing', __('Use automatic start processing', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'checkbox',
			'id'		=> 'auto_processing',
			'description'	=> sprintf(__('Not recomended for sites with high traffic.<br />(If not checked, periodically fetch page <a href="%1$s">%1$s</a> for processing new part of posts.)', 'wp-pirates-search'), WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__)) . '/process.php'),
		));
		//
		add_settings_section('wpPiratesSearch_additional', __('Additional Settings', 'wp-pirates-search'), create_function('', ''), 'wpPiratesSearch');
		add_settings_field('post_status_pending', __('Check only pending posts', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch_additional', array(
			'type'		=> 'checkbox',
			'id'		=> 'post_status_pending',
		));
		add_settings_field('disable_internal_search', __('Disable internal Google search API', 'wp-pirates-search'), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch_additional', array(
			'type'		=> 'checkbox',
			'id'		=> 'disable_internal_search',
			'description'	=> __('Use only if another search engine API was added by separate plugin.', 'wp-pirates-search'),
		));
		
		do_action('wpps_options');

		?>
<div class="wrap">
	<h2><?php _e('Search for pirates', 'wp-pirates-search'); ?></h2>
	<?php printf( __("<p><strong>Instructions:</strong> Default settings are sufficient for 99%% of sites.<br/>
Support page with questions and answers: %s (Please write in Russian or English, or use Google Translator for other languages)</p>", 'wp-pirates-search'), '<a href="http://khrolenok.ru/wp-pirates-search/" target="_blank">http://khrolenok.ru/wp-pirates-search/</a>'); ?>
	<form method="post" action="options.php">
		<?php settings_fields('wpPiratesSearch_options'); ?>
		<?php do_settings_sections('wpPiratesSearch'); ?>
		<p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>
	</form>
</div>
<?php
	}

	/** =====================================================================================================
	*
	*
	* @return void
	*/
	function option_display($args){
		$opt = $this->get_options();

		switch($args['type']){
		case 'text':
		case 'number':
			print "<input id='{$args[id]}' name='wpPiratesSearch_options[{$args[id]}]' type='text' value='{$opt[$args[id]]}' size='{$args[size]}' maxlength='{$args[maxlength]}' />";
			break;
		case 'checkbox':
			print "<input id='{$args[id]}' name='wpPiratesSearch_options[{$args[id]}]' type='checkbox' value='1' "; checked($opt[$args['id']], 1); print "/>";
			break;
		case 'textarea':
			print "<textarea id='{$args[id]}' name='wpPiratesSearch_options[{$args[id]}]' rows='{$args[rows]}' cols='{$args[cols]}'>" . stripslashes(htmlspecialchars($opt[$args['id']])) . "</textarea>";
			break;
		}

		if(!empty($args['description'])){
			print " <span class='description'>{$args[description]}</span>";
		}
	}

	/** =====================================================================================================
	*
	*
	* @return
	*/
	function options_validate($input){
		$opt = $this->get_options();

		// Integer values
		$val = intval($input['post_at_once']);
		if($val < 1)		add_settings_error('post_at_once', 'error', __('Value of posts can not be less than 1.', 'wp-pirates-search'));
		else 	$opt['post_at_once'] = $val;
		$val = intval($input['words_in_result']);
		if($val < 1)		add_settings_error('words_in_result', 'error', __('Value of words can not be less than 1.', 'wp-pirates-search'));
		else 	$opt['words_in_result'] = $val;
		$val = intval($input['check_cache_time']);
		if($val < 1)		add_settings_error('check_cache_time', 'error', __('Value hours can not be less than 1.', 'wp-pirates-search'));
		else 	$opt['check_cache_time'] = $val;
		//
		// Boolean values
		$opt['post_status_pending'] = intval(!empty($input['post_status_pending']));
		$opt['searchengine_google'] = intval(!empty($input['searchengine_google']));
		$opt['searchengine_yandex'] = intval(!empty($input['searchengine_yandex']));
		$opt['auto_processing'] = intval(!empty($input['auto_processing']));
		//
		// Text values
		$opt['ignore_sites'] = $input['ignore_sites'];
		
		$tmp = apply_filters('wpps_options_validate', $opt, $input);
		if(is_array($tmp))
			$opt = array_merge($opt, $tmp);

		return $opt;
	}

	/** =====================================================================================================
	*
	*
	* @return void
	*/
	function results_page(){
		global $wpdb;

		if(isset($_GET['status'])){
			if(is_numeric($_GET['id'])){
				switch(intval($_GET['status'])){
				case 0:		// Plagiarism corrected
					$select = $wpdb->prepare("DELETE FROM {$this->db_table} WHERE id = %d", $_GET['id']);
					$wpdb->query( $select );
					break;
				case 1:		// It's a plagiarism
					$select = $wpdb->prepare("UPDATE {$this->db_table} SET status = 'warned' WHERE id = %d", $_GET['id']);
					$wpdb->query( $select );
					break;
				case 2:		// It's not a plagiarism
					$select = $wpdb->prepare("UPDATE {$this->db_table} SET status = 'legal' WHERE id = %d", $_GET['id']);
					$wpdb->query( $select );
					break;
				}
			}
			if(($_GET['status'] == 3) && is_numeric($_GET['postid'])) {		// Dont't search for plagiarism for this post
				$select = $wpdb->prepare("DELETE FROM {$this->db_table} WHERE postid = %d", $_GET['postid']);
				$wpdb->query( $select );
				$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (postid, url, time, status) VALUES (%d, %s, %d, 'ignore')", $_GET['postid'], get_option('siteurl'), time());
				$wpdb->query( $insert );
			}
		}

		//--------------------------------------------------------------
		// Prepare the list of the tabs
		//--------------------------------------------------------------
		$section_names = array(
			'plagiary'	=> __('Possible plagiaries', 'wp-pirates-search'),
			'summary'	=> __('Summary of searches', 'wp-pirates-search'),
		);
?>
	<div class="wrap">
		<h2><?php _e('Search for pirates', 'wp-pirates-search'); ?></h2>

		<script type="text/javascript">jQuery(function($){ $('#tabs').tabs(); });</script>
		<div id="tabs">
			<ul class="hide-if-no-js">
				<?php
					foreach($section_names as $section_id => $section_name) {
						printf('<li><a href="#section-%s">%s</a></li>', esc_attr($section_id), $section_name);
					}
				?>
			</ul>

			<div id="section-plagiary" class="blc-section">
				<h3 class="hide-if-js"><?php echo $section_names['plagiary']; ?></h3>
				<?php $this->print_result(); ?>
			</div>

			<div id="section-summary" class="blc-section">
				<h3 class="hide-if-js"><?php echo $section_names['summary']; ?></h3>
				<?php $this->print_summary(); ?>
			</div>
		</div>
	</div>
<?php
	}

	/** =====================================================================================================
	*
	*
	* @return void
	*/
	function cron_hook(){
		$opt = $this->get_options();

		if(!defined('wpPiratesSearch_DEBUG') && $opt['auto_processing'])	$this->process();
	}

	/** =====================================================================================================
	*
	*
	* @return void
	*/
	function process(){
		global $wpdb;

		$opt = $this->get_options();

		$args = array(
		  'post_type'   => 'post',
		  'numberposts' => -1,
		  'post_status' => 'pending' . ($opt['post_status_pending'] ? '' : ',publish,future'),
		  'orderby'     => 'rand',
			);
		$posts = get_posts($args);
		$args['post_type'] = 'page';
		$posts = array_merge($posts, get_posts($args));
		shuffle($posts);

		$tmp = preg_split("/[\s,;]+/", $_SERVER['HTTP_HOST'] . "\n" . $opt['ignore_sites']);
		$exclude_sites = array();
		foreach($tmp as $val){
			$val = str_replace("http://", "", $val);
			$val = str_replace("www.", "", $val);
			if(!empty($val))	$exclude_sites[$val] = 1;
		}
		$exclude_sites = array_keys($exclude_sites);
		
		$k = 0;
		foreach($posts as $post){
			$taketime = time() - 3600 * $opt['check_cache_time'];
			$results = 0;

			$select = $wpdb->prepare("SELECT * FROM {$this->db_table} WHERE post_id = %d AND (found_time > %d OR status = 'ignore')", $post->ID, $taketime);
			$results = $wpdb->query( $select ) ;
			if(($results == 0) && ($k < $opt['post_at_once'])) {  // Only "x" records for one time
if(defined('wpPiratesSearch_DEBUG'))	echo '(' . $post->ID . ') ' . htmlspecialchars($post->post_title) . '<br />';
				$k++;
				$terms = $this->get_search_terms($post, $opt['words_in_result']);
				foreach($terms as $search_term){
if(defined('wpPiratesSearch_DEBUG'))	echo '<strong>Searching for "' . $search_term . '"</strong><br />';
					$search_results = apply_filters('wpps_do_search', array(), 'text', $search_term, $exclude_sites);
					if(!empty($search_results)){
						$tmp = array();
						foreach($search_results as $res){
							$tmp[$res->found_url] = $res;
						}
						foreach($tmp as $furl => $res){
							$update = $wpdb->prepare("UPDATE {$this->db_table} SET found_time = %d, found_text = %s, service_url = %s WHERE post_id = %d AND found_url = %s AND query_text = %s",
								time(), (string) $res->found_content, (string) $res->cache_url,
								$post->ID, $furl, $search_term);
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($update) . '<br />';
							$results = $wpdb->query($update);
							if($results == 0){
								$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (post_id, found_url, query_text, found_time, found_text, service_url) " .
									"VALUES (%d, %s, %s, %d, %s, %s)", $post->ID, $furl, $search_term, time(), (string) $res->found_content, (string) $res->cache_url);
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($insert) . '<br />';
								$results = $wpdb->query($insert);
							} // if
						} // if
					}else{
						$update = $wpdb->prepare("UPDATE {$this->db_table} SET found_time = %d WHERE post_id = %d AND found_url = '' AND query_text = %s",
							time(),
							$post->ID, $search_term);
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($update) . '<br />';
						$results = $wpdb->query($update);
						if($results == 0){
							$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (post_id, found_url, query_text, found_time) " .
								"VALUES (%d, '', %s, %d)", $post->ID, $search_term, time());
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($insert) . '<br />';
							$results = $wpdb->query($insert);
						} // if
					} // if
				} // while

				if(empty($terms)){
					$update = $wpdb->prepare("UPDATE {$this->db_table} SET found_time = %d WHERE post_id = %d AND found_url = '' AND query_text = ''",
						time(),
						$post->ID);
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($update) . '<br />';
					$results = $wpdb->query($update);
					if($results == 0){
						$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (post_id, found_url, query_text, found_time) " .
							"VALUES (%d, '', '', %d)", $post->ID, time());
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($insert) . '<br />';
						$results = $wpdb->query($insert);
					} // if
				} // if
			} // if
		} // foreach
	}

	/** =====================================================================================================
	*
	*
	* @return
	*/
/*	function yandex_check($query) {
		$url = "http://xmlsearch.yandex.ru/xmlsearch?query=".str_replace (" ", "%20", $query)."&groupby=attr%3Dd.mode%3Ddeep.groups-on-page%3D10.docs-in-group%3D1";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_REFERER, trackback_url(false));
		$xml = curl_exec($ch);
		curl_close($ch);

		$groups = explode("<group>", $xml);
		foreach ($groups as $group) {
			if (preg_match("!<url>(.*?)</url>!si",$group,$url)) $url=$url[1];
			if (preg_match("!<domain>(.*?)</domain>!si",$group,$domain)) $domain=$domain[1];
			if (preg_match("!<title>(.*?)</title>!si",$group,$title)) $title=$title[1];
			if (preg_match("!<passages>(.*?)</passages>!si",$group,$passages)) $passages=$passages[1];

			$passages = preg_replace('!<hlword priority="strict">(.*?)</hlword>!si', "<b>\\1</b>", $passages);
			$passages = preg_replace('!(</b>(\S\s|\s)<b>)!i'," ",$passages);

			if (!empty($url) && !empty($title)) $yaresult[] = array($url, $domain, $title, trim($passages));
		}

		if (is_array($yaresult)) {
			foreach ($yaresult as $key => $resultjson) {
				$result_google[$key]['searchengine']	= 'Yandex';
				$result_yandex[$key]['urls']			= $resultjson[0];
				$result_yandex[$key]['contents']		= $resultjson[3];
				$result_yandex[$key]['cacheUrl']		= "";
				$result_yandex[$key]['realtext']		= $query;
			}
		}
		else
		{
			return array();
		}

		return $result_yandex;
	}

	/** =====================================================================================================
	*
	*
	* @return
	*/
/*	function yandex_check_status() {
		$url = "http://xmlsearch.yandex.ru/xmlsearch";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_REFERER, trackback_url(false));
		$xml = curl_exec($ch);
		curl_close($ch);

		// echo "\n<!-- " . htmlspecialchars(var_export($xml, true)) . " -->\n";

		if (preg_match("!<error[^>]*>(.*?)</error>!si",$xml,$error)) $error=$error[1];
		if (preg_match("!<request>(.*?)</request>!si",$xml,$request)) $request=$request[1];

		if(!empty($request)){
			return __("Yandex ready", 'wp-pirates-search');
		}else{
			return sprintf(__('Yandex status: %1$d <a href="%2$s" target="_blank">Set/Change your server IP here, need for search by yandex.</a>', 'wp-pirates-search'), $error, "http://xml.yandex.ru/ip.xml");
		}
	}

	/** =====================================================================================================
	*
	*
	* @return
	*/
	function print_result() {
		global $wpdb;

		$opt = $this->get_options();

		$site_num = 0;

		$select = "SELECT post_id, COUNT(DISTINCT query_text) AS cnt FROM {$this->db_table} WHERE status = 'found' GROUP BY post_id";
		$results = $wpdb->get_results($select);
		$num_of_queries = $post_rank = array();
		foreach($results as $res){
			if($res->cnt >= 5){
				$num_of_queries[$res->post_id] = $res->cnt;
				$post_rank[$res->post_id] = 0;
			}
		}
		
		$url_rank = array();
		foreach(array_keys($num_of_queries) as $post_id){
			$select = $wpdb->prepare("SELECT found_url, COUNT(DISTINCT query_text) AS rank FROM {$this->db_table} WHERE post_id = %d  AND found_url <> '' AND status IN ('found', 'warned') GROUP BY post_id, found_url", $post_id);
			$results = $wpdb->get_results($select);
			foreach($results as $res){
				$rank = (int) ($res->rank * 100 / $num_of_queries[$post_id]);
				$post_rank[$post_id] = max($post_rank[$post_id], $rank);
				$url_rank[$post_id][$res->found_url] = $rank;
// if(defined('wpPiratesSearch_DEBUG'))	echo "{$post_id}: {$rank} ({$post_rank[$post_id]})" . '<br />';
			}
			if(!empty($url_rank[$post_id]))
				arsort($url_rank[$post_id]);
// if(defined('wpPiratesSearch_DEBUG'))	echo var_export($url_rank[$post_id]) . '<br />';
		}
		arsort($post_rank);
// if(defined('wpPiratesSearch_DEBUG'))	echo var_export($post_rank) . '<br />';

		if(empty($post_rank)){
			print "<p>" . __('No posts matching found.', 'wp-pirates-search') . "</p>";
			return;
		}

		$action_url = str_replace("&id=" . $_GET['id'] . "&status=" . $_GET['status'], "", $_SERVER['REQUEST_URI']);
		$action_url = str_replace("&postid=" . $_GET['postid'] . "&status=" . $_GET['status'], "", $_SERVER['REQUEST_URI']);
		$action_url .= ((strpos($action_url, '?') === false) ? '?' : '&');

		$printresults = array();
		$site_num = 0;
		foreach(array_keys($post_rank) as $post_id){
			if(!is_array($url_rank[$post_id]))
				continue;

			$post = &get_post($post_id);
			?>
			<div id="site-<?php echo ++$site_num; ?>" class="postbox">
				<h2><span><a href="<?php echo post_permalink($post_id); ?>" target="_blank"><?php echo $post->post_title; ?></a></span></h2>
				<div class="inside">
				  <p><a href="<?php echo $action_url; ?>postid=<?php echo $post_id; ?>&status=3" style="font-size:90%;color:#006400"><?php _e("Don't search for plagiarism for this post", 'wp-pirates-search'); ?></a></p>
				  <ul>
			<?php
			foreach(array_keys($url_rank[$post_id]) as $found_url){
				$select = $wpdb->prepare("SELECT * FROM {$this->db_table} WHERE post_id = %d AND found_url = %s LIMIT 1", $post_id, $found_url);
				$res = $wpdb->get_row($select);
				?>
				<li class="wpPiratesSearch-<?php echo $res->status; ?>"><div>
				<strong><?php echo $url_rank[$post_id][$found_url] . '%'; ?></strong>
				<a href="<?php echo $found_url; ?>" target="_blank"><?php echo $found_url; ?></a>
					<?php
					if(!empty($res->service_url)){
						echo "(<a href='{$res->service_url}' target='_blank'>" . __('CACHE', 'wp-pirates-search') . '</a>)';
					}
					?>
					</div>
					<div><?php echo $res->found_text; ?></div>
					<br />
					<div>
				<?php if ($res->status != 'legal') { ?>
					<a href="<?php echo $action_url; ?>id=<?php echo $res->id?>&status=2" style="font-size:90%;color:#006400"><?php _e('This is not plagiarism', 'wp-pirates-search'); ?></a>
				<?php } ?>
				<?php if ($res->status != 'warned') { ?>
					<a href="<?php echo $action_url; ?>id=<?php echo $res->id?>&status=1" style="font-size:90%;color:#8B0000"><?php _e('It is a plagiarism', 'wp-pirates-search'); ?></a>
				<?php } ?>
				<?php if ($res->status != 'legal') { ?>
					<a href="<?php echo $action_url; ?>id=<?php echo $res->id?>&status=0" style="font-size:90%;color:#000000"><?php _e('Plagiarism corrected', 'wp-pirates-search'); ?></a>
				<?php } ?>
					</div>
				</li>
				<?php
			}
			?></ul>
				</div>
			</div>
			<?php
		}
	}

	/** =====================================================================================================
	* Print the plagiary summary
	*
	* @return void
	*/
	function print_summary(){
		global $wpdb;

		$select = "SELECT COUNT(DISTINCT post_id) FROM {$this->db_table}";
		$numberofsearch = $wpdb->get_var($select);
		?>
		<h4><?php _e('Macro summary', 'wp-pirates-search'); ?></h4>
		<div class="summary-line">
			<span class="summary-title"><?php _e('Search engines are now used to search for pirates: ', 'wp-pirates-search'); ?></span>
			<span class="summary-value"><?php echo join(', ', $this->searchengines['text']); ?></span>
		</div>
		<div class="summary-line">
			<span class="summary-title"><?php _e('Total number of articles checked: ', 'wp-pirates-search'); ?></span>
			<span class="summary-value"><?php echo $numberofsearch; ?></span>
		</div>
		<?php
		//
		$select = $wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$this->db_table} WHERE found_time > %d", time() - 7 * 24 * 60 * 60);
		$numberofsearch = $wpdb->get_var($select);
		?>
		<div class="summary-line">
			<span class="summary-title"><?php _e('Checked articles last week: ', 'wp-pirates-search'); ?></span>
			<span class="summary-value"><?php echo $numberofsearch; ?></span>
		</div>
		<?php
		//
		$select = $wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$this->db_table} WHERE found_time > %d", time() - 24 * 60 * 60);
		$numberofsearch = $wpdb->get_var($select);
		?>
		<div class="summary-line">
			<span class="summary-title"><?php _e('Checked articles last 24 hours: ', 'wp-pirates-search'); ?></span>
			<span class="summary-value"><?php echo $numberofsearch; ?></span>
		</div>
		<?php
		/* Temproraly switched off
		if(false){
		?>
		<h4><?php _e('Force a search', 'wp-pirates-search'); ?></h4>
		<form method="post" action="#section-summary">
		<p><?php _e('If you want to force a check against Google to be sure that the search is working properly, please click on the button.', 'wp-pirates-search'); ?></p>
		<p><?php _e('Note that only one random sentence in a random article will be searched', 'wp-pirates-search'); ?></p>
		<p class="submit"><input type="hidden" name="force_search" value="true" /><input type="submit" name="submit" value="<?php _e('Force a search against Google &raquo;', 'wp-pirates-search'); ?>" /></p>
		<?php
		if(isset($_POST['force_search'])){
			echo "<b>" . __('Result:', 'wp-pirates-search') . "</b><br/>" ;
			$this->process('force');
		}
		?>
		</form>
		<?php
		}/**
		?>

		<h4><?php _e('Detailled summary', 'wp-pirates-search'); ?></h4>
		<p><?php _e('Show the last 20 articles and the number of found URLs with terms ', 'wp-pirates-search'); ?></p>
		<?php
		$posts = get_posts('numberposts=20');
		foreach($posts as $post){
			$select = $wpdb->prepare("SELECT COUNT(found_url) FROM {$this->db_table} WHERE post_id = %d", $post->ID);
			$numberofsearch = $wpdb->get_var($select);
			?>
			<div class="summary-line" style="color:#999;">
				<span class="summary-title"><?php echo $post->post_title; ?></span>
				<span class="summary-value"><?php echo $numberofsearch; ?></span>
			</div>
			<?php
		}/**/
		echo '&nbsp;';
	}

	/** =====================================================================================================
	* Insert Fingerprint into posts
	*
	* @return string the new content
	*/
	function hidden_marker($content){
		global $post;

		$opt = $this->get_options();
		if(!$opt['place_hidden_marker'])	return $content;
		
		$output = $content;
		$key = 'copyright-' . preg_replace("|[/\.]+|", '_', preg_replace("|^.+://|", '', home_url()))/* . '-' . mysql2date('ymd', $post->post_date)/**/;
		foreach(preg_split("/[\s,]+/", 'p ul ol li span h1 h2 h3 h4 h5 h6 h7 strong blockquote div') as $tag){
			$output = str_replace("<{$tag}>", "<{$tag} class='{$key}'>", $output);
		}
		return $output;
	}
	
	/** =====================================================================================================
	* Extract search terms from post content
	*
	* @return array 
	*/
	protected function get_search_terms($post, $min_length = 7){
		// Delete abstract and split text to sentences
		$res = $this->get_sentences(preg_replace('@\A.*<!--more-->\s*@si', '', $post->post_content));

		// Sort sentences and leave not less than 10 longest of it
		$tmp = $snts = array();
		foreach($res as $key => $val){
			$tmp[$key] = $this->get_words_cnt($val);
		}
		arsort($tmp);
		foreach($tmp as $key => $val){
			if(((count($snts) <= 10) || ($val >= $min_length)) && (strlen($res[$key]) >= 10))
				$snts[] = $res[$key];
		}
		shuffle($snts);
		$res = array_slice($snts, 0, 10);

		// Extract $min_length words length search terms from selected sentences
		foreach($res as $key => $val){
			$tmp = preg_split('@[\s-/]+@Ssiu', $val, -1, PREG_SPLIT_OFFSET_CAPTURE);
			$n = mt_rand(0, max(0, count($tmp) - $min_length));
			$x = $tmp[$n][1];
			$y = !isset($tmp[$n + $min_length]) ? PHP_INT_MAX : $tmp[$n + $min_length][1];
			$res[$key] = rtrim(substr($val, $x, $y - $x), "/.!?,;: \n\r-");
		}

		return $res;
	}
	
	/** =====================================================================================================
	* Split text to single sentences
	*
	* @return array single sentences
	*/
	protected function get_sentences($text){
		// Convert HTML to plain text
		$attr_value = '(?:"(?:\\.|[^"]+)*"|\'(?:\\.|[^\\\']*)\'|\S*)';
		$attrs = "(?:\s+\w[-\w]*(?:\={$attr_value})?)*";
		$search = array(
			"@<!--.*?-->@Ssi"							=> '',	// Kill comments
			"@<script{$attrs}\s*>.*?</script>@Ssi"		=> '',	// Kill scripts
			"@<style{$attrs}\s*>.*?</style>@Ssi"		=> '',	// Kill CSS
			"@<blockquote{$attrs}\s*>.*?</blockquote>@Ssi"	=> '',	// Kill blockquotes
			"@<\w[-\w]*{$attrs}\s*(?:/\s*)?>@Ssi"		=> '',	// Kill any opening tags
			"@<\s*/\s*(?:div|p|td|th|li|h[1-7])\s*>@Si"	=> "\1",	// Replace some closing tags to special sentence delimiter
			"@<\s*/\s*\w[-\w]*\s*>@Si"					=> '',	// Kill any other closing tags
			"@\[\w[-\w]*(?:\s+{$attr_value})*\s*\]@Ssiu"	=> '',	// Kill any opening shortcodes
			"@\[\s*/\s*\w[-\w]*\s*\]@Siu"					=> '',	// Kill any closing shortcodes
			"@\n[\s.]*\n@Ssi"							=> "\n",	// Kill empty lines
			"@\s+@Su"									=> ' ',
			"@[…]+@Su"									=> '.',
			"@[—]@Su"									=> '-',
			"@[«»“”„“‘’­]@Su"							=> '',
		);
		$text = html_entity_decode(preg_replace(array_keys($search), array_values($search), $text), ENT_COMPAT, 'UTF-8');
		
		// Split text into individual sentences
		// Thanks webpavilion (http://www.maultalk.com/user12215.html) for base of this code
		preg_match_all("@(?:\1|[\.!?]\s)@Su", $text, $m, PREG_OFFSET_CAPTURE);
		array_unshift($m[0], array(0 => 0, 1 => 0));
		$count = count($m[0]);
		$s = 0;
		$snts = array();
		while($s < $count){
			$l =  $m[0][$s + 1][1] - $m[0][$s][1] + 1;
			if($l < 0)
				$l = $m[0][$count - 1][1];
			$str = rtrim(ltrim(substr($text, $m[0][$s][1], $l), "!?. \n\r\1"), "\1");
			if(!empty($str))
				$snts[] = $str;
			$s++;
		}

		return $snts;
	}
	
	/** =====================================================================================================
	* Count number of words in text
	*
	* @return integer count of words
	*/
	protected function get_words_cnt($text){
		preg_match_all('@[\.!?,;:\s-/]+@Ssiu', trim($text, "/.!?,;: \n\r-"), $m, PREG_OFFSET_CAPTURE);
		return count($m[0]) + 1;
	}
	
	/** =====================================================================================================
	* Initialize Google Search API
	*
	*/
	public function google_init($search_engines){
		$search_engines['text'][] = 'Google';	// Defines what this plugin able to search text via Google
		return $search_engines;
	}
	
	/** =====================================================================================================
	* 
	*
	*/
	public function google_search($results, $search_type, $query, $exclude_sites){
		if($search_type != 'text')
			return $results;

		$tr = array(
			'"' => ''
		);
		$query = '"' . strtr($query, $tr) . '" -site:' . join(' -site:', $exclude_sites);
// if(defined('wpPiratesSearch_DEBUG'))	echo $query . '<br />';
		$url = "http://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=" .
			urlencode($query);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_REFERER, trackback_url(false));
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		$body = curl_exec($ch);
		curl_close($ch);
		if(!function_exists('Services_JSON'))
			require_once(dirname(__FILE__) . '/JSON.php');
		$json = new Services_JSON();
		$json = $json->decode($body);

		if(is_array($json->responseData->results)){
			$pos = 0;
			foreach($json->responseData->results as $key => $val){
				$results[] = (object) array(
					'search_engine'		=> 'Google',
					'search_type'		=> 'text',
					'search_query'		=> $query,
					'result_position'	=> ++$pos,
					'found_url'			=> urldecode($val->url),
					'cache_url'			=> $val->cacheUrl,
					'found_content'		=> $val->content,
				);
			}
		}
		return $results;
	}
	
} // Class wpPiratesSearch

} // if class_exists...



// *********************************************************************************

$wpPiratesSearch = new wpPiratesSearch();

?>