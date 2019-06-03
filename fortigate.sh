#!/usr/bin/expect
#
# script to update LetsEncrypt Certificate on fortigate
# Created by Gerrit Doornenbal 
#  jan 2017 v0.1 initial release
#  jun 2019 v0.2 Updated certificate check with real date comparison
#                Option to remove ssh key after update
#
# Dependencies:
#   * certificate's created by dehydrated (Let's Encrypt)
#   * sending email with sendEmail. (http://caspian.dotconf.net/menu/Software/SendEmail/)
#
# Usage: fortigate.sh <configfile> (if not fortigate.conf)

#Load configuration file
if { [lindex $argv 0] != ""} {
	set configfile [lindex $argv 0]
} else {
	# default config file
	set configfile fortigate.conf
}
if {[file exists $configfile]} {
    source $configfile
} else { 
    send_user "Configfile $configfile does not exist. Script stopped.\n Usage: fortigate.sh <configfile>\n\n"
    exit 1
}

# Scripting vars
set prompt "#"
set timeout 2

#Check if certificate is created
if {[file exists certs/$certname/privkey.pem] == 0} {
	send_user "Certificate file certs/$certname/privkey.pem not found. script stopped.\n"
	exit 1
}
#Read ExpiryDates from certificates and compare them..
set livecertdate [exec echo | openssl s_client -showcerts -connect $host:$sslport 2>/dev/null | openssl x509 -noout -enddate | cut -d = -f 2 ]
set filecertdate [exec echo | openssl x509 -in certs/$certname/cert.pem -noout -dates | grep notAfter | cut -d = -f 2 ]
set livecertUTC [clock scan $livecertdate -format "%b %d %H:%M:%S %Y %Z" ]
set filecertUTC [clock scan $filecertdate -format "%b %d %H:%M:%S %Y %Z" ]
# format Jun 16 04:08:00 2019 GMT

if { [expr {$livecertUTC >= $filecertUTC}] } {
  send_user "Certificate EndDate ($livecertdate) is equal or newer than local cert ($filecertdate), certificate not updated.\n"
  exit
} else {
  send_user "Certificate EndDate ($livecertdate) is older than local cert, certificate will be updated!!\n"
}

#Create hashed private key (stderr info redirected to stdout as openssl outputs informational info to stderr..)
exec openssl rsa -des3 -passout pass:$certpass -in certs/$certname/privkey.pem -out certs/$certname/encrprivkey.pem 2>&1
# Open the new certificates.
set fpk [open "certs/$certname/encrprivkey.pem" r]
set priv_key [read $fpk]
set fcrt [open "certs/$certname/cert.pem" r]
set certificate [read $fcrt]
set fgcertname [clock format [clock seconds] -format {%Y%m}]

send_user "Starting to install new certificate $certname to $host\n\n"
# create log file
if { $logfile != ""} {
send_user "Starting log in $logfile\n"
log_file -noappend $logfile
}

#Login to fortinet host
spawn ssh $username@$host -p $sshport
#test rsa fingerprint
expect "(yes/no)? " { send "yes\r" }
#set timeout 10
expect "password:"
send "$password\r"
#### Start adding certificate
expect $prompt
send "config vpn certificate local\r"
expect $prompt
send "edit $fgcertname\r"
expect $prompt
send_user "set password <---password suppressed--->\r\n"
send "set password $certpass\r"
#do not show/log the password!
log_user 0
#copy private key
expect $prompt
log_user 1
send "set private-key \"$priv_key\"\r"
#copy public certificate
expect $prompt
send "set certificate \""
send -- "$certificate\"\r"
#save new certificate
expect $prompt
send "end\r"
#### set ssl-vpn certificate default
expect $prompt
send "config vpn ssl settings\r"
expect $prompt
send "set servercert $fgcertname\r"
expect $prompt
send "end\r"
#### set admin https server certificate
expect $prompt
send "config system global\r"
expect $prompt
send "unset admin-server-cert\r"
#save input
expect $prompt
send "end\r"
expect $prompt
send "config system global\r"
expect $prompt
send "set admin-server-cert $fgcertname\r"
expect $prompt
send "end\r"

#Logout after update
expect $prompt
send "exit\r"
expect eof

#close my open files
close $fpk
close $fcrt

if { $logfile != "" } {
#disable logging
log_file; 
#remove empty lines in logfile.
set tmpfile "tmp$logfile"
set in  [open $logfile r]
set out [open $tmpfile w]
set content [read $in]
regsub -all {\n\n} $content "\n" content
regsub -all {\n\n} $content "\n" content
puts $out $content
close $out
close $in
file delete -force $logfile 
file rename -force $tmpfile $logfile

#Remove current SSH host key?
if { $removekey == "yes" } {
  send_user "Host key fingerprint of $host is removed..\n"
  exec ssh-keygen -f "$env(HOME)/.ssh/known_hosts" -R "$host"
}

#Email the logging.
if { $emailto != "" && $emailfrom != "" && $emailserver != ""} {
	exec sendEmail -s $emailserver -t $emailto -u Certificate $certname on $host is renewed -o message-file=$logfile -f $emailfrom
	}
}
