After any subversion commit from */common/class or */common/class_local,
this class_local directory can be updated by executing the following.

  $ svn up README.txt update_local.sh
  $ ./update_local.sh

What this accomplishes is the following.

  * Gets this file and the update_local.sh script from subversion.
  * Does a further svn update of local-specific PHP files.
  * Copies common PHP files from ../class/.

