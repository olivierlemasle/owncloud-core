<?php
/**
 * @author Arthur Schiwon <blizzz@owncloud.com>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author hkjolhede <hkjolhede@gmail.com>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <rmccorkell@karoshi.org.uk>
 * @author Sam Tuke <mail@samtuke.com>
 * @author scambra <sergio@entrecables.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Files\Storage;

use OC\Files\Cache\Cache;
use OC\Files\Cache\Scanner;
use OC\Files\Filesystem;
use OC\Files\Cache\Watcher;
use OCP\Files\FileNameTooLongException;
use OCP\Files\InvalidCharacterInPathException;
use OCP\Files\InvalidPathException;
use OCP\Files\ReservedWordException;

/**
 * Storage backend class for providing common filesystem operation methods
 * which are not storage-backend specific.
 *
 * \OC\Files\Storage\Common is never used directly; it is extended by all other
 * storage backends, where its methods may be overridden, and additional
 * (backend-specific) methods are defined.
 *
 * Some \OC\Files\Storage\Common methods call functions which are first defined
 * in classes which extend it, e.g. $this->stat() .
 */
abstract class Common implements Storage {

	use LocalTempFileTrait;

	protected $cache;
	protected $scanner;
	protected $watcher;
	protected $storageCache;

	protected $mountOptions = [];

	public function __construct($parameters) {
	}

	/**
	 * Remove a file or folder
	 *
	 * @param string $path
	 * @return bool
	 */
	protected function remove($path) {
		if ($this->is_dir($path)) {
			return $this->rmdir($path);
		} else if ($this->is_file($path)) {
			return $this->unlink($path);
		} else {
			return false;
		}
	}

	public function is_dir($path) {
		return $this->filetype($path) === 'dir';
	}

	public function is_file($path) {
		return $this->filetype($path) === 'file';
	}

	public function filesize($path) {
		if ($this->is_dir($path)) {
			return 0; //by definition
		} else {
			$stat = $this->stat($path);
			if (isset($stat['size'])) {
				return $stat['size'];
			} else {
				return 0;
			}
		}
	}

	public function isReadable($path) {
		// at least check whether it exists
		// subclasses might want to implement this more thoroughly
		return $this->file_exists($path);
	}

	public function isUpdatable($path) {
		// at least check whether it exists
		// subclasses might want to implement this more thoroughly
		// a non-existing file/folder isn't updatable
		return $this->file_exists($path);
	}

	public function isCreatable($path) {
		if ($this->is_dir($path) && $this->isUpdatable($path)) {
			return true;
		}
		return false;
	}

	public function isDeletable($path) {
		if ($path === '' || $path === '/') {
			return false;
		}
		$parent = dirname($path);
		return $this->isUpdatable($parent) && $this->isUpdatable($path);
	}

	public function isSharable($path) {
		if (\OC_Util::isSharingDisabledForUser()) {
			return false;
		}

		return $this->isReadable($path);
	}

	public function getPermissions($path) {
		$permissions = 0;
		if ($this->isCreatable($path)) {
			$permissions |= \OCP\Constants::PERMISSION_CREATE;
		}
		if ($this->isReadable($path)) {
			$permissions |= \OCP\Constants::PERMISSION_READ;
		}
		if ($this->isUpdatable($path)) {
			$permissions |= \OCP\Constants::PERMISSION_UPDATE;
		}
		if ($this->isDeletable($path)) {
			$permissions |= \OCP\Constants::PERMISSION_DELETE;
		}
		if ($this->isSharable($path)) {
			$permissions |= \OCP\Constants::PERMISSION_SHARE;
		}
		return $permissions;
	}

	public function filemtime($path) {
		$stat = $this->stat($path);
		if (isset($stat['mtime']) && $stat['mtime'] > 0) {
			return $stat['mtime'];
		} else {
			return 0;
		}
	}

	public function file_get_contents($path) {
		$handle = $this->fopen($path, "r");
		if (!$handle) {
			return false;
		}
		$data = stream_get_contents($handle);
		fclose($handle);
		return $data;
	}

	public function file_put_contents($path, $data) {
		$handle = $this->fopen($path, "w");
		$this->removeCachedFile($path);
		$count = fwrite($handle, $data);
		fclose($handle);
		return $count;
	}

	public function rename($path1, $path2) {
		$this->remove($path2);

		$this->removeCachedFile($path1);
		return $this->copy($path1, $path2) and $this->remove($path1);
	}

	public function copy($path1, $path2) {
		if ($this->is_dir($path1)) {
			$this->remove($path2);
			$dir = $this->opendir($path1);
			$this->mkdir($path2);
			while ($file = readdir($dir)) {
				if (!Filesystem::isIgnoredDir($file)) {
					if (!$this->copy($path1 . '/' . $file, $path2 . '/' . $file)) {
						return false;
					}
				}
			}
			closedir($dir);
			return true;
		} else {
			$source = $this->fopen($path1, 'r');
			$target = $this->fopen($path2, 'w');
			list(, $result) = \OC_Helper::streamCopy($source, $target);
			$this->removeCachedFile($path2);
			return $result;
		}
	}

