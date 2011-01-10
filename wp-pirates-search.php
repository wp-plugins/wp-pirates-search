<?php
/*
Plugin Name: WP Pirates Search
Plugin URI: http://khrolenok.ru/wp-pirates-search/
Description: This plugin allows you to find the pirates who coping articles from your website.
Version: 1.0
Author: Andrey Khrolenok
Author URI: http://khrolenok.ru/
License: GPL3
*/

// define('wpPiratesSearch_DEBUG', 1);



function plagiatSearch_get_options(){
	// Fake function to prevent simultaneous activation of both plugins
}

function postscompare_get_options(){
	// Fake function to prevent simultaneous activation of both plugins
}



if(!class_exists('wpPiratesSearch')){

class wpPiratesSearch {
	const DB_VERSION	= 1;
	const TEXTDOMAIN	= 'wp-pirates-search';

	var $db_table		= '';

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

		// *** Actions ***********************************************
		add_action('init',			array(&$this, 'load_language'));
		add_action('shutdown',		array(&$this, 'shutdown_process'));
		add_action('admin_init',	array(&$this, 'admin_init'));	// Admin Panel Init
		add_action('admin_menu',	array(&$this, 'admin_menu'));	// Admin Panel Page

		// *** Filters ***********************************************
		  // None

		// *** Install and Uninstall *********************************
		register_activation_hook(__FILE__, array(&$this, 'install'));
		// register_deactivation_hook(__FILE__, array(&$this, 'uninstall'));
    }

	function load_language(){
		if(function_exists('load_plugin_textdomain')){
			if(!defined('WP_PLUGIN_DIR')){
				load_plugin_textdomain(self::TEXTDOMAIN, str_replace(ABSPATH, '', dirname(__FILE__)) . '/lang');
			}else{
				load_plugin_textdomain(self::TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/lang');
			}
		}
	}

	function install(){
		global $wpdb;
		static $statuses = array('none', 'pirated', 'legal', 'ignore');

		$installed_ver = get_option('wpPiratesSearch_db_version');

		if($wpdb->get_var("SHOW TABLES LIKE '{$this->db_table}'") != $this->db_table) {
			// Creating new DB table for plugin
			$sql = "CREATE TABLE {$this->db_table} (
				id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
				postid BIGINT(11) DEFAULT '0' NOT NULL,
				status ENUM('none', 'pirated', 'legal', 'ignore') DEFAULT 'none' NOT NULL,
				searchtext TINYTEXT,
				searchengine VARCHAR(50),
				resulttext TEXT,
				url VARCHAR(255) NOT NULL,
				cacheurl VARCHAR(255),
				time BIGINT(11) DEFAULT '0' NOT NULL,
				UNIQUE KEY id (id)
			);";

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
			// $sql = "...";

			// require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			// dbDelta($sql);
		}

		update_option('wpPiratesSearch_db_version', self::DB_VERSION);
	}

	function get_options(){
		$opt = get_option('wpPiratesSearch_options');

		if(!isset($opt['post_at_once']))		$opt['post_at_once'] = 1;
		if(!isset($opt['sentence_from_post']))	$opt['sentence_from_post'] = 3;
		if(!isset($opt['words_in_result']))		$opt['words_in_result'] = 8;
		if(!isset($opt['check_cache_time']))	$opt['check_cache_time'] = 48;
		if(!isset($opt['post_status_pending']))	$opt['post_status_pending'] = 0;
		if(!isset($opt['searchengine_google']))	$opt['searchengine_google'] = 1;
		if(!isset($opt['searchengine_yandex']))	$opt['searchengine_yandex'] = 0;
		if(!isset($opt['auto_processing']))		$opt['auto_processing'] = 0;

		return $opt;
	}

	function admin_init(){
		wp_register_style('wpPiratedSearch_css',
			WP_PLUGIN_URL . '/' . substr(plugin_basename(__FILE__), 0, -3) . 'css'
		);
		wp_enqueue_style('wpPiratedSearch_css');

		register_setting('wpPiratesSearch_options', 'wpPiratesSearch_options', array(&$this, 'options_validate'));

		add_settings_section('wpPiratesSearch', __('Main Settings', self::TEXTDOMAIN), null, 'wpPiratesSearch');
		add_settings_field('post_at_once', __('Check posts at once for one time', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'post_at_once',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf(__('(Recomended value is %d)', self::TEXTDOMAIN), 1),
		));
		add_settings_field('sentence_from_post', __('Amount of sentences from one post', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'sentence_from_post',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf(__('(Recomended value is %d)', self::TEXTDOMAIN), 3),
		));
		add_settings_field('words_in_result', __('Amount of words one by one in result for compare', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'words_in_result',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf(__('(Recomended value is %d)', self::TEXTDOMAIN), 8),
		));
		add_settings_field('check_cache_time', __('Check posts again every (hours)', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'number',
			'id'		=> 'check_cache_time',
			'size'		=> 1,
			'maxlength'	=> 2,
			'description'	=> sprintf(__('(Recomended value is %d)', self::TEXTDOMAIN), 48),
		));
		add_settings_field('post_status_pending', __('Check only pending posts', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'checkbox',
			'id'		=> 'post_status_pending',
		));
		add_settings_field('searchengine_yandex', __('Include searchengine yandex.ru?', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'checkbox',
			'id'		=> 'searchengine_yandex',
			'description'	=> $this->yandex_check_status(),
		));
		add_settings_field('ignore_sites', __('Filter sites (one site in one line) which should not be displayed', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'textarea',
			'id'		=> 'ignore_sites',
			'rows'		=> 4,
			'cols'		=> 50,
		));
		add_settings_field('auto_processing', __('Use automatic start processing', self::TEXTDOMAIN), array(&$this, 'option_display'), 'wpPiratesSearch', 'wpPiratesSearch', array(
			'type'		=> 'checkbox',
			'id'		=> 'auto_processing',
			'description'	=> sprintf(__('Not recomended for sites with high traffic.<br />(If not checked, periodically fetch page <a href="%1$s">%1$s</a> for processing new part of posts.)', self::TEXTDOMAIN), WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__)) . '/process.php'),
		));
	}

	function admin_menu(){
		if(current_user_can('delete_pages') || current_user_can('delete_posts') || current_user_can('edit_pages') || current_user_can('edit_posts')){
			global $wpdb;

			$select = "SELECT COUNT(*) FROM {$this->db_table} WHERE status = 'none' AND searchtext IS NOT NULL";
			$nbPlagiat = $wpdb->get_var($select);

			if($nbPlagiat == 0){
				$results_page_hook = add_submenu_page('index.php', __('Search for pirates', self::TEXTDOMAIN), __('Search for pirates', self::TEXTDOMAIN), 6, 'wpPiratesSearch', array(&$this, 'results_page'));
			} else {
				$results_page_hook = add_submenu_page('index.php', __('Search for pirates', self::TEXTDOMAIN), __('Search for pirates', self::TEXTDOMAIN) . ' <span class=\'update-plugins count-1\' title=\'' . sprintf( _n('%d pirated copy possible', '%d pirated copies possibles', $nbPlagiat, self::TEXTDOMAIN), $nbPlagiat) . '\'><span class=\'update-count\'>'.$nbPlagiat.'</span></span>', 6, 'wpPiratesSearch', array(&$this, 'results_page'));
			}
		}

		$options_page_hook = add_options_page(__('Search for pirates', self::TEXTDOMAIN), __('Search for pirates', self::TEXTDOMAIN), 'manage_options', 'wpPiratesSearch', array(&$this, 'options_page'));

		//Make the Settings page link to the results page, and vice versa
		add_screen_meta_link(
			'wpPiratesSearch_settings_link',
			__('Go to Settings', self::TEXTDOMAIN),
			admin_url('options-general.php?page=wpPiratesSearch'),
			$results_page_hook,
			array('style' => 'font-weight: bold;')
		);
		add_screen_meta_link(
			'wpPiratesSearch_results_link',
			__('Go to Search Results', self::TEXTDOMAIN),
			admin_url('index.php?page=wpPiratesSearch'),
			$options_page_hook,
			array('style' => 'font-weight: bold;')
		);
	}

	function options_page(){
?>
<div class="wrap">
	<h2><?php _e('Search for pirates', self::TEXTDOMAIN); ?></h2>
<?php printf( __("<p><strong>Instructions:</strong> Default settings are sufficient for 99%% of sites. Please note that the increase in value increases the load on the server of your website.<br/>
Support page with questions and answers: %s (Please write in Russian or English, or use Google Translator for other languages)</p>", self::TEXTDOMAIN), '<a href="http://khrolenok.ru/wp-pirates-search/" target="_blank">http://khrolenok.ru/wp-pirates-search/</a>'); ?>
	<form method="post" action="options.php">
		<?php settings_fields('wpPiratesSearch_options'); ?>
		<?php do_settings_sections('wpPiratesSearch'); ?>
		<p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>
	</form>
</div>
<?php
	}

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

	function options_validate($input){
		$opt = $this->get_options();

		// Integer values
		$val = intval($input['post_at_once']);
		if($val < 1)		add_settings_error('post_at_once', 'error', __('Value of posts can not be less than 1.', self::TEXTDOMAIN));
		else 	$opt['post_at_once'] = $val;
		$val = intval($input['sentence_from_post']);
		if($val < 1)		add_settings_error('sentence_from_post', 'error', __('Value of sentences can not be less than 1.', self::TEXTDOMAIN));
		else 	$opt['sentence_from_post'] = $val;
		$val = intval($input['words_in_result']);
		if($val < 1)		add_settings_error('words_in_result', 'error', __('Value of words can not be less than 1.', self::TEXTDOMAIN));
		else 	$opt['words_in_result'] = $val;
		$val = intval($input['check_cache_time']);
		if($val < 1)		add_settings_error('check_cache_time', 'error', __('Value hours can not be less than 1.', self::TEXTDOMAIN));
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

		return $opt;
	}

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
					$select = $wpdb->prepare("UPDATE {$this->db_table} SET status = 'pirated' WHERE id = %d", $_GET['id']);
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

?>
	<div class="wrap">
		<h2><?php _e('Search for pirates', self::TEXTDOMAIN); ?></h2>
		<?php $this->print_result(); ?>
	</div>
<?php
	}

	function shutdown_process(){
		$opt = $this->get_options();

		if(!defined('wpPiratesSearch_DEBUG') && $opt['auto_processing'])	$this->cron_process();
	}

	function cron_process() {
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

		$k = 0;
		$posttitles = $searchengresult = array();
		foreach ($posts as $post) {
			$taketime = time() - 3600 * $opt["check_cache_time"];
			$results = 0;

			$select = "SELECT * FROM {$this->db_table} WHERE postid = '{$post->ID}' AND (time > {$taketime} OR status = 'ignore')";
			$results = $wpdb->query( $select ) ;
			if(($results == 0) && ($k < $opt["post_at_once"])) {  // Only "x" records for one time
				$posttitles[$post->ID] = $post->post_title;
if(defined('wpPiratesSearch_DEBUG'))	echo '(' . $post->ID . ') ' . htmlspecialchars($post->post_title) . '<br />';
				$k++;
				$c = $d = 0;

				// We suppress the quoted text. . .
				$withoutquotes = preg_replace('/<blockquote>(.*?\r*?\n*?)*?<\/blockquote>/i', '', $post->post_content);

				$sentences = explode(".", strip_tags($withoutquotes) ) ;

				while($c < $opt['sentence_from_post'] and $d < 200) {
				  $sentence = $sentences[rand(0, count($sentences) - 1)] ;
				  if($this->word_count($sentence) >= $opt["words_in_result"]) {

					// Search pirates by Google
					if($opt['searchengine_google']){
						if ($resgoogle = $this->google_check($sentence)) {
						  foreach ($resgoogle as $resnumber => $resdata) {
							if($this->checkcontent($resdata)) {
								$searchengresult[$post->ID][$c][$resnumber] = $resdata;
							} // if
						  } // foreach
						} // if
					} // if

					// Search pirates by Yandex
					if($opt['searchengine_yandex']){
						if($resyandex = $this->yandex_check($sentanse)) {
							foreach ($resyandex as $resnumber => $resdata) {
								if($this->checkcontent($resdata)) {
									$searchengresult[$post->ID][$c][$resnumber] = $resdata;
								} // if
							} // foreach
						} // if
					} // if

					$c++;
				  } // if
				  $d++;
				} // while
			} // if
		} // foreach

		if (!empty($searchengresult)) {
			$nositesneed = array();
			$sites_filters = explode("\r\n", $opt['sites_filter']);
			$nositesneed[] = $_SERVER['HTTP_HOST'];
			foreach ($sites_filters as $sites_filter) {
				$nositesneed[] = $sites_filter;
			}

			foreach ($searchengresult as $postid => $result) {
				foreach ($result as $listofsites) {
					foreach ($listofsites as $site) {
					  $select = "SELECT * FROM {$this->db_table} WHERE postid = '{$postid}' and url = '{$site['urls']}'";
					  $results = $wpdb->query( $select );

					  if($results == 0){
						// We had if only there is no other entry and it is not on this domain
						$nomark = false;
						foreach ($nositesneed as $nositesneed_) {
							if (!empty($nositesneed_)) {
								preg_match("/^(http:\/\/)?([^\/]+)/i", $site['urls'], $matches);
								$nositesneed_ = str_replace("http://", "", $nositesneed_);
								$nositesneed_ = str_replace("www.", "", $nositesneed_);
								if(strstr($matches[2], $nositesneed_)){
								  $nomark = true;
								  if($nositesneed_ == $_SERVER['HTTP_HOST'])
									continue 2;
								  break;
								}
							}
						}

						$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (postid, status, searchengine, searchtext, resulttext, url, cacheurl, time) " .
							"VALUES (%d, %s, %s, %s, %s, %s, %s, %d)", $postid, ($nomark ? 'legal' : 'none'), $site['searchengine'], $site['realtext'], $site['contents'], $site['urls'], $site['cacheUrl'], time());
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($insert) . '<br />';
						$results = $wpdb->query($insert);

						unset($posttitles[$postid]);
					  }
					}
				}
			}
		}

		if(!empty($posttitles)) {
			foreach ($posttitles as $postid => $posttitle) {
			  $select = "SELECT * FROM {$this->db_table} WHERE postid = '{$postid}' AND status = 'ignore'";
			  $results = $wpdb->query( $select );

			  if($results == 0){
				$update = "UPDATE {$this->db_table} SET time = '" . time() . "' WHERE postid = '{$postid}' AND url = '" . get_option('siteurl') . "'";
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($update) . '<br />';
				$results = $wpdb->query($update);

				if($results == 0){
					$insert = $wpdb->prepare("INSERT INTO {$this->db_table} (postid, url, time) " .
						"VALUES (%d, %s, %d)", $postid, get_option('siteurl'), time());
if(defined('wpPiratesSearch_DEBUG'))	echo htmlspecialchars($insert) . '<br />';
					$results = $wpdb->query($insert);
				}
			  }
			}
		}
	}

	function google_check($query) {
		$url = "http://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=".urlencode($query);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_REFERER, trackback_url(false));
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		$body = curl_exec($ch);
		curl_close($ch);
		if (!function_exists('Services_JSON')) require_once(dirname(__FILE__) . '/JSON.php');
		$json = new Services_JSON();
		$json = $json->decode($body);

		if (is_array($json->responseData->results)) {
			foreach ($json->responseData->results as $key => $resultjson) {
				$result_google[$key]['searchengine']	= 'Google';
				$result_google[$key]['urls']			= urldecode($resultjson->url);
				$result_google[$key]['contents']		= $resultjson->content;
				$result_google[$key]['cacheUrl']		= $resultjson->cacheUrl;
				$result_google[$key]['realtext']		= $query;
			}
		}

		return $result_google;
	}

	function yandex_check($query) {
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

	function yandex_check_status() {
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
			return __("Yandex ready", self::TEXTDOMAIN);
		}else{
			return sprintf(__('Yandex status: %1$d <a href="%2$s" target="_blank">Set/Change your server IP here, need for search by yandex.</a>', self::TEXTDOMAIN), $error, "http://xml.yandex.ru/ip.xml");
		}
	}

	function word_count($sentence) {
		$newsentense = explode(" ", $sentence);
		$c = 0;
		foreach ($newsentense as $newsentense_) {
			if (strlen($newsentense_) > 1) {
				$c++;
			}
		}

		return $c;
	}

	function checkcontent($res) {
		$opt = $this->get_options();

		$resarray = explode("<b>", $res['contents']);
		foreach ($resarray as $resarray_) {
			if (strstr($resarray_, "</b>")) {
				$text = explode("</b>", $resarray_);
				$text = $text[0];
				if (strlen($text) > 5) {
					if($this->word_count($text) >= $opt['words_in_result']){
						return true;
					}
				}
			}
		}

		return false;
	}

	function print_result() {
		global $wpdb;

		$opt = $this->get_options();

		$site_num = 0;

		// $select = "SELECT * FROM {$this->db_table} WHERE status IN ('none', 'pirated', 'legal') AND searchtext IS NOT NULL ORDER BY time DESC";
		$select = "SELECT * FROM {$this->db_table} WHERE status IN ('none', 'pirated') AND searchtext IS NOT NULL ORDER BY time DESC";
		$results = $wpdb->get_results($select);

		$nositesneed = array();
		$settings_sites_filters = explode("\r\n", $opt['sites_filter']);
		foreach($settings_sites_filters as $settings_sites_filter){
			$nositesneed[] = $settings_sites_filter;
		}
// echo "Size: " . count($results) . "<br />";

		foreach($results as $site){
			$nomark = false;
			foreach($nositesneed as $nositesneed_){
				if (!empty($nositesneed_)) {
					preg_match("/^(http:\/\/)?([^\/]+)/i", $site->url, $matches);
					$nositesneed_ = str_replace("http://", "", $nositesneed_);
					$nositesneed_ = str_replace("www.", "", $nositesneed_);
					if (strstr($matches[2], $nositesneed_)) {
					  $nomark = true;
					  $select = $wpdb->prepare("UPDATE {$this->db_table} SET status = 'ignore' WHERE id = %d", $site->id);
					  $wpdb->query( $select );
					  break;
					}
				}
			}
			if($nomark == false)	$printresults[$site->postid][$site->url][] = $site;
		}

		if (is_array($printresults)) {
			$action_url = str_replace("&id=" . $_GET['id'] . "&status=" . $_GET['status'], "", $_SERVER['REQUEST_URI']);
			$action_url = str_replace("&postid=" . $_GET['postid'] . "&status=" . $_GET['status'], "", $_SERVER['REQUEST_URI']);
			$action_url .= ((strpos($action_url, '?') === false) ? '?' : '&');

			foreach ($printresults as $postid => $printresult) {
				$post = &get_post($postid);
	?>
	<div id="site-<?php echo ++$site_num; ?>" class="postbox">
		<h2><span><a href="<?php echo post_permalink($postid); ?>" target="_blank"><?php echo $post->post_title; ?></a></span></h2>
		<div class="inside">
		  <p><a href="<?php echo $action_url; ?>postid=<?php echo $postid; ?>&status=3" style="font-size:90%;color:#006400"><?php _e("Don't search for plagiarism for this post", self::TEXTDOMAIN); ?></a></p>
		  <ul>
	<?php
		foreach($printresult as $url => $res){
			foreach($res as $site){
			?>
				<li class="wpPiratesSearch-<?php echo $site->status; ?>"><div><img src="/<?php echo PLUGINDIR . '/' . dirname(plugin_basename (__FILE__)) ?>/img/<?php echo strtolower($site->searchengine); ?>-ico.gif" alt="<?php echo $site->searchengine; ?>" width="16" height="16" />
			<a href="<?php echo $url; ?>" target="_blank"><?php echo $url; ?></a>
				<?php
				if(!empty($site->cacheurl)){
					echo "(<a href='{$site->cacheurl}' target='_blank'>" . __('CACHE', self::TEXTDOMAIN) . '</a>)';
				}
				?>
				</div>
				<div><b>Search:</b> <i><?php echo $site->searchtext; ?></i></div>
				<div><b>Result: </b><?php echo $site->resulttext; ?></div>
				<br />
				<div>
			<?php if ($site->status != 'legal') { ?>
				<a href="<?php echo $action_url; ?>id=<?php echo $site->id?>&status=2" style="font-size:90%;color:#006400"><?php _e('This is not plagiarism', self::TEXTDOMAIN); ?></a>
			<?php } ?>
			<?php if ($site->status != 'pirated') { ?>
				<a href="<?php echo $action_url; ?>id=<?php echo $site->id?>&status=1" style="font-size:90%;color:#8B0000"><?php _e('It is a plagiarism', self::TEXTDOMAIN); ?></a>
			<?php } ?>
			<?php if ($site->status != 'legal') { ?>
				<a href="<?php echo $action_url; ?>id=<?php echo $site->id?>&status=0" style="font-size:90%;color:#000000"><?php _e('Plagiarism corrected', self::TEXTDOMAIN); ?></a>
			<?php } ?>
				</div>
			</li>
			<?php
			}
		}
	?></ul>
		</div>
	</div>
	<?php
			}
		}
		else {
			echo "<br /><h3>";
			_e('No posts matching found.', self::TEXTDOMAIN);
			echo "</h3><br />";
		}
	}

} // Class wpPiratesSearch

} // if class_exists...



// *********************************************************************************

$wpPiratesSearch = new wpPiratesSearch();

?>