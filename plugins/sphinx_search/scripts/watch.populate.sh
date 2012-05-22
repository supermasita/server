#!/bin/bash
if [ -L $0 ];then
	REAL_SCRIPT=`readlink $0`
else
	REAL_SCRIPT=$0
fi
. `dirname $REAL_SCRIPT`/../../../configurations/system.ini

SERVER=$1
MAILTO="$ADMIN_CONSOLE_ADMIN_MAIL"
KP=$(pgrep -f populateFromLog.php)
MAINT=$BASE_DIR/maintenance
if [[ "X$KP" = "X" && ! -f $MAINT ]]
      then
          echo "populateFromLog.php `hostname` was restarted" | mail -s "populateFromLog.php script not found on `hostname`" $MAILTO
	  cd $APP_DIR/plugins/sphinx_search/scripts
	  php populateFromLog.php ${SERVER} >> $LOG_DIR/kaltura_sphinx_populate.log 2>&1 &
      fi

