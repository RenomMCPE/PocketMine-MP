<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\server_phar_stub;

use function clearstatcache;
use function define;
use function dirname;
use function fclose;
use function fflush;
use function file_get_contents;
use function flock;
use function fopen;
use function fwrite;
use function gzinflate;
use function getmypid;
use function hrtime;
use function ini_set;
use function is_dir;
use function is_file;
use function mkdir;
use function number_format;
use function ord;
use function putenv;
use function sprintf;
use function str_repeat;
use function str_replace;
use function stream_get_contents;
use function strlen;
use function strpos;
use function substr;
use function substr_replace;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Finds the appropriate directory to store the decompressed phar cache in, accounting for potential file name
 * collisions.
 */
function prepareCacheDirectory(string $basePath) : string{
	clearstatcache();

	$i = 0;
	do{
		$tmpPath = $basePath . '/PocketMine-MP-phar-cache.' . $i;
		$i++;
	}while(is_file($tmpPath));
	if(!@mkdir($tmpPath, 0777, true) && !is_dir($tmpPath)){
		throw new \RuntimeException("Failed to create temporary directory $tmpPath. Please ensure the disk has enough space and that the current user has permission to write to this location.");
	}

	return $tmpPath;
}

/**
 * Deletes caches left behind by previous server instances.
 * This ensures that the tmp directory doesn't get flooded by servers crashing in restart loops.
 */
function cleanupPharCache(string $tmpPath) : void{
	clearstatcache();

	/** @var string[] $matches */
	foreach(new \RegexIterator(
		new \FilesystemIterator(
			$tmpPath,
			\FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
		),
		'/(.+)\.lock$/',
		\RegexIterator::GET_MATCH
	) as $matches){
		$lockFilePath = $matches[0];
		$baseTmpPath = $matches[1];

		$file = @fopen($lockFilePath, "rb");
		if($file === false){
			//another process probably deleted the lock file already
			continue;
		}

		if(flock($file, LOCK_EX | LOCK_NB)){
			//this tmpfile is no longer in use
			flock($file, LOCK_UN);
			fclose($file);

			unlink($lockFilePath);
			@unlink($baseTmpPath . ".tar"); //may not exist if a previous attempt failed mid-conversion
			@unlink($baseTmpPath);
			echo "Deleted stale phar cache at $baseTmpPath\n";
		}else{
			$pid = stream_get_contents($file);
			fclose($file);

			echo "Phar cache at $baseTmpPath is still in use by PID $pid\n";
		}
	}
}

/**
 * Builds a standard ustar header block for the given path, using the prefix field for paths longer than 100 bytes.
 */
function buildTarHeader(string $name, int $size, string $typeflag, int $mtime) : string{
	$prefix = "";
	if(strlen($name) > 100){
		//ustar prefix split: find a slash such that the prefix fits in 155 bytes and the suffix fits in 100 bytes
		$min = strlen($name) - 101; //the suffix must not exceed 100 bytes
		$limit = min(155, strlen($name) - 2);
		$candidate = false;
		for($i = $limit; $i >= $min && $i >= 0; --$i){
			if($name[$i] === "/"){
				$candidate = $i;
				break;
			}
		}
		if($candidate === false){
			throw new \RuntimeException("Path $name is too long to be stored in a tar archive");
		}
		$prefix = substr($name, 0, $candidate);
		$name = substr($name, $candidate + 1);
	}
	$header = pack(
		"a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
		$name,
		$typeflag === "5" ? "0000755" : "0000644",
		"0000000",
		"0000000",
		sprintf("%011o", $size),
		sprintf("%011o", $mtime),
		"        ", //checksum placeholder
		$typeflag,
		"",
		"ustar", //magic, NUL-padded
		"00",
		"",
		"",
		"",
		"",
		$prefix,
		""
	);
	$checksum = 0;
	for($i = 0; $i < 512; ++$i){
		$checksum += ord($header[$i]);
	}
	return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
}

