<?php

$files = [ // destination IPs only used in RouterOS mode
	"peter-lowe" 			=> ["240.0.0.1"],
	"mvps" 					=> ["240.0.0.2"],
	"hphosts" 				=> ["240.0.0.3"],
	"dan-pollock" 			=> ["240.0.0.4"],
	"spam404" 				=> ["240.0.0.5"],
	"malwaredomains.com" 	=> ["240.0.0.6"],
	"malwaredomainlist.com"	=> ["240.0.0.7"],
];

//download the files before merging
//dan pollock
file_put_contents("source.dan-pollock.txt", fopen("http://someonewhocares.org/hosts/hosts",'r'));
//hpHosts, partial
file_put_contents("source.hphosts.txt", fopen("https://hosts-file.net/hphosts-partial.txt",'r'));
//spam404
file_put_contents("source.spam404.txt", fopen("https://raw.githubusercontent.com/Dawsey21/Lists/master/adblock-list.txt",'r'));
//peter lowe
file_put_contents("source.peter-lowe.txt", fopen("http://pgl.yoyo.org/adservers/serverlist.php?hostformat=hosts&showintro=1&mimetype=plaintext",'r'));
//mvps
file_put_contents("source.mvps.txt", fopen("http://winhelp2002.mvps.org/hosts.txt",'r'));
//malware domains
file_put_contents("source.malwaredomains.com.txt", fopen("http://mirror1.malwaredomains.com/files/justdomains",'r'));
//malware list
file_put_contents("source.malwaredomainlist.com.txt", fopen("http://www.malwaredomainlist.com/hostslist/hosts.txt",'r'));

// Might be a bit memory-intensive/slow... not strictly necessary, as RouterOS will just display a warning on duplicates. Only applicable in RouterOS mode
define('SKIP_DUPLICATES', true);

// Seems to be faster - use integer (CRC32 hash) keys for matching duplicates, rather than strings
define('SKIP_DUPLICATES_CRC32', true);

// Only applies to RouterOS output
define('PER_FILE_LIMIT', 900000);

// Enables output of bind9 zone files instead of RouterOS scripts. Forces skip_duplicates to ON
define('BIND9_OUTPUT', false);

// Name of Bind9 "null" zone file
define('BIND9_NULL_ZONEFILE_NAME', '/etc/bind/db.null');

define('IN_PROCESS', 1);
$totalTimeStart = microtime(true);
$totalHosts = 0;
$totalFiles = 0;
$hostsList = [];

if (BIND9_OUTPUT) {
	echo "NOTE: Generating Bind9 zone output instead of RouterOS commands. Forcing SKIP_DUPLICATES to be ON";
	if (SKIP_DUPLICATES_CRC32) {
		echo " (via crc32)";
	}
	echo ".\r\n\r\n";
} else {
	echo "NOTE: Removing duplicate hosts is " . (SKIP_DUPLICATES ? "ENABLED" : "DISABLED") . (SKIP_DUPLICATES_CRC32 ? ' (via crc32)' : '') . ".\r\n\r\n";
}

foreach ($files as $type => $details) {
	list($destIp) = $details;

	$startTime = microtime(true);
	echo
		str_pad($type, 39, " ", STR_PAD_RIGHT) .
		" => " .
		str_pad($destIp, 15, " ", STR_PAD_RIGHT) .
		" ... ";

	$hosts = 0;
	$hostsInThisFile = 0;
	$fileNum = 0;
	$outputFilename = (BIND9_OUTPUT) ? "named.conf.adblock-$type" : "script.$type-$fileNum.rsc";
	$fpRead = fopen("source.$type.txt", 'rb');
	$fpWrite = fopen($outputFilename, 'wb');

	$addLn = function($name, $comment = null) use ($type, $destIp, &$fpWrite, &$hosts, &$hostsList, &$hostsInThisFile, &$fileNum) {
		if (BIND9_OUTPUT || SKIP_DUPLICATES) {
			$searchName = strtolower($name);
			if (SKIP_DUPLICATES_CRC32) {
				$searchName = crc32($searchName);
			}
			if (in_array($searchName, $hostsList)) {
				return;
			} else {
				$hostsList[] = $searchName;
			}
		}

		if (!BIND9_OUTPUT && $hostsInThisFile >= PER_FILE_LIMIT) {
			// Switch to a new file
			fclose($fpWrite);
			++$fileNum;
			$hostsInThisFile = 0;
			$fpWrite = fopen("script.$type-$fileNum.rsc", 'wb');
			fputs($fpWrite, "# Continuation...\r\n\r\n");
			fputs($fpWrite, "/ip dns static\r\n\r\n");
		}

		if (BIND9_OUTPUT) {
			if (!empty($comment)) {
				// Includes a comment
				fputs($fpWrite, sprintf(
					"zone \"%s\" { type master; notify no; file \"%s\"; }; // %s\r\n",
					$name,
					BIND9_NULL_ZONEFILE_NAME,
					addcslashes($comment, '"')
				));
			} else {
				// No comment
				fputs($fpWrite, sprintf(
					"zone \"%s\" { type master; notify no; file \"%s\"; };\r\n",
					$name,
					BIND9_NULL_ZONEFILE_NAME
				));
			}
		} else {
			if (!empty($comment)) {
				// Includes a comment
				fputs($fpWrite, sprintf(
					"add address=%s name=\"%s\" comment=\"%s\"\r\n",
					$destIp,
					$name,
					addcslashes($comment, '?"')
				));
			} else {
				// No comment
				fputs($fpWrite, sprintf(
					"add address=%s name=\"%s\"\r\n",
					$destIp,
					$name
				));
			}
		}
		++$hosts;
		++$hostsInThisFile;
	};

	include "process.$type.php";

	fclose($fpRead);
	fclose($fpWrite);

	$duration = (microtime(true) - $startTime) * 1000;
	printf("%d hosts (%.2fms) (%d files)\r\n", $hosts, $duration, ($fileNum+1));

	$totalHosts += $hosts;
	$totalFiles += ($fileNum + 1);
}

echo "\r\n";
$totalDuration = (microtime(true) - $totalTimeStart) * 1000;
printf("Total duration: %.2fms\r\n", $totalDuration);
printf("Total hosts:    %d\r\n", $totalHosts);
printf("Total files:    %d\r\n", $totalFiles);
printf("Peak RAM use:   %.2f MB\r\n", (memory_get_peak_usage(true) / (1024*1024)));
