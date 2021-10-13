<?php

namespace Wincorex\Session;

use Nette;
use SessionHandlerInterface;


/**
 * Storing session to database.
 * Inspired by: https://github.com/JedenWeb/SessionStorage/
 */
class MysqlSessionHandler implements SessionHandlerInterface
{
	use Nette\SmartObject;

	/** @var Nette\Database\Context */
	private $context;

	/** @var string */
	private $tableName;

	/** @var boolean */
	private $jsonFormat = false;

	/** @var string */
	private $lockId;

	/** @var integer */
	private $lockTimeout = 5;

	/** @var integer */
	private $unchangedUpdateDelay = 300;


	public function __construct(Nette\Database\Context $context)
	{
		$this->context = $context;
	}


	public function setTableName($tableName)
	{
		$this->tableName = $tableName;
	}


	public function setJsonFormat($jsonFormat)
	{
		$this->jsonFormat = $jsonFormat;
	}


	public function setLockTimeout($timeout)
	{
		$this->lockTimeout = $timeout;
	}


	public function setUnchangedUpdateDelay($delay)
	{
		$this->unchangedUpdateDelay = $delay;
	}


	protected function hash($id)
	{
		return $id ? $id : null;
	}


	private function lock() {
		if ($this->lockId === null) {
			$this->lockId = $this->hash(session_id());
			if ($this->lockId) {
				while (!$this->context->query("SELECT GET_LOCK(?, ?) as `lock`", $this->lockId, $this->lockTimeout));
			}
		}
	}


	private function unlock() {
		if ($this->lockId === null) {
			return;
		}

		$this->context->query("SELECT RELEASE_LOCK(?)", $this->lockId);
		$this->lockId = null;
	}


	/**
	 * @param string $savePath
	 * @param string $name
	 * @return boolean
	 */
	public function open($savePath, $name)
	{
		$this->lock();
		return TRUE;
	}


	public function close()
	{
		$this->unlock();
		return TRUE;
	}


	/**
	 * @param string $sessionId
	 * @return boolean
	 */
	public function destroy($sessionId)
	{
		$hashedSessionId = $this->hash($sessionId);
		$this->context->table($this->tableName)->where('id', $hashedSessionId)->delete();
		$this->unlock();
		return TRUE;
	}


	/**
	 * @param string $sessionId
	 * @return string
	 */
	public function read($sessionId)
	{
		$this->lock();
		$hashedSessionId = $this->hash($sessionId);
		$row = $this->context->table($this->tableName)->get($hashedSessionId);

		if ($row) {
			if ($this->jsonFormat) {
				$sessionData = json_decode($row->data, TRUE);
				return $this->serializeSession(
					json_last_error() === JSON_ERROR_NONE ? $sessionData : array()
				);
			} else {
				return  $row->data;
			}
		}

		return '';
	}


	/**
	 * @param string $sessionId
	 * @param string $sessionData
	 * @return boolean
	 */
	public function write($sessionId, $sessionData)
	{
		$this->lock();
		$hashedSessionId = $this->hash($sessionId);
		$time = time();
		
		if ($this->jsonFormat) {
			$sessionData = json_encode(
				$this->unserializeSession($sessionData),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		}

		if ($row = $this->context->table($this->tableName)->get($hashedSessionId)) {
			if ($row->data !== $sessionData) {
				$row->update(array(
					'timestamp' => $time,
					'data' => $sessionData,
				));
			} else if ($this->unchangedUpdateDelay === 0 || $time - $row->timestamp > $this->unchangedUpdateDelay) {
				// Optimization: When data has not been changed, only update
				// the timestamp after 5 minutes.
				$row->update(array(
					'timestamp' => $time,
				));
			}
		} else {
			$this->context->table($this->tableName)->insert(array(
				'id' => $hashedSessionId,
				'timestamp' => $time,
				'data' => $sessionData,
			));
		}

		return TRUE;
	}


	/**
	 * @param integer $maxLifeTime
	 * @return boolean
	 */
	public function gc($maxLifeTime)
	{
		$maxTimestamp = time() - $maxLifeTime;

		// Try to avoid a conflict when running garbage collection simultaneously on two
		// MySQL servers at a very busy site in a master-master replication setup by
		// subtracting one tenth of $maxLifeTime (but at least one day) from $maxTimestamp
		// for each server with reasonably small ID except for the server with ID 1.
		//
		// In a typical master-master replication setup, the server IDs are 1 and 2.
		// There is no subtraction on server 1 and one day (or one tenth of $maxLifeTime)
		// subtraction on server 2.
		$serverId = $this->context->query("SELECT @@server_id as `server_id`")->fetch()->server_id;
		if ($serverId > 1 && $serverId < 10) {
			$maxTimestamp -= ($serverId - 1) * max(86400, $maxLifeTime / 10);
		}

		$this->context->table($this->tableName)
			->where('timestamp < ?', $maxTimestamp)
			->delete();

		return TRUE;
	}


	/**
	 * @see http://php.net/manual/en/function.session-decode.php#108037
	 * @param array $session_data
	 * @throws Exception
	 * @return multitype:mixed
	 */
	private function unserializeSession($session_data)
	{
		$return_data = array();
		$offset = 0;
		while ($offset < strlen($session_data)) {
			if (!strstr(substr($session_data, $offset), '|')) {
				throw new Exception('invalid data, remaining: ' . substr($session_data, $offset));
			}
			$pos = strpos($session_data, '|', $offset);
			$num = $pos - $offset;
			$varname = substr($session_data, $offset, $num);
			$offset += $num + 1;
			$data = unserialize(substr($session_data, $offset));
			$return_data[$varname] = $data;
			$offset += strlen(serialize($data));
		}
		return $return_data;
	}


	/**
	 * @see http://php.net/manual/en/function.session-encode.php#76425
	 * @param array $array
	 * @return string
	 */
	private function serializeSession(array $array)
	{
		$raw = '';
		$line = 0;
		$keys = array_keys($array);
		foreach ($keys as $key) {
			$value = $array[$key];
			$line++;

			$raw .= $key . '|';

			if (is_array($value) && isset($value['huge_recursion_blocker_we_hope'])) {
				$raw .= 'R:' . $value['huge_recursion_blocker_we_hope'] . ';';
			} else {
				$raw .= serialize($value);
			}
			$array[$key] = Array('huge_recursion_blocker_we_hope' => $line);
		}
		return $raw;
	}
}
