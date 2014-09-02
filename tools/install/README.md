# CASH Music Installers #
There are two main installers for the platform: a web installer for end users to
upload and run in a browser, and a command-line installer that simply sets up
database tables and a default user.

## Web Installer
The web installer (cashmusic_web_installer.php) is intended to be a single-file
download that a less technical user can upload to an empty directory on their 
server. It:

- pulls the latest source from github
- creates a /framework directory above the www root
- creates an /admin directory where the installer was uploaded 
- configures database tables
- sets up a default login for the system

The web installer is a graphical interface used by uploading then visiting:

http://yourserver.com/whereyouputit/cashmusic_web_installer.php

## Command-Line (dev) Installer
The command line installer is meant to be used along with a cloned version of 
the DIY repo. After the repo is down, run the installer using:

     php /path/to/DIY/installers/php/dev_installer.php

For Mac users running MAMP you may want to use MAMP's PHP binary instead of the
system default, like so:

     /Applications/MAMP/bin/php5.2/bin/php /path/to/DIY/installers/php/dev_installer.php

The installer will prompt for mysql or sqlite. We recommend starting with sqlite 
where you only need specify an email address to use as your admin login. (It can
be a fake address...only stored locally.)

If you choose MySQL you'll need to specify the following:

- database server (include ports for non-standard using localhost:8889)
- database name (you'll need to create an empty db first)
- database username
- database password
- main system email (for logging in to the admin)

When complete the installer will confirm success and assign a temporary password
for the email address you entered. Now point Apache (via MAMP or otherwise) to 
the top level of the repo directory and you'll be up and running.