	public function getMimeType($path) {
		if ($this->is_dir($path)) {
			return 'httpd/unix-directory';
		} elseif ($this->file_exists($path)) {
			return \OC_Helper::getFileNameMimeType($path);
		} else {
			return false;
		}
	}

	public function hash($type, $path, $raw = false) {
		$fh = $this->fopen($path, 'rb');
		$ctx = hash_init($type);
		hash_update_stream($ctx, $fh);
		fclose($fh);
		return hash_final($ctx, $raw);
	}

	public function search($query) {
		return $this->searchInDir($query);
	}

	public function getLocalFile($path) {
		return $this->getCachedFile($path);
	}

	public function getLocalFolder($path) {
		$baseDir = \OC_Helper::tmpFolder();
		$this->addLocalFolder($path, $baseDir);
		return $baseDir;
	}

	/**
	 * @param string $path
	 * @param string $target
	 */
	private function addLocalFolder($path, $target) {
		$dh = $this->opendir($path);
		if (is_resource($dh)) {
			while (($file = readdir($dh)) !== false) {
				if ($file !== '.' and $file !== '..') {
					if ($this->is_dir($path . '/' . $file)) {
						mkdir($target . '/' . $file);
						$this->addLocalFolder($path . '/' . $file, $target . '/' . $file);
					} else {
						$tmp = $this->toTmpFile($path . '/' . $file);
						rename($tmp, $target . '/' . $file);
					}
				}
			}
		}
	}

	/**
	 * @param string $query
	 */
	protected function searchInDir($query, $dir = '') {
		$files = array();
		$dh = $this->opendir($dir);
		if (is_resource($dh)) {
			while (($item = readdir($dh)) !== false) {
				if ($item == '.' || $item == '..') continue;
				if (strstr(strtolower($item), strtolower($query)) !== false) {
					$files[] = $dir . '/' . $item;
				}
				if ($this->is_dir($dir . '/' . $item)) {
					$files = array_merge($files, $this->searchInDir($query, $dir . '/' . $item));
				}
			}
		}
		closedir($dh);
		return $files;
	}

	/**
	 * check if a file or folder has been updated since $time
	 *
	 * The method is only used to check if the cache needs to be updated. Storage backends that don't support checking
	 * the mtime should always return false here. As a result storage implementations that always return false expect
	 * exclusive access to the backend and will not pick up files that have been added in a way that circumvents
	 * ownClouds filesystem.
	 *
	 * @param string $path
	 * @param int $time
	 * @return bool
	 */
	public function hasUpdated($path, $time) {
		return $this->filemtime($path) > $time;
	}

