# AWS - SES EMail , SNS Handler for Mautic v6.0.2

# Step 1: Upload attached file ses-bounce.php to root of the mautic URL

# Step 2 : Enable access to sns-bounce.php from public
 Replace below in .htaccess
 
     <If "%{REQUEST_URI} =~ m#^/(index|upgrade/upgrade)\.php#">  

     with
 
     <If "%{REQUEST_URI} =~ m#^/(index|upgrade/upgrade|ses-bounce)\.php#">
     
# Step 3: Create user in Mautic
 Open mautic ==> gear icon ==> Create User with Full permission (optimize permissions later)
 
# Step 4 : Enable API access in Mautic
Open mautic ==> gear icon ==> Configuration ==> Api Acecess ==> Enable
                                                            ==> Basic auth access => Enable

# Step 5 : Fill the parametrs in sns-bounce.php
// edit below parameters. sns-log.txt  will be location to save logs
class Config {
    const MAUTIC_URL = "https://xxxxxxxxxx";
    const API_USERNAME = "xxxxxxxxxxx";
    const API_PASSWORD = "yyyyyyyyyy";
    const LOG_FILE = "sns-log.txt";
    const MAX_LOG_SIZE = 10 * 1024 * 1024; // 10MB
}
# Step 6 : Create SNS Topic

# Step 7 : Subscribe to the topic

# Step 8 : Verify

# Step 9 : Send Email to simulator and test



