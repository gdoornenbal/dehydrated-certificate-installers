# dehydrated-certificate-installers
Tools to install Let's Encrypt certificates which are created with [dehydrated](https://github.com/lukas2511/dehydrated).

# Fortigate
The fortigate.sh script checks if there's a new certificate (with timestamp today), adds encryption to the privatekey, and uploads it into the fortigate.

Installation: 
Copy the fortigate.conf and fortigate.sh file into the dehydrated map.

Configuration: 
Edit the fortigate.conf, set all values to your needs.

Usage:
Start `fortigate.sh`.  Default configuration file is fortigate.conf.  You can use another configuration file as commandline option.

# Fortimail
The fortimail.sh script checks if there's a new certificate (with timestamp today), adds encryption to the privatekey, and uploads it into the fortimail.

For further instructions: same as Fortigate.

# update_tlsa
This script checks and updates your TSLA DNS records (hosted by TransIP) for specified dns entry.  It's using the [TransIP API](https://www.transip.nl/transip/api/) for that.

Installation:
Copy the update_tlsa.php into the dehydrated map.
Usage:
Start the script with two commandline options:
`update_tlsa.php <dnsname> <tcp_port>`

