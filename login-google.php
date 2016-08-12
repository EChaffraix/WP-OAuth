<?php

include_once 'session.php';
include_once 'login-common.php';

# DEFINE THE OAUTH PROVIDER AND SETTINGS TO USE #
WPOA_Session::set_provider('Google');
define('HTTP_UTIL', get_option('wpoa_http_util'));
define('CLIENT_ENABLED', get_option('wpoa_google_api_enabled'));
define('CLIENT_ID', get_option('wpoa_google_api_id'));
define('CLIENT_SECRET', get_option('wpoa_google_api_secret'));
define('REDIRECT_URI', rtrim(site_url(), '/') . '/');
define('SCOPE', 'profile'); // PROVIDER SPECIFIC: 'profile' is the minimum scope required to get the user's id from Google
define('URL_AUTH', "https://accounts.google.com/o/oauth2/auth?");
define('URL_TOKEN', "https://accounts.google.com/o/oauth2/token?");
define('URL_USER', "https://www.googleapis.com/plus/v1/people/me?");
# END OF DEFINE THE OAUTH PROVIDER AND SETTINGS TO USE #

WPOA_Session::save_last_url();

# AUTHENTICATION FLOW #
// the oauth 2.0 authentication flow will start in this script and make several calls to the third-party authentication provider which in turn will make callbacks to this script that we continue to handle until the login completes with a success or failure:
if (!CLIENT_ENABLED) {
	$this->wpoa_end_login("This third-party authentication provider has not been enabled. Please notify the admin or try again later.");
}
elseif (!CLIENT_ID || !CLIENT_SECRET) {
	// do not proceed if id or secret is null:
	$this->wpoa_end_login("This third-party authentication provider has not been configured with an API key/secret. Please notify the admin or try again later.");
}
elseif (isset($_GET['error_description'])) {
	// do not proceed if an error was detected:
	$this->wpoa_end_login($_GET['error_description']);
}
elseif (isset($_GET['error_message'])) {
	// do not proceed if an error was detected:
	$this->wpoa_end_login($_GET['error_message']);
}
elseif (isset($_GET['code'])) {
	// post-auth phase, verify the state:
	if (WPOA_Session::get_state() == $_GET['state']) {
		// get an access token from the third party provider:
		get_oauth_token($this);
		// get the user's third-party identity and attempt to login/register a matching wordpress user account:
		$oauth_identity = get_oauth_identity($this);
		$this->wpoa_login_user($oauth_identity);
	}
	else {
		// possible CSRF attack, end the login with a generic message to the user and a detailed message to the admin/logs in case of abuse:
		// TODO: report detailed message to admin/logs here...
		$this->wpoa_end_login("Sorry, we couldn't log you in. Please notify the admin or try again later.");
	}
}
else {
	// pre-auth, start the auth process:
	if ((empty(WPOA_Session::get_expires_at())) || (time() > WPOA_Session::get_expires_at())) {
		// expired token; clear the state:
		$this->wpoa_clear_login_state();
	}
	get_oauth_code($this);
}
// we shouldn't be here, but just in case...
$this->wpoa_end_login("Sorry, we couldn't log you in. The authentication flow terminated in an unexpected way. Please notify the admin or try again later.");
# END OF AUTHENTICATION FLOW #

# AUTHENTICATION FLOW HELPER FUNCTIONS #
function get_oauth_code($wpoa) {
	$params = array(
		'response_type' => 'code',
		'client_id' => CLIENT_ID,
		'scope' => SCOPE,
		'state' => uniqid('', true),
		'redirect_uri' => REDIRECT_URI,
		'scope' => 'email',
	);
	WPOA_Session::set_state($params['state']);
	$url = URL_AUTH . http_build_query($params);
	header("Location: $url");
	exit;
}

