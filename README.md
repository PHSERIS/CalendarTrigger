# CalendarTrigger
REDCap Calendar DET trigger

This is a REDCap plugin that utilizes the Data Entry Trigger (DET) functionality of REDCap
Place the code in:
<webroot>/redcap/plugins/calendar_trigger/
(ex. /var/www/html/redcap/plugins/calendar_trigger)
redcap_connect.php should be in the /redcap/ folder
(ex. /var/www/html/redcap/redcap_connect.php)

Requirements:
REDCap 6 or above
DET enabled for the project or for all projects
redcap_connect.php

Configure at least one field in your project as a Date field
Configure the

https://<your URL HERE>/redcap/plugins/calendar_trigger/index.php
as a bookmark to your project and select "Append Project ID"

Go to the bookmark in your project. You will then be allowed to add calendar triggers.


It must be used in conjunction with a data entry trigger to function in real-time. The settings for each project are stored as an encoded variable (ct) in the query string of the DET.