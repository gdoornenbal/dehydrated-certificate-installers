#!/usr/bin/php
<?php
# Script to setup/update DANE TLSA record.
# Created by Gerrit Doornenbal 
#  jan 2017 v0.1
#     * Initial release
#  jan 2018 v0.2
#     * Added published certificate functionality.
#     * Added checks for availability of api's
#  dec 2018 v0.3
#     * Added some dependency checks
#     * removed dependencies to external bash scripts, all hashes etc
#       are now calculated directyly by openssl and shasum
#     * added 2 debug levels.
#     * Added named commandline options instead of parameters only.
#
# Dependencies:
#   * certificate's created by dehydrated (Let's Encrypt)
#   * TransIP API installed.
#
# Many thanks to http://jpmens.net/2015/03/04/enabling-dane/ for the open_ssl syntax
# and of course Mark Janssen for the inspiritation how to use the TransIP API 
# see https://github.com/sigio/dehydrated-transip-dns-cmdoptidator

#Set your ttl for your tlsa records. 
$ttlive=3600;
# Set this only to 1 when you want to publish your records in DNS!!
$publish = 1;

# Help text
function help() {
	global $argv;
	echo "This script is used to create and update TLSA dns records created by dehydrated (LetsEncrypt)\n";
	echo "or from certificates already published (https only), using the TransIP API.\n\n";
	echo "usage $argv[0] -h <fqdn> -t <type> [-p <port> -i <published ip> -d <level 0-2>]\n\n";
	echo " -h Fully Qualified Domain Name of the host certificate you want to set\n";
	echo " -t TLSA record type, eg for \"3 1 1\" set 311\n";
	echo " -p TCP port used by this service. (default 443)\n";
	echo " -i IP address of the service to request the certificate.\n    Usefull in split DNS configs where local certificate is not available.\n";
	echo " -d Debug level; default 0, level 1 showing info, level 2 also showing certificates.\n\n";
	exit(0);
}

#Get commandline options
$cmdopt = getopt("h:p:t:i:d:");
if ( array_key_exists("h",$cmdopt) ) { $domain = $cmdopt['h']; } else { help(); }     # full domain-name to work on
if ( array_key_exists("p",$cmdopt) ) { $port = $cmdopt['p'];   } else { $port="443"; } # tcp-port to work on.
if ( array_key_exists("t",$cmdopt) ) { $type = $cmdopt['t'];	 } else { help(); } 	# TLSA record type (eg 301, 311, 211 etc)
if ( array_key_exists("d",$cmdopt) ) { $debug = $cmdopt['d'];	 } else { $debug=0; } 	# Debug level to show what records are found in the transip API level 2 also shows cert info

#initiale debugging; also for php.
if ( $debug ) {
echo "Debugging is ON!\n";
print_r($cmdopt);
error_reporting(E_ALL);
ini_set('display_errors', 1);
}

	
if ( !array_key_exists("i",$cmdopt)) {
	# Get the local dehydrated Letsencrypt certificate hash..
	if ( file_exists( "config.inc.php" )) { 
		require_once('config.inc.php'); #load dehydrated configuration
		$domains_pattern = implode("|", $my_domains);
		$domains_pattern = str_replace(".", "\.", $domains_pattern);
		$pattern = '/^((.*)\.)?(' . $domains_pattern . ')$/';
		if( preg_match($pattern, $domain, $matches ) )	{
			#echo "Host-part: ", $matches[1], "\n";
			$subdomain = $matches[2];
			rtrim($subdomain, ".");
			#echo "Domain-part: ", $matches[2], "\n";
			$zone = $matches[3];
		} else {
			echo "ERROR: $domain NOT found in local dehydrated store!\n";
			exit(1);
		}
		# What content are we putting in?
		if (file_exists("certs/$domain/fullchain.pem")) {
			echo "Using local certificate file certs/$domain/fullchain.pem.\n";
			$cert_chain=file_get_contents("certs/$domain/fullchain.pem");
		} else {
			if ( $debug ) { echo "Certificate file certs/$domain/fullchain.pem NOT found, trying live certificate..\n"; }
			$ip=$domain;
		}
	} else { 
		$ip=$domain;
		echo "Let's Encrypt dehydrated not available, trying live certificate..\n";
	}
}

# Get real-life certificate when published-ip is given or local not found...
if ( array_key_exists("i",$cmdopt) or isset($ip) ) {
	if ( isset($ip) ) {
		$connect = $ip;
	} else {
		if (filter_var($cmdopt['i'], FILTER_cmdoptIDATE_IP)) {
			$connect = $cmdopt['i'];
		} else {
			echo "IP address incmdoptid, using host dns name to connect!\n";
			echo "WARNING!! When your dns is corrupted, you could publish incorrect records!!\n\n";
			$connect = $domain;
		}
	}
	if ( $port != 443 ) { 
		# for now i keep this message, not tested for smtp,pop3,imap.. it should work thoug...
		echo "When using published certificates only port 443 is supported..\n";
		exit(1);
	}
	# get published certificate hash
	$domainarray= explode(".", $domain);
	$subdomain = $domainarray[0];
	$zone = $domainarray[1].".".$domainarray[2];
	echo "Downloading published certificate chain from ".$domain.":".$port."\n";
	
	# Download certificate chain:
	$chainfile=$connect.'.pem';
	if ( $port == 25 ) { $openssl_options="-starttls smtp"; }
	if ( $port == 110 ) { $openssl_options="-starttls pop3"; }
	if ( $port == 143 ) { $openssl_options="-starttls imap"; }
	if ( $port == 443 ) { $openssl_options="-servername $domain"; } #added for SNI sites.
	$command="openssl s_client -showcerts -connect \"$connect:$port\" $openssl_options </dev/null 2>&1";
	if ( $debug ) { echo "Starting download command: $command\n"; }
	$result = shell_exec("$command") ;
	# Keep only certificate info, and skip other info from opensll
	$state="";
	$cert_chain="";
	foreach (explode("\n", $result) as $line) {
		if ( $line=="---" &&  $cert_chain!="") { 
			# That's the end of the certs...
			$state="ending";
			break;
        }
 		if ( $state=="reading" ) { 
			# Read the normal part of a cert; and accumulate to the cert_chain.
			$cert_chain=$cert_chain."\n$line";
        }
		if ( $line=="Certificate chain") { 
			# First certificate line starting next line!
			$state="reading";
        }
	}	
}	

