#!/usr/bin/php
<?php
# Script to setup/update DANE TLSA record.
# Created by Gerrit Doornenbal (jan 2017) v0.1
#
# Dependencies:
#   * certificate's created by dehydrated (Let's Encrypt)
#   * TransIP API installed.
#
# Many thanks to http://jpmens.net/2015/03/04/enabling-dane/ for the open_ssl syntax
# and of course Mark Janssen voor the inspiritaion how to use the TransIP API 
# see https://github.com/sigio/dehydrated-transip-dns-validator

# Include TransIP domainservice API
require_once('Transip/DomainService.php');
#require_once('dns.inc.php');
require_once('config.inc.php');
$ttl=3600;

# Help text
if ($argc <> 3) {
	echo "This script is used to create/update the TLSA dns records\n";
	echo "from certificates created by dehydrated (LetsEncrypt)\n";
	echo "using the TransIP API.\n\n";
	echo "usage $argv[0] <dnsname> <port>\n";
	exit(0);
}

$domain = $argv[1];     # full domain-name to work on
$port = $argv[2];       # tcp-port to work on.

$domains_pattern = implode("|", $my_domains);
$domains_pattern = str_replace(".", "\.", $domains_pattern);
$pattern = '/^((.*)\.)?(' . $domains_pattern . ')$/';

if( preg_match($pattern, $argv[1], $matches ) )
{
#  echo "Host-part: ", $matches[1], "\n";
    $subdomain = $matches[2];
    rtrim($subdomain, ".");
#  echo "Domain-part: ", $matches[2], "\n";
    $zone = $matches[3];
}
else
{
    echo "No domain-name and/or subdomain found\n";
    exit(1);
}
$entryname = "_$port._tcp.$subdomain";

# What content are we putting in?
if (file_exists("certs/$domain/cert.pem")) {
	$tlsahash = exec("openssl x509 -in certs/$domain/cert.pem -outform DER | shasum -a 256 | awk '{print $1;}'");
	$newcontent = "3 0 1 $tlsahash";
	#echo "tlsacontent = ".$newcontent."\n";
} else {
	echo "Certificate file certs/$domain/cert.pem NOT found, exiting..\n";
	exit(1);
}

# Retrieve all DNS records for the zone
$dnsEntries = Transip_DomainService::getInfo($zone)->dnsEntries;
# Check all retrieved DNS records, and 
echo "Looking for TLSA record $entryname on zone '$zone' with domain '$domain', and subdomain '$subdomain'\n";
$found = 0;
$publish = 1;
foreach ($dnsEntries as $key => $dnsEntry)
    {
        if($dnsEntry->name == "$entryname")
        {            
		    #print_r ($dnsEntries[$key]); #debugging
		    if ($dnsEntry->content == "$newcontent") { 
		        echo "This TLSA record is already published\n";
			    $publish = 0;
		    } else { 
			    echo "TLSA record found but needs to be updated\n";
		        $dnsEntries[$key] = new Transip_DnsEntry("$entryname", $ttl, "TLSA", $newcontent);
		        print_r ($dnsEntries[$key]);
		    } 
        $found++;
        }
    }
    if ( $found == 0 )
    {
        echo "Entry not found adding new one.\n";
		$dnsEntries[] = new Transip_DnsEntry("$entryname", $ttl, "TLSA", $newcontent);
		#print_r ($dnsEntries[$key+1]);
	}
if ($publish == 1) {
	try
	{
		# Commit the changes to the TransIP DNS servers
		Transip_DomainService::setDnsEntries($zone, $dnsEntries);
		echo "DNS updated with new TLSA record\n\n";
	}
	catch(SoapFault $f)
	{
		echo "DNS not updated. " . $f->getMessage() , "\n\n";
		exit(1);
	}
}

exit(0);
?>
