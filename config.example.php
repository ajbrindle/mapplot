<?php
// config.php

// Define your Mapbox credentials here
define('MAPBOX_TOKEN', 'YOUR_MAPBOX_ACCESS_TOKEN');
define('MAPBOX_STYLE', 'YOUR_MAPBOX_STYLE_URL');
define('GPX_BASE_DIR', 'tracks'); // GPX files will be read from subdirectories of this folder. You can change this to any folder you want, but make sure it's readable by the web server and not publicly accessible if it contains sensitive data.