if ( $debug == 2) { echo "result: $cert_chain\n"; }
#creating TLSA hash from certificate and hash type given!
$usage="13"; #tlsa usage field, 1 and 3 need first (CN) certificate. 
$cert="";
#Loop through certificate chain..
foreach (explode("\n", $cert_chain) as $line) { 

	if ( $cert=="" && ! strpos($line, "---BEGIN") ) {
		#skipping text lines
		continue;
	}
	$cert=$cert."\n".$line;
	if ( strpos($line, "---END") && $cert != "" ) {  #got the complete cert :-)
		if ( strpos($usage,substr($type,0,1)) ) {
			if ( $debug ) { echo "Creating tlsa hash for $domain $port $type\n"; } 
			if ( $debug == 2 ) { echo "$cert\n"; }
			#selector for part of certifcate (whole or public key only)
			if     ( substr($type,1,1)==0 ) { $extract="openssl x509 -outform DER"; }
			elseif ( substr($type,1,1)==1 ) { $extract="openssl x509 -noout -pubkey | openssl pkey -pubin -outform DER"; }
			#set matchingtype/fingerprint type
			if     ( substr($type,2,1)==0 ) { $digest = "cat"; } 
			elseif ( substr($type,2,1)==1 ) { $digest = "shasum -a 256"; } 
			elseif ( substr($type,2,1)==2 ) { $digest = "shasum -a 512"; }
			#$tlsahash = exec("openssl x509 -in certs/$domain/cert.pem -outform DER | shasum -a 256 | awk '{print $1;}'");
			$command="$extract | $digest | awk '{printf $1;}'";
			if ( $debug ) { echo "Creating TLSA using command: ".$command."\n"; }
			#put the certificate into the command doing an echo...
			$newhash= shell_exec("echo \"$cert\" | $command");
			break; #only one hash needed; skip the rest of the certificate chain
		}
		#Clean up for next (intermediate) certificate
		$cert="";
		$usage="02"; #TLSA TYPE 0 and 2 need intermediate certificate(s)
	}
}

$entryname = "_$port._tcp.$subdomain";
$tlsatype=substr($type,0,1)." ".substr($type,1,1)." ".substr($type,2,1);
$newcontent=$tlsatype." ".$newhash;

if ( $debug ) { echo "New tlsacontent = $entryname ".$newcontent."\n"; }

# Include TransIP domainservice API
if ( file_exists( "Transip/DomainService.php" )) { 
	require_once('Transip/DomainService.php');
	} else { 
	echo "Transip API not available, no automatic dns updates possible!\n";
	echo "create manually the following tlsa record:\n";
	echo "name:    ".$entryname."\n";
	echo "content: ".$newcontent."\n";
	exit(0); 
	}

# Retrieve all DNS records for the zone
$dnsEntries = Transip_DomainService::getInfo($zone)->dnsEntries;
# Check all retrieved DNS records, and 
echo "Looking for TLSA record $entryname on zone '$zone' with subdomain '$subdomain' and tlsatype $tlsatype \n";
$found = 0;
$message = "";
if ($publish == 0) { $message="Publishing is currently turned off, so this DNS update is NOT committed\n"; }
foreach ($dnsEntries as $key => $dnsEntry)
    {
        if($dnsEntry->name == "$entryname")
        {            
		    if ( substr($dnsEntry->content,0,5)==$tlsatype ) { 
			if ($debug) { print_r ($dnsEntries[$key]); } #debugging
		    if ( strtoupper($dnsEntry->content) == strtoupper($newcontent) ) { 
		        echo "This TLSA record is already published\n$message";
			    $publish = 0;
		    } else { 
			    echo "TLSA record found but needs to be updated\n$message";
		        $dnsEntries[$key] = new Transip_DnsEntry("$entryname", $ttlive, "TLSA", $newcontent);
		    } 
			$found++;
			}
        }
    }
    if ( $found == 0 )
    {
        echo "Entry not found, adding new one.\n$message";
		$dnsEntries[] = new Transip_DnsEntry("$entryname", $ttlive, "TLSA", $newcontent);
		if ($debug) { print_r ($dnsEntries[$key+1]); }
	}
if ($publish == 1) {
	try
	{
		# Commit the changes to the TransIP DNS servers
		Transip_DomainService::setDnsEntries($zone, $dnsEntries);
		echo "DNS updated with new TLSA record\n";
	}
	catch(SoapFault $f)
	{
		echo "DNS not updated. " . $f->getMessage() , "\n";
		exit(1);
	}
} 
echo "\n";
exit(0);
?>
