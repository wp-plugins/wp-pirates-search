<?php
/*
Plugin Name: Google
Description: Google module for WP Pirates Search plugin
Version: 1.0
Author: Andrey Khrolenok

*/

if(!class_exists('wpPiratesSearch') || !class_exists('wpPiratesSearch_SEModule'))	return;



class wppsGoogle extends wpPiratesSearch_SEModule	{

	protected const SEName	= 'Google';

	/** =====================================================================================================
	* 
	* 
	* @return 
	*/
	public function check_query($query){
		if(!function_exists('curl_init'))
			return false;

		$url = "http://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=" . urlencode($query);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_REFERER, trackback_url(false));
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		$body = curl_exec($ch);
		curl_close($ch);
		if(!function_exists('Services_JSON')) require_once(dirname(__FILE__) . '/JSON.php');
		$json = new Services_JSON();
		$json = $json->decode($body);

		$result = array();
		if(is_array($json->responseData->results)){
			foreach($json->responseData->results as $key => $resultjson){
				$result[$key] = self::make_result($query, urldecode($resultjson->url), $resultjson->content, $resultjson->cacheUrl);
			}
		}

		return $result;
	}

	/** =====================================================================================================
	* 
	* 
	* @return void
	*/
	public function options_section(){
	}
} // Class wppsGoogle



// Module name translation hook
false || __('Google', 'wp-pirates-search');

?>