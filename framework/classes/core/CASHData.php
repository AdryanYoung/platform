<?php

namespace CASHMusic\Core;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHDBA;
use CASHMusic\Entities\SystemConnection;
use CASHMusic\Entities\SystemMetadata;
use CASHMusic\Entities\SystemSession;

/**
 * Data access for all Plant and Seed classes. CASHData abstracts out SESSION
 * data handling, provides a CASHDBA object as $this->db, and provides functions
 * to access metadata for all tables.
 *
 * @package platform.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * Copyright (c) 2013, CASH Music
 * Licensed under the GNU Lesser General Public License version 3.
 * See http://www.gnu.org/licenses/lgpl-3.0.html
 *
 *
 * This file is generously sponsored by John Luini and chime.com
 * jon luini and chime.com support cashmusic's efforts towards furthering
 * easy-to-use open source tools for musicians!
 *
 */
abstract class CASHData {
	protected $db = false,
			  $orm = false,
			  $cash_session_timeout = 10800,
			  $cash_session_data = null,
			  $cash_session_id = null,
			  $cache_enabled = false,
			  $cache_dir = null;

	/**
	 *
	 * DATABASE CONNECTION
	 * Create and store new CASHDBA
	 *
	 */

	/**
	 * Grabs database connection properties from /settings/cashmusic.ini.php and
	 * opens the appropriate connection
	 *
	 * @return void
	 */
	protected function connectDB() {
		$cash_db_settings = CASHSystem::getSystemSettings();

		$config = array(
			'driver'    => $cash_db_settings['driver'], // Db driver
			'host'      => $cash_db_settings['hostname'],
			'database'  => $cash_db_settings['database'],
			'username'  => $cash_db_settings['username'],
			'password'  => $cash_db_settings['password']
		);

		$connection = new \Pixie\Connection('mysql', $config);
		$this->db = new \Pixie\QueryBuilder\QueryBuilderHandler($connection);

		// piggyback the PDO to Doctrine so we're using the same connection
		$this->orm = new CASHEntity($this->db->pdo());
	}

	/**
	 *
	 * SESSION HANDLERS
	 * CASH session management. Uses a manual implementaion of cookie (session id only) and
	 * database store for persistence. Allows for multiple web servers running against a
	 * single database back-end. Also means we don't accidentally trample another app's
	 * session data.
	 *
	 */

	/**
	 * Empties (or creates empty) entries to the standard CASH session.
	 * Only resets persistent data if a current session_id is found
	 *
	 * @return boolean
	 */protected function resetSession() {
		if ($this->sessionGet('session_id','script')) {
			$session_id = $this->sessionGet('session_id','script');
			if (!$this->orm) $this->connectDB();
			
			$session = $this->orm->findWhere(SystemSession::class, 
				['session_id'=>$session_id], false, ['id'=>'DESC'], 1);

			$session->update(array(
                'data' => [],
                'expiration_date' => time() + $this->cash_session_timeout
            ));

			$GLOBALS['cashmusic_script_store'] = array();
			$this->sessionSet('session_id',$session_id,'script');
		} else {
			$GLOBALS['cashmusic_script_store'] = array();
		}
	}

	/**
	 * Sets the initial CASH session_id and cookie on the user's machine
	 *
	 * @return boolean
	 */public function startSession($force_session_id=false,$sandbox=false) {
		// if 'session_id' is already set in script store then we've already started
		// the session in this script, do not hammer the database needlessly
		$newsession = false;
		$expiration = false;
		$generate_key = false;
		$previous_session = false;
		if (!$this->orm) $this->connectDB();
		if ($force_session_id) {
			$this->sessionSet('session_id',$force_session_id,'script');
		}
		if (!$this->sessionGet('start_time','script') || $force_session_id) {
			// first make sure we have a valid session
			$current_session = $this->getAllSessionData();
			if ($current_session['persistent'] && isset($current_session['expiration_date'])) {
				// found session data, check expiration
				if ($current_session['expiration_date'] < time()) {
					$this->sessionClearAll();
					$current_session['persistent'] = false;
				}
			}
			$expiration = time() + $this->cash_session_timeout;
			$current_ip = CASHSystem::getRemoteIP();
			if ($force_session_id || $sandbox) {
				$session_id = $force_session_id;
			} else {
				$session_id = $this->getSessionID();
			}
			if ($session_id) {

                $session_exists = $this->orm->findWhere(SystemSession::class,
                    ['session_id'=>$session_id], false, ['id'=>'DESC'], 1);

				if ($session_exists) {
					// if there is an existing session that's not expired, use it
					$previous_session = array(
						'session_id' => array(
							'condition' => '=',
							'value' => $session_id
						)
					);
				}
			} else {
				// create a new session
				$newsession = true;
				$session_id = md5($current_ip['ip'] . rand(10000,99999)) . time(); // IP + random, hashed, plus timestamo
				$previous_session = false;
			}
			$session_data = array(
				'session_id' => $session_id,
				'expiration_date' => $expiration,
				'client_ip' => $current_ip['ip'],
				'client_proxy' => $current_ip['proxy']
			);
			if (!$current_session['persistent']) {
				// no existing session, set up empty data
				$session_data['data'] = array(
                    'created' => time()
                );
			}
			// set the session info
			$this->sessionSet('session_id',$session_id,'script');
			$this->sessionSet('start_time',time(),'script');

			// set the database session data
            $session = $this->orm->create(SystemSession::class, $session_data);

			if (!$sandbox && !$force_session_id) {
				// set the client-side cookie
				if (!headers_sent()) {
					// no headers yet, we can just send the cookie through
					setcookie('cashmusic_session', $session_id, $expiration, '/');
				}
			}
		} else {
			$session_id = $this->sessionGet('session_id','script');
		}

		// ERROR LOGGING
		// error_log('starting session: ' . $session_id);

		return array(
			'newsession' => $newsession,
			'expiration' => $expiration,
			'id' => $session_id
		);
	}

