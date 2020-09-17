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
			'home' => array (
				'description' => false,
				'url' => '',
				'tab' => 'Home',
				'administrator' => true,
			),
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
		# Start the HTML
		$html = '';
		
		# Show recordings
		$html .= $this->recordings ();
		
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function to get, cache, and resize recordings
	private function recordings ()
	{
		# Start the HTML
		$html = '';
		
		# Get the recordings
		$recordings = $this->getRecordings ();
		
		# Obtain and cache the recordings
		$recordings = $this->cacheRecordings ($recordings);
		
		# Resize the recordings
		$recordings = $this->resizeRecordings ($recordings);
		
		# Render as table
		$html .= "\n<h3>Recordings</h3>";
		$html .= "\n<p>The following recordings are available.</p>";
		$html .= "\n<p>Please note that Zoom limits the listing to the last month only.</p>";
		$headings = array ('error' => 'Error?');
		$html .= application::htmlTable ($recordings, $headings, 'lines', $keyAsFirstColumn = false, $uppercaseHeadings = true, $allowHtml = array ('linkOriginal', 'errorOriginal', 'linkResized', 'errorResized'));
		
		
		# Return the HTML
		return $html;
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
		//var_dump ($data);
		
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
			
			# Obtain the ID
			$id = $meeting['id'];
			
			# Register the recording
			$recordings[$id] = array (
				//'id'		=> $id,
				'title'		=> $meeting['topic'],
				'date'		=> date ('Y-m-d', strtotime ($meeting['start_time'])),
				'duration'	=> $meeting['duration'] . ' minutes',
				'size'		=> (int) round (($meeting['recording_files'][$fileIndex]['file_size'] / (1024*1024))) . 'MB',
				'sizeBytes'	=> $meeting['recording_files'][$fileIndex]['file_size'],
				'videoUrl'	=> $meeting['recording_files'][$fileIndex]['download_url'] . '?access_token=' . $this->jwt,
			);
		}
		
		# Return the recordings list
		return $recordings;
	}
	
	
	# Function to cache recordings
	private function cacheRecordings ($recordings)
	{
		# Ensure the data directory is writeable
		if (!is_writable ($this->dataDirectory)) {
			echo "\n<p class=\"warning\">Error: the data directory" . ($this->userIsAdministrator ? " at <tt>{$this->dataDirectory}</tt>" : '') . " is not writeable. The administrator needs to fix this.</p>";
		}
		
		# Loop through each recording
		foreach ($recordings as $id => $recording) {
			
			# Determine the file location
			$filename = $id . '-original.mp4';
			$file = $this->dataDirectory . $filename;
			
			# If the file does not exist, download it in the background
			$error = '-';
			if (!file_exists ($file)) {
				$command = "wget -q -O {$file} '{$recording['videoUrl']}' 2>&1 &";	// Backgrounded, using & ; this will start creating the file straight away so file_exists will not trigger again
				exec ($command, $output, $returnValue);
				$success = (!$returnValue);
				if (!$success) {
					$error = '<span class="warning">' . trim ($output) . '</span>';
				}
			}
			unset ($recordings[$id]['videoUrl']);
			
			# Register the result
			$recordings[$id]['filenameOriginal'] = $filename;
			$recordings[$id]['fileOriginal'] = $file;
			$recordings[$id]['linkOriginal'] = "<a href=\"{$this->baseUrl}/data/{$filename}\">{$filename}</a>";
			$recordings[$id]['errorOriginal'] = $error;
		}
		
		# Return the recordings list
		return $recordings;
	}
	
	
	# Function to resize recordings
	private function resizeRecordings ($recordings)
	{
		# Loop through each recording
		foreach ($recordings as $id => $recording) {
			
			# Determine the intended filename after resizing
			$filename = $id . '.mp4';
			$file = $this->dataDirectory . $filename;
			
			# Ensure the file is fully-downloaded
			if (filesize ($recordings[$id]['fileOriginal']) != $recording['sizeBytes']) {
				unset ($recordings[$id]['sizeBytes']);
				continue;
			}
			unset ($recordings[$id]['sizeBytes']);
			
			# Transcode the file
			# Scale :-2 handles non-divisibility by 2; see: https://stackoverflow.com/a/29582287/180733
			$command = "ffmpeg -i '{$recording['fileOriginal']}' -vcodec libx264 -crf 24 -vf scale='800:-2' '{$file}' 2>&1 &";	// Backgrounded, using &
			exec ($command, $output, $returnValue);
			$success = (!$returnValue);
			if (!$success) {
				$error = '<span class="warning">' . trim ($output) . '</span>';
			}
			unset ($recordings[$id]['fileOriginal']);
			
			# Register the result
			$recordings[$id]['filenameResized'] = $filename;
			$recordings[$id]['linkResized'] = "<a href=\"{$this->baseUrl}/data/{$filename}\">{$filename}</a>";
			$recordings[$id]['errorResized'] = $error;
			
			# Show size if the recording exists
			if (file_exists ($file)) {
				$recordings[$id]['sizeResized'] = (int) round ((filesize ($file) / (1024*1024))) . 'MB';
			}
		}
		
		# Return the recordings list
		return $recordings;
	}
	
	
	# Function to get data
	private function getData ($apiCall)
	{
		# Generate the JWT
		$this->jwt = $this->generateJWT ();
		
		# List users endpoint, e.g. GET https://api.zoom.us/v2/users
		$url = 'https://api.zoom.us/v2' . $apiCall;
		$ch = curl_init ($url);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
		
		# Add token to the authorization header
		curl_setopt ($ch, CURLOPT_HTTPHEADER, array (
			'Authorization: Bearer ' . $this->jwt
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