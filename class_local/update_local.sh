#!/bin/sh
#	update_local.sh  - update */common/class_local
 
LOC_FILES="airavata-client-properties.ini \
           experiment_cancel.php \
           experiment_errors.php \
           experiment_status.php \
           submit_airavata.php \
           thrift_includes.php"
 
COM_FILES="jobsubmit.php \
           jobsubmit_aira.php \
           submit_gfac.php \
           submit_local.php"

# Do update from repository of local-specific files
git pull origin master

# Copy all common files from ../class/
for F in ${COM_FILES}; do cp -p ../class/$F .;done

# Show what we now have in */common/class_local
ls -l