	/**
	 * Returns an array of all current 'persistent' and 'script' scoped data
	 *
	 * @return array
	 */public function getAllSessionData() {
		$return_array = array(
			'persistent' => false,
			'script' => false
		);
		// first add script-scope stuff if set:
		if (isset($GLOBALS['cashmusic_script_store'])) {
			$return_array['script'] = $GLOBALS['cashmusic_script_store'];
		}
		$session_id = $this->getSessionID();
		if ($session_id) {
			if (!$this->orm) $this->connectDB();

            $session = $this->orm->findWhere(SystemSession::class,
                ['session_id'=>$session_id], false, ['id'=>'DESC'], 1);

			if ($session) {
				$return_array['persistent'] = $session->date;
				$return_array['expiration_date'] = $session->expiration_date;
			}
		}
		return $return_array;
	}

	/**
	 * Returns the CASH session_id
	 *
	 * @return boolean
	 */protected function getSessionID() {
		if (!$this->sessionGet('session_id','script') && isset($_COOKIE['cashmusic_session'])) {
			$this->sessionSet('session_id',$_COOKIE['cashmusic_session'],'script');
		}
		return $this->sessionGet('session_id','script');
	}

	/**
	 * Replaces script-scoped 'cash_last_response' with a new response
	 *
	 * @param {array} $response - the new CASHResponse
	 * @param {boolean} $reset_session_id [default: false] - if true a new
	 *        session id is generated as a security measure
	 * @return boolean
	 */protected function sessionSetLastResponse($response) {
		$this->sessionSet('cash_last_response',$response,'script');
		return true;
	}

	/**
	 * Returns the current value of script-scoped 'cash_last_response'
	 *
	 * @return array|false
	 */public function sessionGetLastResponse() {
		return $this->sessionGet('cash_last_response','script');
	}

	/**
	 * Sets script-scoped 'cash_last_response' to false
	 *
	 * @return array|false
	 */public function sessionClearLastResponse() {
		$this->sessionSet('cash_last_response',false,'script');
		return true;
	}

	/**
	 * Adds new data to the CASH session — 'persistent' (db) or 'script' ($GLOBALS) scope
	 *
	 * @param {string} $key - the key to associate with the new data
	 * @param {*} $value - the data to store
	 * @return boolean
	 */public function sessionSet($key,$value,$scope='persistent') {
		if ($scope == 'persistent') {
			$session_id = $this->getSessionID();
			if ($session_id) {
				$session_data = $this->getAllSessionData();
				if (!$session_data['persistent']) {
					$this->resetSession();
					$session_data['persistent'] = array();
				}
				$session_data['persistent'][(string)$key] = $value;
				$expiration = time() + $this->cash_session_timeout;
				if (!$this->orm) $this->connectDB();

                $session = $this->orm->findWhere(SystemSession::class,
                    ['session_id'=>$session_id], false, ['id'=>'DESC'], 1);

                $session->update(array(
                    'expiration_date' => $expiration,
                    'data' => $session_data['persistent']
                ));


				return true;
				// ERROR LOGGING
				// error_log('writing ' . $key . '(' . json_encode($value) . ') to session: ' . $session_id);
			}
			return false;
		} else {
			// set scope to 'script' -- or you know, whatever
			if (!isset($GLOBALS['cashmusic_script_store'])) {
				$GLOBALS['cashmusic_script_store'] = array();
			}
			$GLOBALS['cashmusic_script_store'][(string)$key] = $value;
			return true;
		}
	}

