I integrated this from: https://github.com/ctrlcctrlv/infinity/commit/62a6dac022cb338f7b719d0c35a64ab3efc64658

In inc/captcha/config.php change the database_name database_user database_password to your own settings.

Add js/captcha.js in your secrets.php or config.php

Go to Line 305 in the /inc/config file and copy the settings in instance config, while changing the url to your website.
Go to the line beneath it if you only want to enable it when posting a new thread.