function get_oauth_token($wpoa) {
	$params = array(
		'grant_type' => 'authorization_code',
		'client_id' => CLIENT_ID,
		'client_secret' => CLIENT_SECRET,
		'code' => $_GET['code'],
		'redirect_uri' => REDIRECT_URI,
	);
	$url_params = http_build_query($params);
	switch (strtolower(HTTP_UTIL)) {
		case 'curl':
			$url = URL_TOKEN . $url_params;
			$curl = init_curl($url);
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
			// PROVIDER NORMALIZATION: PayPal requires Accept and Accept-Language headers, Reddit requires sending a User-Agent header
			// PROVIDER NORMALIZATION: PayPal/Reddit requires sending the client id/secret via http basic authentication
			
			$result = exec_curl($curl, "google get token");
			break;
		case 'stream-context':
			$url = rtrim(URL_TOKEN, "?");
			$opts = array('http' =>
				array(
					'method'  => 'POST',
					'header'  => 'Content-type: application/x-www-form-urlencoded',
					'content' => $url_params,
				)
			);
			$context = $context  = stream_context_create($opts);
			$result = @file_get_contents($url, false, $context);
			if ($result === false) {
				$wpoa->wpoa_end_login("Sorry, we couldn't log you in. Could not retrieve access token via stream context. Please notify the admin or try again later.");
			}
			break;
	}
	// parse the result:
	$result_obj = json_decode($result, true); // PROVIDER SPECIFIC: Google encodes the access token result as json by default
	$access_token = $result_obj['access_token']; // PROVIDER SPECIFIC: this is how Google returns the access token KEEP THIS PROTECTED!
	$expires_in = $result_obj['expires_in']; // PROVIDER SPECIFIC: this is how Google returns the access token's expiration
	$expires_at = time() + $expires_in;
	// handle the result:
	if (!$access_token || !$expires_in) {
		// malformed access token result detected:
		$wpoa->wpoa_end_login("Sorry, we couldn't log you in. Malformed access token result detected. Please notify the admin or try again later.");
	}
	else {
		WPOA_Session::set_token($access_token);
		WPOA_Session::set_expires_in($expires_in);
		WPOA_Session::set_expires_at($expires_at);
		return true;
	}
}

function get_oauth_identity($wpoa) {
	// here we exchange the access token for the user info...
	// set the access token param:
	$params = array(
		'access_token' => WPOA_Session::get_token(), // PROVIDER SPECIFIC: the access_token is passed to Google via POST param
	);
	$url_params = http_build_query($params);
	// perform the http request:
	switch (strtolower(HTTP_UTIL)) {
		case 'curl':
			$url = URL_USER . $url_params; // TODO: we probably want to send this using a curl_setopt...
			$curl = init_curl($url);
			// PROVIDER NORMALIZATION: Github/Reddit require a User-Agent here...
			// PROVIDER NORMALIZATION: PayPal/Reddit require that we send the access token via a bearer header, PayPal also requires a Content-Type: application/json header, LinkedIn requires an x-li-format: json header...
			$result = exec_curl($curl, "google identity");
			$result_obj = json_decode($result, true);
			break;
		case 'stream-context':
			$url = rtrim(URL_USER, "?");
			$opts = array('http' =>
				array(
					'method'  => 'GET',
					// PROVIDER NORMALIZATION: Reddit/Github requires User-Agent here...
					'header'  => "Authorization: Bearer " . WPOA_Session::get_token() . "\r\n" . "x-li-format: json\r\n", // PROVIDER SPECIFIC: i think only LinkedIn uses x-li-format...
				)
			);
			$context = $context  = stream_context_create($opts);
			$result = @file_get_contents($url, false, $context);
			if ($result === false) {
				$wpoa->wpoa_end_login("Sorry, we couldn't log you in. Could not retrieve user identity via stream context. Please notify the admin or try again later.");
			}
			$result_obj = json_decode($result, true);
			break;
	}
	$result_obj = verify_domain($result_obj, $wpoa);
	// parse and return the user's oauth identity:
	$oauth_identity = array();
	$oauth_identity[WPOA_Session::PROVIDER] = WPOA_Session::get_provider();
	$oauth_identity[WPOA_Session::USER_ID] = $result_obj['id']; // PROVIDER SPECIFIC: Google returns the user's OAuth identity as id
	$oauth_identity[WPOA_Session::USER_NAME] = $result_obj['displayName'];
	$oauth_identity[WPOA_Session::USER_EMAIL] = $result_obj['emails'][0]['value'];
	if (!$oauth_identity[WPOA_Session::USER_ID]) {
		$wpoa->wpoa_end_login("Sorry, we couldn't log you in. User identity was not found. Please notify the admin or try again later.");
	}
	return $oauth_identity;
}

function verify_domain($base_result, $wpoa)
{
	$allowedConfig = get_option('wpoa_google_check_domain');
	if (!$allowedConfig) {
		return $base_result;
	}
	
	$allowedConfig = str_replace(" ", "", $allowedConfig);
	$key = "domain";
	$value = "Gmail";
	if (array_key_exists($key, $base_result))
	{
		$value = $base_result[$key]; 
		$allowed = explode(",", $allowedConfig);  
		if ($value && in_array($value, $allowed))
		{
			return $base_result;
		}
	}
	$wpoa->wpoa_end_login("Sorry, we couldn't log you in. Your ". $key ." is not allowed : " . $value);
	return $null;
}
# END OF AUTHENTICATION FLOW HELPER FUNCTIONS #
?>