	public function getCache($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->cache)) {
			$this->cache = new Cache($storage);
		}
		return $this->cache;
	}

	public function getScanner($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->scanner)) {
			$this->scanner = new Scanner($storage);
		}
		return $this->scanner;
	}

	public function getWatcher($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->watcher)) {
			$this->watcher = new Watcher($storage);
			$globalPolicy = \OC::$server->getConfig()->getSystemValue('filesystem_check_changes', Watcher::CHECK_ONCE);
			$this->watcher->setPolicy((int)$this->getMountOption('filesystem_check_changes', $globalPolicy));
		}
		return $this->watcher;
	}

	public function getStorageCache($storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->storageCache)) {
			$this->storageCache = new \OC\Files\Cache\Storage($storage);
		}
		return $this->storageCache;
	}

	/**
	 * get the owner of a path
	 *
	 * @param string $path The path to get the owner
	 * @return string|false uid or false
	 */
	public function getOwner($path) {
		return \OC_User::getUser();
	}

	/**
	 * get the ETag for a file or folder
	 *
	 * @param string $path
	 * @return string|false
	 */
	public function getETag($path) {
		$ETagFunction = \OC\Connector\Sabre\Node::$ETagFunction;
		if ($ETagFunction) {
			$hash = call_user_func($ETagFunction, $path);
			return $hash;
		} else {
			return uniqid();
		}
	}

	/**
	 * clean a path, i.e. remove all redundant '.' and '..'
	 * making sure that it can't point to higher than '/'
	 *
	 * @param string $path The path to clean
	 * @return string cleaned path
	 */
	public function cleanPath($path) {
		if (strlen($path) == 0 or $path[0] != '/') {
			$path = '/' . $path;
		}

		$output = array();
		foreach (explode('/', $path) as $chunk) {
			if ($chunk == '..') {
				array_pop($output);
			} else if ($chunk == '.') {
			} else {
				$output[] = $chunk;
			}
		}
		return implode('/', $output);
	}

	public function test() {
		if ($this->stat('')) {
			return true;
		}
		return false;
	}

	/**
	 * get the free space in the storage
	 *
	 * @param string $path
	 * @return int|false
	 */
	public function free_space($path) {
		return \OCP\Files\FileInfo::SPACE_UNKNOWN;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isLocal() {
		// the common implementation returns a temporary file by
		// default, which is not local
		return false;
	}

	/**
	 * Check if the storage is an instance of $class or is a wrapper for a storage that is an instance of $class
	 *
	 * @param string $class
	 * @return bool
	 */
	public function instanceOfStorage($class) {
		return is_a($this, $class);
	}

	/**
	 * A custom storage implementation can return an url for direct download of a give file.
	 *
	 * For now the returned array can hold the parameter url - in future more attributes might follow.
	 *
	 * @param string $path
	 * @return array|false
	 */
	public function getDirectDownload($path) {
		return [];
	}

	/**
	 * @inheritdoc
	 */
	public function verifyPath($path, $fileName) {
		if (isset($fileName[255])) {
			throw new FileNameTooLongException();
		}

		// NOTE: $path will remain unverified for now
		if (\OC_Util::runningOnWindows()) {
			$this->verifyWindowsPath($fileName);
		} else {
			$this->verifyPosixPath($fileName);
		}
	}

	/**
	 * https://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
	 * @param string $fileName
	 * @throws InvalidPathException
	 */
	protected function verifyWindowsPath($fileName) {
		$fileName = trim($fileName);
		$this->scanForInvalidCharacters($fileName, "\\/<>:\"|?*");
		$reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
		if (in_array(strtoupper($fileName), $reservedNames)) {
			throw new ReservedWordException();
		}
	}

	/**
	 * @param string $fileName
	 * @throws InvalidPathException
	 */
	protected function verifyPosixPath($fileName) {
		$fileName = trim($fileName);
		$this->scanForInvalidCharacters($fileName, "\\/");
		$reservedNames = ['*'];
		if (in_array($fileName, $reservedNames)) {
			throw new ReservedWordException();
		}
	}

	/**
	 * @param string $fileName
	 * @param string $invalidChars
	 * @throws InvalidPathException
	 */
	private function scanForInvalidCharacters($fileName, $invalidChars) {
		foreach(str_split($invalidChars) as $char) {
			if (strpos($fileName, $char) !== false) {
				throw new InvalidCharacterInPathException();
			}
		}

		$sanitizedFileName = filter_var($fileName, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW);
		if($sanitizedFileName !== $fileName) {
			throw new InvalidCharacterInPathException();
		}
	}
	
	/**
	 * @param array $options
	 */
	public function setMountOptions(array $options) {
		$this->mountOptions = $options;
	}

	/**
	 * @param string $name
	 * @param mixed $default
	 * @return mixed
	 */
	public function getMountOption($name, $default = null) {
		return isset($this->mountOptions[$name]) ? $this->mountOptions[$name] : $default;
	}
	/**
	 * @param \OCP\Files\Storage $sourceStorage
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @param bool $preserveMtime
	 * @return bool
	 */
	public function copyFromStorage(\OCP\Files\Storage $sourceStorage, $sourceInternalPath, $targetInternalPath, $preserveMtime = false) {
		if ($sourceStorage->is_dir($sourceInternalPath)) {
			$dh = $sourceStorage->opendir($sourceInternalPath);
			$result = $this->mkdir($targetInternalPath);
			if (is_resource($dh)) {
				while ($result and ($file = readdir($dh)) !== false) {
					if (!Filesystem::isIgnoredDir($file)) {
						$result &= $this->copyFromStorage($sourceStorage, $sourceInternalPath . '/' . $file, $targetInternalPath . '/' . $file);
					}
				}
			}
		} else {
			$source = $sourceStorage->fopen($sourceInternalPath, 'r');
			$target = $this->fopen($targetInternalPath, 'w');
			list(, $result) = \OC_Helper::streamCopy($source, $target);
			if ($result and $preserveMtime) {
				$this->touch($targetInternalPath, $sourceStorage->filemtime($sourceInternalPath));
			}
			fclose($source);
			fclose($target);

			if (!$result) {
				// delete partially written target file
				$this->unlink($targetInternalPath);
				// delete cache entry that was created by fopen
				$this->getCache()->remove($targetInternalPath);
			}
		}
		return (bool)$result;
	}

	/**
	 * @param \OCP\Files\Storage $sourceStorage
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @return bool
	 */
	public function moveFromStorage(\OCP\Files\Storage $sourceStorage, $sourceInternalPath, $targetInternalPath) {
		$result = $this->copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath, true);
		if ($result) {
			if ($sourceStorage->is_dir($sourceInternalPath)) {
				$result &= $sourceStorage->rmdir($sourceInternalPath);
			} else {
				$result &= $sourceStorage->unlink($sourceInternalPath);
			}
		}
		return $result;
	}

	/**
	 * @inheritdoc
	 */
	public function getMetaData($path) {
		$permissions = $this->getPermissions($path);
		if (!$permissions & \OCP\Constants::PERMISSION_READ) {
			//cant read, nothing we can do
			return null;
		}

		$data = [];
		$data['mimetype'] = $this->getMimeType($path);
		$data['mtime'] = $this->filemtime($path);
		if ($data['mimetype'] == 'httpd/unix-directory') {
			$data['size'] = -1; //unknown
		} else {
			$data['size'] = $this->filesize($path);
		}
		$data['etag'] = $this->getETag($path);
		$data['storage_mtime'] = $data['mtime'];
		$data['permissions'] = $this->getPermissions($path);

		return $data;
	}
}
