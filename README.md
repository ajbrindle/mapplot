# GPX Route Viewer

A lightweight, responsive PHP web application that dynamically reads and visualizes multiple GPX tracks on an interactive map. Built with PHP and Mapbox GL JS, it is designed to easily map out grouped routes—perfect for visualizing cycling trips, hiking trails, or road tours.

## Features

* **Dynamic Folder Scanning:** Automatically reads subfolders to group and select different sets of routes.
* **Multi-Track Rendering:** Parses and draws multiple GPX files on the map simultaneously.
* **Algorithmic Color-Coding:** Distinctively colors each track automatically for clear visual separation.
* **Peak Elevation Markers:** Parses XML `<ele>` tags to calculate and mark the highest altitude point for each route.
* **Automatic Viewport Bounding:** Smoothly adjusts the map camera to perfectly fit all loaded tracks.
* **Responsive Design:** Optimized layout for both desktop and mobile browsers.
* **Broad Compatibility:** Core logic is fully compatible with PHP 5.6+.

## Prerequisites

* A web server running PHP 5.6 or higher (Apache, Nginx, etc.).
* A [Mapbox](https://www.mapbox.com/) account with a public Access Token and a preferred Map Style URL.

## Installation & Setup

1. **Clone the repository** to your web server directory.
2. **Securely add your credentials:** Create a file named `config.php` in the root directory (this file should be ignored by Git).
3. **Populate your map data:** Add your `.gpx` files into subdirectories within the main data folder.

### Configuration (`config.php`)

You must create a `config.php` file in the same directory as `index.php`. 

```php
<?php
// config.php

// Required: Define your Mapbox credentials
define('MAPBOX_TOKEN', 'your_public_mapbox_access_token_here');
define('MAPBOX_STYLE', 'mapbox://styles/mapbox/outdoors-v12');

// Optional: Define the base directory for your GPX files (defaults to 'tracks')
define('GPX_BASE_DIR', 'tracks');
```

**Note:** Ensure `config.php` is listed in your `.gitignore` to prevent leaking your Mapbox API keys.

### Directory Structure

By default, the application looks for a base directory named `tracks/`. Inside this directory, create subfolders for your different trips or regions. The dropdown menu will automatically populate based on these subfolders.

```text
your-project-root/
├── index.php
├── config.php           <-- You create this
└── tracks/              <-- Create this base folder
    ├── Peak_District/
    │   ├── route-1.gpx
    │   └── route-2.gpx
    └── Swiss_Alps/
        ├── day-1-adelboden.gpx
        └── day-2-zurich.gpx
```

## Usage

1. Navigate to the `index.php` file in your web browser.
2. Use the dropdown menu in the header to select a folder.
3. The application will fetch, parse, and render all valid GPX files from that folder, complete with peak elevation markers.

## Technical Notes

* The application uses standard `simplexml_load_file` to parse the GPX data directly on the server, ensuring rapid loading even on older PHP environments.
* Garbage collection is built into the frontend JavaScript to ensure map layers, sources, and HTML markers are cleanly destroyed and removed from the DOM when switching between folders.