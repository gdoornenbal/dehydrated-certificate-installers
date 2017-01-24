#!/usr/bin/expect
#
# script to update LetsEncrypt Certificate on fortimail
# Created by Gerrit Doornenbal (jan 2017) v0.1
#
# Dependencies:
#   * certificate's created by dehydrated (Let's Encrypt)
#   * sending email with sendEmail. (http://caspian.dotconf.net/menu/Software/SendEmail/)
#
# Usage: fortimail.sh <configfile> (if not fortimail.conf)

#Load configuration file
if { [lindex $argv 0] != ""} {
	set configfile [lindex $argv 0]
} else {
	# default config file
	set configfile fortimail.conf
}
if {[file exists $configfile]} {
    source $configfile
} else { 
    send_user "Configfile $configfile does not exist. Script stopped.\n Usage: fortimail.sh <configfile>\n\n"
    exit 1
}

# Scripting vars
set prompt "#"
set timeout 2

#Check if new certificate is created
if {[file exists certs/$certname/privkey.pem] == 0} {
	send_user "Certificate file certs/$certname/privkey.pem not found. script stopped.\n"
	exit 1
}
set currdate [clock format [clock seconds] -format {%Y-%m-%d}]
set fdate [exec stat certs/$certname/cert.pem | grep Modify]
set filedate [string range $fdate 8 17]

if { $filedate != $currdate } {
  send_user "Certificate $certname: timestamp $filedate not equal $currdate, certificate not updated.\n"
  exit
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
spawn ssh $username@$host -p $port
#test rsa fingerprint
expect "(yes/no)? " { send "yes\r" }
#Give password
expect "password:"
send "$password\r"
#Start adding certificate
expect $prompt
send "config system certificate local\r"
expect $prompt
send "edit test$fgcertname\r"
expect $prompt
send_user "set password <---password suppressed--->\r\n"
send "set password $certpass\r"
#do not show/log the password!
log_user 0
expect $prompt
log_user 1
#copy private key
send "set private-key \""
send -- "$priv_key\"\r"
expect $prompt
#copy public certificate
send "set certificate \""
send -- "$certificate\"\r"
#save input
expect $prompt
send "end\r"
#set certificate default
expect $prompt
send "config system global\r"
expect $prompt
send "set default-certificate $fgcertname\r"
expect $prompt
send "end\r"

#Logout after all is done
expect $prompt
send exit\r
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

#Email the logging.
if { $emailto != "" && $emailfrom != "" && $emailserver != ""} {
	exec sendEmail -s $emailserver -t $emailto -u Certificate $certname on $host is renewed -o message-file=$logfile -f $emailfrom
	}
}
