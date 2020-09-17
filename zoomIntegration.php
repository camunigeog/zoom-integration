<?php

# Class to create a template application
require_once ('frontControllerApplication.php');

# JWT PHP Library https://github.com/firebase/php-jwt
require __DIR__ . '/vendor/autoload.php';
use \Firebase\JWT\JWT;


class zoomIntegration extends frontControllerApplication
{
	# Function to assign defaults additional to the general application defaults
	public function defaults ()
	{
		# Specify available arguments as defaults or as NULL (to represent a required argument)
		$defaults = array (
			'applicationName'		=> 'Zoom recordings',
			'div'					=> strtolower (__CLASS__),
			'tabUlClass'			=> 'tabsflat',
			'databaseStrictWhere'	=> true,
			'administrators'		=> 'administrators',
			'username'				=> 'zoomintegration',
			'database'				=> 'zoomintegration',
			'table'					=> false,
			'zoomJwtKey'			=> NULL,		// Obtain Zoom JWT API credentials from https://marketplace.zoom.us/develop/create
			'zoomJwtSecret'			=> NULL,
		);
		
		# Return the defaults
		return $defaults;
	}
	
	
	# Function to assign supported actions
	public function actions ()
	{
		# Define available actions
		$actions = array (
		);
		
		# Return the actions
		return $actions;
	}
	
	
	# Database structure definition
	public function databaseStructure ()
	{
		return "
			
			-- Administrators
			CREATE TABLE IF NOT EXISTS `administrators` (
			  `username` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Username' PRIMARY KEY,
			  `active` enum('','Yes','No') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'Yes' COMMENT 'Currently active?',
			  `privilege` enum('Administrator','Restricted administrator') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'Administrator' COMMENT 'Administrator level'
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='System administrators';
		";
	}
	
	
	
	# Additional processing
	public function main ()
	{
		
	}
	
	
	
	# Home page
	public function home ()
	{
		# Get users
		//$users = $this->getUsers ();
		//var_dump ($users);
		
		# Show recordings
		$recordings = $this->getRecordings ();
		var_dump ($recordings);
		
	}
	
	
	# Function to get users
	private function getUsers ()
	{
		$users = $this->getData ('/users');
		return $users;
	}
	
	
	# Function to get recordings
	# https://marketplace.zoom.us/docs/api-reference/zoom-api/cloud-recording/getaccountcloudrecording
	private function getRecordings ()
	{
		# Get the data
		# Note that the API only shows max 1 month of requests
		$data = $this->getData ('/accounts/me/recordings?page_size=300&from=1970-01-01');
		
		# Parse to simplified array
		$recordings = array ();
		foreach ($data['meetings'] as $meeting) {
			
			# Find the video file
			# There are multiple recording types, so file type is queried for simplicity; see recording types at: https://devforum.zoom.us/t/complete-list-of-recording-type-returned-via-webhooks/6867
			$videoFile = false;
			foreach ($meeting['recording_files'] as $fileIndex => $recording) {
				if ($recording['file_type'] == 'MP4') {
					$videoFile = $fileIndex;
					break;
				}
			}
			
			# Skip if not ready
			if ($meeting['recording_files'][$fileIndex]['status'] != 'completed') {continue;}
			
			# Register the recording
			$recordings[] = array (
				'id'		=> $meeting['id'],
				'title'		=> $meeting['topic'],
				'date'		=> date ('Y-m-d', strtotime ($meeting['start_time'])),
				'duration'	=> $meeting['duration'] . ' minutes',
				'sizeMb'	=> (int) round (($meeting['recording_files'][$fileIndex]['file_size'] / (1024*1024))),
				'videoUrl'	=> $meeting['recording_files'][$fileIndex]['download_url'],
			);
		}
		
		# Return the recordings list
		return $recordings;
	}
	
	
	# Function to get data
	private function getData ($apiCall)
	{
		# Generate the JWT
		$jwt = $this->generateJWT ();
		
		# List users endpoint, e.g. GET https://api.zoom.us/v2/users
		$url = 'https://api.zoom.us/v2' . $apiCall;
		$ch = curl_init ($url);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
		
		# Add token to the authorization header
		curl_setopt ($ch, CURLOPT_HTTPHEADER, array (
			'Authorization: Bearer ' . $jwt
		));
		
		
		# Get the data
		$response = curl_exec ($ch);
		$data = json_decode ($response, true);
		
		# Return the data
		return $data;
	}
	
	
	# Function to generate JWT
	private function generateJWT ()
	{
		$token = array (
			'iss'	=> $this->settings['zoomJwtKey'],
	        'exp'	=> time () + 60,	// The benefit of JWT is expiry tokens' we'll set this one to expire in 1 minute
		);
		return JWT::encode ($token, $this->settings['zoomJwtSecret']);
	}
}

?>