/**
 * Parses the phar manifest and returns every file entry as [name, decompressed size, compressed size, flags, data offset].
 * We parse the format manually instead of using the Phar class, because reading a compressed entry through the
 * phar:// stream requires a temporary file in the system temp directory, which some hosting environments don't have
 * enough space for. Parsing the bytes directly needs no temporary space at all.
 *
 * @phpstan-return list<array{name: string, size: int, csize: int, flags: int, offset: int}>
 */
function readPharEntries(string $pharPath, string $raw) : array{
	//the marker is assembled from pieces so this source code can't match itself when scanning the phar
	$haltMarker = "__HALT_" . "COMPILER();";
	$halt = strpos($raw, $haltMarker);
	if($halt === false){
		throw new \RuntimeException("Not a valid phar archive");
	}
	$pos = $halt + strlen($haltMarker);
	//Phar::setStub() appends a closing tag and a newline after the marker when the stub doesn't end with one
	$rawLength = strlen($raw);
	while($pos < $rawLength){
		if($raw[$pos] === "\r" || $raw[$pos] === "\n" || $raw[$pos] === " "){
			++$pos;
		}elseif(substr($raw, $pos, 2) === "?" . ">"){
			$pos += 2;
		}else{
			break;
		}
	}
	$manifestLength = unpack("V", substr($raw, $pos, 4))[1];
	if($manifestLength <= 0 || $manifestLength > strlen($raw)){
		throw new \RuntimeException("Failed to locate the phar manifest");
	}
	$pos += 4;
	$dataStart = $pos + $manifestLength;
	$entryCount = unpack("V", substr($raw, $pos, 4))[1];
	$pos += 4 + 2 + 4; //API version, global flags
	$aliasLength = unpack("V", substr($raw, $pos, 4))[1];
	$pos += 4 + $aliasLength;
	$metadataLength = unpack("V", substr($raw, $pos, 4))[1];
	$pos += 4 + $metadataLength;

	$entries = [];
	$dataPointer = $dataStart;
	for($i = 0; $i < $entryCount; ++$i){
		$nameLength = unpack("V", substr($raw, $pos, 4))[1];
		$pos += 4;
		$name = substr($raw, $pos, $nameLength);
		$pos += $nameLength;
		["size" => $size, "csize" => $csize, "flags" => $flags, "metaLen" => $metaLen] = unpack("Vsize/Vtimestamp/Vcsize/Vcrc32/Vflags/VmetaLen", substr($raw, $pos, 24));
		$pos += 24 + $metaLen;
		$entries[] = ["name" => $name, "size" => $size, "csize" => $csize, "flags" => $flags, "offset" => $dataPointer];
		$dataPointer += $csize;
	}

	return $entries;
}

/**
 * Writes a plain uncompressed .tar archive of the given phar's contents.
 * We don't use Phar::convertToData() because both it and phar:// stream reads decompress compressed entries through
 * temporary files in the system temp directory, which some hosting environments don't have enough space for.
 * Parsing the phar bytes directly and writing the tar ourselves only needs space for the tar itself, wherever we
 * choose to put it.
 */
function writePharAsTar(string $pharPath, string $tarPath) : void{
	$raw = file_get_contents($pharPath);
	if($raw === false){
		throw new \RuntimeException("Failed to read the phar file");
	}
	$entries = readPharEntries($pharPath, $raw);

	$dirs = [];
	foreach($entries as $entry){
		for($pos = strpos($entry["name"], "/"); $pos !== false; $pos = strpos($entry["name"], "/", $pos + 1)){
			$dirs[substr($entry["name"], 0, $pos)] = true;
		}
	}

	$out = fopen($tarPath, "wb");
	if($out === false){
		throw new \RuntimeException("Failed to create $tarPath");
	}
	try{
		$mtime = time();
		foreach(array_keys($dirs) as $dir){
			fwrite($out, buildTarHeader($dir . "/", 0, "5", $mtime));
		}
		foreach($entries as $entry){
			fwrite($out, buildTarHeader($entry["name"], $entry["size"], "0", $mtime));
			$content = substr($raw, $entry["offset"], $entry["csize"]);
			if(($entry["flags"] & 0x2000) !== 0){
				throw new \RuntimeException("BZIP2 compressed phar entries are not supported");
			}
			if(($entry["flags"] & 0x1000) !== 0){
				$content = gzinflate($content);
			}
			if($content === false || strlen($content) !== $entry["size"]){
				throw new \RuntimeException("Failed to decompress " . $entry["name"] . " from the phar");
			}
			fwrite($out, $content);
			$pad = (512 - ($entry["size"] % 512)) % 512;
			if($pad > 0){
				fwrite($out, str_repeat("\0", $pad));
			}
		}
		fwrite($out, str_repeat("\0", 1024)); //end of archive marker
	}finally{
		fclose($out);
	}
}

