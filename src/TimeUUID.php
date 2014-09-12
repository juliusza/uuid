<?php
namespace Uuid;
/**
 * The goods are here: www.ietf.org/rfc/rfc4122.txt.
 */
class TimeUUID {
	// A grand day! 100's nanoseconds precision at 00:00:00.000 15 Oct 1582.
	const START_EPOCH = -122192928000000000;
	
	protected static $_semKey = 1000;
	protected static $_shmKey = 2000;

	protected static $_clockSeqKey = 1;
	protected static $_lastNanosKey = 2;

	/**
	 * 
	 * @var string
	 */
	protected static $_mac;

	/**
	 * 
	 * @var string
	 */
	protected static $_clockSeq;

	/**
	 * 
	 * @param string $mac
	 */
	public static function setMAC($mac) {
		self::$_mac = strtolower(str_replace(':', '', $mac));
	}

	/**
	 * 
	 * @param int $semKey
	 */
	public static function setSemKey($semKey) {
		self::$_semKey = $semKey;
	}

	/**
	 * 
	 * @param int $shmKey
	 */
	public static function setShmKey($shmKey) {
		self::$_shmKey = $shmKey;
	}

	protected static function _getTimeBefore($sec, $msec = null) {
		$nanos = (int)$sec * 10000000 + (isset($msec) ? (int)($msec * 10000000) : mt_rand(0, 10000000 - 1));
		return $nanos - self::START_EPOCH;
	}

	protected static function _getTimeNow() {
		list($msec, $sec) = explode(' ', microtime());
		$nanos = (int)($sec . substr($msec, 2, 7));

		$nanosSince = $nanos - self::START_EPOCH;

		$semId = sem_get(self::$_semKey);
		sem_acquire($semId); //blocking

		$shmId = shm_attach(self::$_shmKey);
		$lastNanos = shm_get_var($shmId, self::$_lastNanosKey);
		if ($lastNanos === false)
			$lastNanos = 0;

		if ($nanosSince > $lastNanos)
			$lastNanos = $nanosSince;
		else
			$nanosSince = ++$lastNanos;

		shm_put_var($shmId, self::$_lastNanosKey, $lastNanos);
		shm_detach($shmId);

		sem_release($semId);

		return $nanosSince;
	}

	/**
	 * 
	 * @param int $sec
	 * @param double $msec
	 * @return string
	 */
	public static function getTimeUUID($sec = null, $msec = null) {
		if (self::$_clockSeq === null) {
			$shmId = shm_attach(self::$_shmKey);
			self::$_clockSeq = shm_get_var($shmId, self::$_clockSeqKey);

			if (self::$_clockSeq === false) {
				$semId = sem_get(self::$_semKey);
				sem_acquire($semId); //blocking

				if (shm_has_var($shmId, self::$_clockSeqKey)) {
					self::$_clockSeq = shm_get_var($shmId, self::$_clockSeqKey);
				}
				else {
					// 0x8000 variant (2 bits)
					// clock sequence (14 bits)
					self::$_clockSeq = dechex(0x8000 | mt_rand(0, (1 << 14) - 1));
					
					shm_put_var($shmId, self::$_clockSeqKey, self::$_clockSeq);
				}

				sem_release($semId);
			}

			shm_detach($shmId);
		}

		$nanosSince = isset($sec) ? self::_getTimeBefore($sec, $msec) : self::_getTimeNow();

		return str_pad(dechex(0xffffffff & $nanosSince), 8, '0', STR_PAD_LEFT) 
			. '-' . str_pad(dechex($nanosSince >> 32 & 0xffff), 4, '0', STR_PAD_LEFT) 
			. '-' . dechex($nanosSince >> 48 & 0xffff | 0x1000) 
			. '-' . self::$_clockSeq 
			. '-' . self::$_mac;
	}
}