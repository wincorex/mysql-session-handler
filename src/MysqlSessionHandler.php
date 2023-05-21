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
	private $database;

	/** @var string */
	private $tableName = 'web_session';

	/** @var bool */
	private $jsonDebug = FALSE;

	/** @var string */
	private $lockId = NULL;

	/** @var int */
	private $lockTimeout = 5;

	/** @var int */
	private $unchangedUpdateDelay = 300;


	public function __construct(Nette\Database\Context $database)
	{
		$this->database = $database;
	}


	public function setTableName($tableName)
	{
		$this->tableName = $tableName;
	}


	public function setJsonDebug($jsonDebug)
	{
		$this->jsonDebug = $jsonDebug;
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
		return $id ? $id : NULL;
	}


	private function lock()
	{
		if ($this->lockId === NULL) {
			$this->lockId = $this->hash(session_id());

			// if ($this->lockId) {
				while (!$this->database->query("SELECT IS_FREE_LOCK(?name) AS `free`;", $this->lockId)->fetch()->free);
				$this->database->query("SELECT GET_LOCK(?, ?) AS `lock`;", $this->lockId, $this->lockTimeout);

				/** @noinspection PhpStatementHasEmptyBodyInspection */
   				/// while (!$this->database->query("SELECT GET_LOCK(?, ?) AS `lock`;", $this->lockId, $this->lockTimeout)->fetch()->lock);
			// }
		}
	}


	private function unlock()
	{
		if ($this->lockId === NULL) {
			return;
		}

		$this->database->query("SELECT RELEASE_LOCK(?);", $this->lockId);
		$this->lockId = NULL;
	}


	/**
	 * @param string $path
	 * @param string $name
	 * @return bool
	 */
	public function open($path, $name)
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
	 * @param string $id
	 * @return bool
	 */
	public function destroy($id)
	{
		$idHash = $this->hash($id);
		$this->database->table($this->tableName)->where('id', $idHash)->delete();
		$this->unlock();
		return TRUE;
	}


	/**
	 * @param string $id
	 * @return string
	 */
	public function read($id)
	{
		$this->lock();
		$idHash = $this->hash($id);
		$row = $this->database->table($this->tableName)->get($idHash);
		return $row ? strval($row->data) : '';
	}


	/**
	 * @param string $id
	 * @param string $data
	 * @return bool
	 */
	public function write($id, $data)
	{
		$this->lock();

		$idHash = $this->hash($id);
		$time = time();

		$dump = $this->jsonDebug
			? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			: NULL
		;

		if ($row = $this->database->table($this->tableName)->get($idHash)) {
			if ($row->data !== $data) {
				$row->update([
					'timestamp' => $time,
					'data' => $data,
					'dump' => $dump,
				]);
			}
			elseif (intval($this->unchangedUpdateDelay) === 0 || $time - $row->timestamp > $this->unchangedUpdateDelay) {
				// Optimization: When data has not been changed, only update
				// the timestamp after 5 minutes.
				$row->update([
					'timestamp' => $time,
				]);
			}
		}
		else {
			$this->database->table($this->tableName)->insert([
				'id' => $idHash,
				'timestamp' => $time,
				'data' => $data,
				'dump' => $dump,
			]);
		}

		return TRUE;
	}


	/**
	 * @param int $max_lifetime
	 * @return bool
	 */
	public function gc($max_lifetime)
	{
		$maxTimestamp = time() - $max_lifetime;

		// Try to avoid a conflict when running garbage collection simultaneously on two
		// MySQL servers at a very busy site in a master-master replication setup by
		// subtracting one tenth of $maxLifeTime (but at least one day) from $maxTimestamp
		// for each server with reasonably small ID except for the server with ID 1.
		//
		// In a typical master-master replication setup, the server IDs are 1 and 2.
		// There is no subtraction on server 1 and one day (or one tenth of $maxLifeTime)
		// subtraction on server 2.
		$serverId = $this->database->query("SELECT @@server_id AS `server_id`;")->fetch()->server_id;
		if ($serverId > 1 && $serverId < 10) {
			$maxTimestamp -= ($serverId - 1) * max(86400, $max_lifetime / 10);
		}

		$this->database->table($this->tableName)
			->where('timestamp < ?', intval($maxTimestamp))
			->delete()
		;

		return TRUE;
	}
}