	/**
	 * Returns data from the CASH session at either 'persistent' (db) or 'script' ($GLOBALS) scope
	 *
	 * @param {string} $key - the key associated with the requested data
	 * @return *|false
	 */public function sessionGet($key,$scope='persistent') {
		if ($scope == 'persistent') {
			$session_data = $this->getAllSessionData();
			if (isset($session_data['persistent'][(string)$key])) {

				// ERROR LOGGING
				// $session_id = $this->getSessionID();
				// error_log('reading ' . $key . '(' . json_encode($session_data['persistent'][(string)$key]) . ') from session: ' . $session_id);

				return $session_data['persistent'][(string)$key];
			} else {

				// ERROR LOGGING
				// $session_id = $this->getSessionID();
				// error_log('reading ' . $key . '(false/empty) from session: ' . $session_id);

				return false;
			}
		} else {
			if (isset($GLOBALS['cashmusic_script_store'][(string)$key])) {
				return $GLOBALS['cashmusic_script_store'][(string)$key];
			} else {
				return false;
			}
		}
	}

	/**
	 * Removes the key/value entry for a specified key
	 *
	 * @param {string} $key - the key to be removed
	 * @return void
	 */public function sessionClear($key,$scope='persistent') {
		if ($scope == 'persistent') {
			$session_data = $this->getAllSessionData();
			if (!$session_data['persistent']) {
				$this->resetSession();
			} else if (isset($session_data['persistent'][(string)$key])) {
				unset($session_data['persistent'][(string)$key]);
				$session_id = $this->getSessionID();
				$expiration = time() + $this->cash_session_timeout;

                $session = $this->orm->findWhere(SystemSession::class,
                    ['session_id'=>$session_id], false, ['id'=>'DESC'], 1);

                $session->update(['expiration_date'=>$expiration, 'data'=>$session_data['persistent']]);

			}
		} else {
			if (isset($GLOBALS['cashmusic_script_store'][(string)$key])) {
				unset($GLOBALS['cashmusic_script_store'][(string)$key]);
			}
		}
	}

	/**
	 * Reset the session in the database, expire the cookie
	 *
	 * @return void
	 */public function sessionClearAll() {
		$this->resetSession();
		// set the client-side cookie
		if (!headers_sent()) {
			// if headers have already been sent the cookie will be cleared on
			// next sessionStart()
			if (isset($_COOKIE['cashmusic_session'])) {
				setcookie('cashmusic_session', null, -1, '/');
			}
		}
	}

	/**
	 *
	 * METADATA
	 * Metadata can be applied to any table by way of a scope table (alias) and
	 * id. These functions make access available to all plants.
	 *
	 */

	public function setMetaData($scope_table_alias,$scope_table_id,$user_id,$data_key,$data_value) {
		// try to find an exact key/value match
		$selected_tag = $this->getMetaData($scope_table_alias,$scope_table_id,$user_id,$data_key,$data_value);
		if (!$selected_tag) {
			$data_key_exists = $this->getMetaData($scope_table_alias,$scope_table_id,$user_id,$data_key);
			if ($data_key == 'tag' || !$data_key_exists) {
				// no matching tag or key, so we can just create a new one

                $result = $this->orm->create(SystemMetadata::class,
                    array(
                        'scope_table_alias' => $scope_table_alias,
                        'scope_table_id' => $scope_table_id,
                        'type' => $data_key,
                        'value' => $data_value,
                        'user_id' => $user_id
                    	)
					);

			} else {
				// key already exists and isn't a tag, so we need to edit the value

				if (is_array($data_key_exists)) {
					$data_key_exists = $data_key_exists[0];
				}

				$result = $data_key_exists->update(['value'=>$data_value]);

			}
			return $result;
		} else {
			// exact match: metadata exists as requested. return true
			return $selected_tag['id'];
		}
	}

	public function getMetaData($scope_table_alias,$scope_table_id,$user_id,$data_key,$data_value=false) {
		// set up options for the query. leave off $data_value to widen the results
		// by default
		$conditions = array(
			"scope_table_alias" => $scope_table_alias,
			"scope_table_id" => $scope_table_id,
			"type" => $data_key,
			"user_id" => $user_id
		);
		// if $data_value is set, add it to the options for refined search (tags)
		if ($data_value) {
			$conditions['value'] = $data_value;
		}

        $metadata = $this->orm->findWhere(SystemMetadata::class,
            $conditions, true);

		if ($metadata) {
			if ($data_value && $data_key != 'tag') {
				// $data_value means a unique set, give direct access to array
				return $metadata[0];
			} else {
				// without $data_value set there could be multiple results (tags only)
				return $metadata;
			}
		} else {
			return false;
		}
	}

