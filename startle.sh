#!/usr/bin/env bash
# Script to start Let'sEncrypt certificate updates and distribute them after.
#
# The commands are placed inside a function loop to create a log file.
# The log file can be handled after the script the way you want,
# for instance emailing..
# sending email with sendEmail. (http://caspian.dotconf.net/menu/Software/SendEmail/)
#

cd ~/dehydrated
logfile=startle.log
mailserver=smtpserver.yourdomain.com
to=youremail@yourdomain.com
#from is default hostname@yourdomain.com
from=`hostname -f | sed 's/\./@/'`

main_function() {
#Put here are all commands you want to run, these are some examples.
./dehydrated -c 
./fortigate.sh 
./update_tlsa.php  -h <host.yourdomain.com> -t 311 -p 443 
./fortimail.sh 
./update_tlsa.php -h <mail.yourdomain.com> -t 311 -p 25 
}

#Start the script, all output into logfile!
main_function 2>&1 >$logfile
#Send the email with the logfile.
sendEmail -s $mailserver -t $to -u Certificate check is done -f $from -o message-file=$logfile