/**
 * Locks a phar tmp cache to prevent it from being deleted by other server instances.
 * This code looks similar to Filesystem::createLockFile(), but we can't use that because it's inside the compressed
 * phar.
 */
function lockPharCache(string $lockFilePath) : void{
	//this static variable will keep the file(s) locked until the process ends
	static $lockFiles = [];

	$lockFile = fopen($lockFilePath, "wb");
	if($lockFile === false){
		throw new \RuntimeException("Failed to open temporary file");
	}
	flock($lockFile, LOCK_EX); //this tells other server instances not to delete this cache file
	fwrite($lockFile, (string) getmypid()); //maybe useful for debugging
	fflush($lockFile);
	$lockFiles[$lockFilePath] = $lockFile;
}

/**
 * Prepares a decompressed .tar of PocketMine-MP.phar in the system temp directory for loading code from.
 *
 * @return string path to the temporary decompressed phar (actually a .tar)
 */
function preparePharCache(string $tmpPath, string $pharPath) : string{
	clearstatcache();

	$tmpName = tempnam($tmpPath, "PMMP");
	if($tmpName === false){
		throw new \RuntimeException("Failed to create temporary file");
	}

	lockPharCache($tmpName . ".lock");
	try{
		writePharAsTar($pharPath, $tmpName . ".tar");
	}catch(\Throwable $e){
		//don't leak a partial tar into a constrained tmpfs on every failed attempt
		@unlink($tmpName . ".tar");
		throw $e;
	}
	return $tmpName . ".tar";
}

$pharPath = __FILE__;
$pharDir = dirname($pharPath);
try{
	$tmpDir = prepareCacheDirectory(sys_get_temp_dir());
}catch(\RuntimeException $e){
	//system temp directory is unwritable or doesn't exist - fall back to the phar's own directory
	echo "Could not use the system temp directory (" . $e->getMessage() . "), falling back to $pharDir instead\n";
	$tmpDir = prepareCacheDirectory($pharDir);
}
cleanupPharCache($tmpDir);
echo "Preparing PocketMine-MP.phar decompressed cache...\n";
$start = hrtime(true);
try{
	$cacheName = preparePharCache($tmpDir, $pharPath);
}catch(\Throwable $e){
	if($tmpDir !== $pharDir){
		//some hosting environments mount /tmp as a very small tmpfs, which is too small for the decompressed cache
		//even though the actual disk has plenty of space - fall back to the phar's own directory
		echo "Cache creation in the system temp directory failed (" . $e->getMessage() . "), falling back to $pharDir\n";
		//the Phar extension also creates its own temporary files in the system temp directory during conversion,
		//so redirect it into the fallback directory too (putenv alone is not enough - PHP caches the temp dir)
		ini_set("sys_temp_dir", $pharDir);
		putenv("TMPDIR=" . $pharDir);
		putenv("TMP=" . $pharDir);
		putenv("TEMP=" . $pharDir);
		$tmpDir = prepareCacheDirectory($pharDir);
		cleanupPharCache($tmpDir);
		$cacheName = preparePharCache($tmpDir, $pharPath);
	}else{
		throw $e;
	}
}
echo "Cache ready at $cacheName in " . number_format((hrtime(true) - $start) / 1e9, 2) . "s\n";

define('pocketmine\ORIGINAL_PHAR_PATH', __FILE__);
require 'phar://' . str_replace(DIRECTORY_SEPARATOR, '/', $cacheName) . '/src/PocketMine.php';