	public function removeMetaData($metadata_id) {

		$result = $this->orm->delete(SystemMetadata::class, ['id'=>$metadata_id]);

		return $result;
	}

	public function removeAllMetaData($scope_table_alias,$scope_table_id,$user_id=false,$ignore_or_match='match',$data_key=false) {
		// set table / id up front. if no user is specified it will remove ALL
		// metadata for a given table+id — used primarily when deleting the parent item

        $query = $this->db->table('system_metadata')
            ->where('scope_table_alias', $scope_table_alias)
            ->where('scope_table_id', $scope_table_id);

		if ($user_id) {
			// if a $user_id is present refine the search
			$conditions_array['user_id'] = array(
				'condition' => '=',
				'value' => $user_id
			);

			$query = $query->where('user_id', $user_id);
		}

        if ($data_key) {
            if ($ignore_or_match == 'ignore') {
                $query = $query->whereNot("type", $data_key);
            } else {
                $query = $query->where("type", $data_key);
            }
        }

        if ($query->delete()) {
		    return true;
        }

		return false;
	}

	public function getAllMetaData($scope_table_alias,$scope_table_id,$data_key=false,$ignore_or_match='match') {

		// most $data_keys will be unique per user per table+id, but tags need multiple
		// so we'll add a filter. pass 'tag' as the final option to getAllMetaData
		// to get an array of all tag rows for a single table+id

        try {
            $query = $this->db->table('system_metadata')
				->where('scope_table_alias', $scope_table_alias)
                ->where('scope_table_id', $scope_table_id);

            if ($data_key) {

                if ($ignore_or_match == 'ignore') {
                    $query = $query->whereNot("type", $data_key);
                } else {
                    $query = $query->where("type", $data_key);
				}
            }

            $metadata = $query->get();

		} catch (\Exception $e) {
        	if (CASH_DEBUG) {
        		CASHSystem::errorLog("Missing a metadata relationship on this entity model class.");
			}
        	return false;
		}

		if ($metadata) {
			$return_array = array();
			foreach ($metadata as $row) {
				if ($data_key == 'tag' && $ignore_or_match == 'match') {
					$return_array[] = $row->value;
				} else {
					if ($row->type !== 'tag') {
						$return_array[$row->type] = $row->value;
					}
				}
			}
			return $return_array;
		} else {
			return false;
		}
	}

	public function setAllMetaData($scope_table_alias,$scope_table_id,$user_id,$tags=false,$metadata=false,$delete_existing=false) {
		// also need to add $ignore_or_match='match',$data_key=false to removeAllMetaData

		if ($tags) {
			if ($delete_existing) {
				// remove all tags if delete_existing is set
				$this->removeAllMetaData($scope_table_alias,$scope_table_id,$user_id,'match','tag');
			}
			// first get current tags and remove any that are no longer in the list
			$current_tags = $this->getAllMetaData($scope_table_alias,$scope_table_id,$user_id,'match','tag');
			if ($current_tags) {
				foreach ($current_tags as $tag) {
					if (!in_array($tag, $tags)) {
						$tag_details = $this->getMetaData($scope_table_alias,$scope_table_id,$user_id,'tag',$tag);
						$tag_id = $tag_details[0]['id'];
						$this->removeMetaData($tag_id);
					}
				}
			}
			// run setMetaData on all passed tags - will edit existing tags and add new ones
			foreach ($tags as $tag) {
				$this->setMetaData($scope_table_alias,$scope_table_id,$user_id,'tag',$tag);
			}
		}
		if ($metadata) {
			if ($delete_existing) {
				// remove all non-tag metadata if delete_existing is set
				$this->removeAllMetaData($scope_table_alias,$scope_table_id,$user_id,'ignore','tag');
			}
			$current_metadata = $this->getAllMetaData($scope_table_alias,$scope_table_id,$user_id,'ignore','tag');
			if ($current_metadata) {
				foreach ($current_metadata as $key => $value) {
					if (!array_key_exists($key, $metadata)) {
						$metadata_details = $this->getMetaData($scope_table_alias,$scope_table_id,$user_id,$key,$value);
						$metadata_id = $metadata_details['id'];
						$this->removeMetaData($tag_id);
					}
				}
			}
			foreach ($metadata as $key => $value) {
				$this->setMetaData($scope_table_alias,$scope_table_id,$user_id,$key,$value);
			}
		}
	}

