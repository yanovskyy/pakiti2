#!/usr/bin/php
<?php
# Copyright (c) 2008-2009, Grid PP, CERN and CESNET. All rights reserved.
# 
# Redistribution and use in source and binary forms, with or
# without modification, are permitted provided that the following
# conditions are met:
# 
#   o Redistributions of source code must retain the above
#     copyright notice, this list of conditions and the following
#     disclaimer.
#   o Redistributions in binary form must reproduce the above
#     copyright notice, this list of conditions and the following
#     disclaimer in the documentation and/or other materials
#     provided with the distribution.
# 
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND
# CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
# INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
# MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
# DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS
# BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
# EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
# TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
# ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
# OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
# OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE. 
include("../config/config.php");
include("../include/functions.php");
include_once("../include/mysql_connect.php");

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$starttime = $mtime;

$verbose = 0;
if (isset($argv[1]) && $argv[1] == "-v") $verbose = 1;

###########################################
# Store the information in DB
$sql = "SELECT 1 FROM settings WHERE name='recalculate_update_timestamp'";
if (!$row = mysql_query($sql)) {
        die("DB: Unable to get repositories update timestamp: ".mysql_error($link));
}
if (mysql_num_rows($row) > 0) {
  $sql = "UPDATE settings SET value=CURRENT_TIMESTAMP WHERE name='recalculate_update_timestamp'";
  if (!mysql_query($sql)) {
        die("DB: Unable to set set repositories update timestamp: ".mysql_error($link));
  }
} else {
  $sql = "INSERT INTO settings (name, value) VALUES ('recalculate_update_timestamp',CURRENT_TIMESTAMP)";
  if (!mysql_query($sql)) {
	  die("DB: Unable to set set repositories update timestamp: ".mysql_error($link));
  }
}



# Check if there were some changes in the repositories
$sql = "SELECT DISTINCT host.id, host.arch_id, host.type, host.host 
	FROM host";

if (!$res = mysql_query($sql)) {
        die("DB: Unable to get info about host: ".mysql_error($link));
}
$num_hosts = mysql_num_rows($res);
$i = 1;
while ($host = mysql_fetch_row($res)) {
	$host_id = $host[0];
	$arch_id = $host[1];
	$os_type = $host[2];
	
	if ($verbose) print "Processing ($i/$num_hosts) $host[4] .";
	$i++;

	# Clean CVEs for this host
	$sql = "LOCK TABLES installed_pkgs_cves WRITE, installed_pkgs WRITE, act_version READ, cves READ, cves_os READ, host READ, pkg_exception_cve READ, pkgs_exceptions READ ";
	if (!mysql_query($sql)) {
		die("DB: Unable to lock tables: ".mysql_error($link));
	}
	$sql = "DELETE FROM installed_pkgs_cves WHERE host_id=$host_id" ;
        if (!mysql_query($sql)) {
                die("DB: Unable to delete installed_pkgs_cves for host:".mysql_error($link));
        }

	$sql = "SELECT id, pkg_id, version, rel, arch FROM installed_pkgs WHERE host_id=$host_id";
	if (!$res2 = mysql_query($sql)) {
	       	die("DB: Unable to pkg info: ".mysql_error($link));
	}
	while ($pkgs = mysql_fetch_row($res2)) {
		$act_version_id = NULL;
		$installed_pkg_id = $pkgs[0];
		$pkg_id = $pkgs[1];
		$pkg_version = $pkgs[2];
		$pkg_rel = $pkgs[3];
		$pkg_arch = $pkgs[4];
	
		# Compare against CVEs
		# Get pkg version from CVEs
		$sql = "SELECT cves.id, cves.version, cves.rel
			FROM cves, cves_os, host
			WHERE cves.pkg_id=$pkg_id AND host.id=$host_id AND cves.cves_os_id=cves_os.id
				AND cves_os.os_id=host.os_id 
				AND strcmp(concat(cves.version,cves.rel), '" . $pkg_version . $pkg_rel . "') != 0";
	
		if (!$result = mysql_query($sql)) {
	               $mysql_e = mysql_error();
	               die("DB: Unable to get cves version and release: $mysql_e ... $sql");
	        }
		$cves_to_insert = array();
                while ($item = mysql_fetch_row($result)) {
			$cmp_ret = vercmp($os_type, $pkg_version, $pkg_rel, $item[1], $item[2]);
                        if ($cmp_ret < 0) {
                                array_push($cves_to_insert, $item[0]);
                                $num_of_cves += 1;
                        }
                }
	
		$cves_to_insert_sql = "";
                foreach($cves_to_insert as $cve_id) {
                        // Is there an exception?
                        $exp_sql = "SELECT 1 FROM pkg_exception_cve, pkgs_exceptions WHERE pkg_exception_cve.cve_id=$cve_id AND pkg_exception_cve.exp_id=pkgs_exceptions.id AND pkgs_exceptions.pkg_id=$pkg_id AND pkgs_exceptions.version='$pkg_version' AND pkgs_exceptions.rel='$pkg_rel' AND pkgs_exceptions.arch='$pkg_arch'";
                        if (!$res_exp = mysql_query($exp_sql)) {
                                $mysql_e = mysql_error();
                                syslog(LOG_ERR, "DB: Unable to get exception: $mysql_e ... $sql");
                        }
                        if (mysql_num_rows($res_exp) == 0) {
                                $cves_to_insert_sql .= "($host_id, $installed_pkg_id,  $cve_id),";
                                $num_of_cves += 1;
                        }
                }

                if (!empty($cves_to_insert_sql)) {
                        # Remove last comma
                        $cves_to_insert_sqla = substr($cves_to_insert_sql, 0, -1);
                        $sql = "INSERT IGNORE INTO installed_pkgs_cves (host_id, installed_pkg_id, cve_id) VALUES $cves_to_insert_sqla";
                        if (!mysql_query($sql)) {
                                $mysql_e = mysql_error();
                                syslog(LOG_ERR, "DB: Unable to add entry into installed_pkgs_cves: $mysql_e ... $sql");
                        }
                }
	}
	if ($verbose) print ". done\n";
	$sql = "UNLOCK TABLES";
	mysql_query($sql);
}

$sql = "UNLOCK TABLES" ;
if (!mysql_query($sql)) {
        die("DB: Unable to unlock tables: ".mysql_error($link));
}

mysql_close($link);

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$endtime = $mtime;
$totaltime = ($endtime - $starttime);
if ($verbose) print "Information recorded in time: $totaltime";
?>