	/**
	 *
	 * FEED/DATA CACHE STUFF
	 * Functions to read and write data to file — useful both for raw data and
	 * structured JSON. Primarily used for feeds from API scrapes, etc.
	 *
	 */

	/**
	 * Readies the basic file cache for JSON/feed caching — essentially just tests
	 * to ensure that the cache directory exists and is writeable. primeCache() will
	 * set $this->cache_enabled true on success.
	 *
	 * @return void
	 */public function primeCache($cache_dir=false) {
	 	if (!$this->cache_enabled) {
			if (!$cache_dir) {
				$cache_dir = CASH_PLATFORM_ROOT.'/cache';
			}
			if (file_exists($cache_dir)) {
				$this->cache_dir = $cache_dir;
				if (is_writable($cache_dir) && is_readable($cache_dir)) {
					$this->cache_enabled = true;
				}
			} else {
				if (mkdir($cache_dir)) {
					$this->cache_dir = $cache_dir;
					$this->cache_enabled = true;
				}
			}
		}
	}

	/**
	 * Sets the contents of a given cache file. Setting $encode will tell it to
	 * encode the data as JSON or not.
	 *
	 * @return string or decoded JSON object/array
	 */public function setCacheData($cache_name, $data_name, $data, $encode=true) {
	 	$this->primeCache();
		if ($this->cache_enabled) {
			if ($encode) {
				$payload = json_encode($data);
				$file_extension = '.json';
			} else {
				$payload = $data;
				$file_extension = '.utf8';
			}
			$datafile = $this->cache_dir . '/' . $cache_name . '/' . $data_name . $file_extension;
			if (!file_exists($this->cache_dir . '/' . $cache_name)) {
				mkdir($this->cache_dir . '/' . $cache_name, 0777, true);
			}
			$success = file_put_contents($datafile, $payload);
			return $success;
		} else {
			return false;
		}
	}

	/**
	 * Gets the contents of a given cache file. If $force_last is set it will
	 * ignore expiry state and simply return the data in the file regardless.
	 * Setting $decode will tell it to parse the data as JSON or not.
	 *
	 * @return string or decoded JSON object/array
	 */public function getCacheData($cache_name, $data_name, $force_last=false, $decode=true, $associative=true) {
	 	$this->primeCache();
		if ($decode) {
			$file_extension = '.json';
		} else {
			$file_extension = '.utf8';
		}
		$datafile = $this->cache_dir . '/' . $cache_name . '/' . $data_name . $file_extension;
		if ($this->cache_enabled && file_exists($datafile)) {
			if ($force_last || $this->getCacheExpirationFor($datafile) >= 0) {
				if ($decode) {
					return json_decode(@file_get_contents($datafile),$associative);
				} else {
					return @file_get_contents($datafile);
				}
			}
		} else {
			return false;
		}
	}

	/**
	 * Tests whether a given set of data has expired based on the passed duration.
	 *
	 * @return int (remaining time in seconds) or false
	 */private function getCacheExpirationFor($datafile, $cache_duration=600) {
		$expiration = @filemtime($datafile) + $cache_duration;
		if ($expiration) {
			$remaining = $expiration - time();
			return $remaining;
		} else {
			return false;
		}
	}

	/**
	 * Takes a cache name, data name, and URL — first looks for viable cache data,
	 * then
	 *
	 * @return int (remaining time in seconds) or false
	 */public function getCachedURL($cache_name, $data_name, $data_url, $format='json', $decode=true) {
		$url_contents = $this->getCacheData($cache_name,$data_name,false,$decode);
		if (!$url_contents) {
			$url_contents = CASHSystem::getURLContents($data_url);
			if (!$url_contents) {
				$url_contents = $this->getCacheData($cache_name,$data_name,true,$decode);
			} else {
				if ($format == 'json') {
					$url_contents = json_decode($url_contents, true);
				}
				$this->setCacheData($cache_name,$data_name,$url_contents);
			}
		}
		return $url_contents;
	}

	/**
	 *
	 * CONNECTIONS STUFF
	 * Get more info about third-party connections
	 *
	 */

	/**
	 * Returns connection type for connection_id
	 *
	 * @return string or false
	 */
	public function getConnectionDetails($connection_id) {

	 	if ($connection = $this->orm->find(SystemConnection::class, $connection_id)) {
	 		return $connection;
		}

		return false;
	}

	/**
	 * Returns connection type for connection_id
	 *
	 * @return string or false
	 */protected function getConnectionType($connection_id) {
		$result = $this->getConnectionDetails($connection_id);
		if ($result) {
			return $result->type;
		}
    	return false;
	}


} // END class
?>
