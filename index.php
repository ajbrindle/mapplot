<?php
// 1. Require the configuration file
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die("Error: config.php is missing. Please create it and define your Mapbox credentials.");
}

$baseDir = defined('GPX_BASE_DIR') ? GPX_BASE_DIR : 'tracks';

// 2. AJAX Endpoint: Fetch and parse GPX files
if (isset($_GET['get_tracks'])) {
    header('Content-Type: application/json');
    
    $selectedFolder = isset($_GET['folder']) ? basename($_GET['folder']) : '';
    $targetPath = $baseDir . '/' . $selectedFolder;

    if (empty($selectedFolder) || !is_dir($targetPath)) {
        echo json_encode(array('error' => 'Invalid folder selected'));
        exit;
    }

    $gpxFiles = glob($targetPath . '/*.{gpx,GPX}', GLOB_BRACE);
    $allTracks = array();

    if ($gpxFiles) {
        foreach ($gpxFiles as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml) continue; 

            $coordinates = array();
            $maxEle = -99999;
            $highestPoint = null;
            
            if (isset($xml->trk)) {
                foreach ($xml->trk as $trk) {
                    if (isset($trk->trkseg)) {
                        foreach ($trk->trkseg as $trkseg) {
                            if (isset($trkseg->trkpt)) {
                                foreach ($trkseg->trkpt as $trkpt) {
                                    $lat = (float)$trkpt['lat'];
                                    $lon = (float)$trkpt['lon'];
                                    $coordinates[] = array($lon, $lat);

                                    if (isset($trkpt->ele)) {
                                        $ele = (float)$trkpt->ele;
                                        if ($ele > $maxEle) {
                                            $maxEle = $ele;
                                            $highestPoint = array($lon, $lat);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($coordinates)) {
                $trackData = array(
                    'fileName' => basename($file),
                    'coordinates' => $coordinates
                );

                if ($highestPoint !== null) {
                    $trackData['highestPoint'] = $highestPoint;
                    $trackData['maxElevation'] = $maxEle;
                }

                $allTracks[] = $trackData;
            }
        }
    }

    echo json_encode($allTracks);
    exit;
}

// 3. Main Page Render
$subfolders = array();
if (is_dir($baseDir)) {
    $dirs = glob($baseDir . '/*', GLOB_ONLYDIR);
    if ($dirs) {
        foreach ($dirs as $dir) {
            $subfolders[] = basename($dir);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPX Route Viewer</title>
    <script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
    <link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet" />
    <style>
        body { 
            margin: 0; 
            padding: 0; 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            display: flex; 
            flex-direction: column; 
            height: 100vh; 
        }
        
        header { 
            background: #2c3e50; 
            color: #fff; 
            padding: 12px 20px; 
            z-index: 10; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.2); 
            display: flex; 
            align-items: center; 
            justify-content: flex-start; 
            gap: 20px; 
            flex-wrap: wrap; 
        }
        
        header h1 { 
            margin: 0; 
            font-size: 1.2rem; 
            white-space: nowrap; 
        }
        
        select { 
            padding: 8px 12px; 
            font-size: 1rem; 
            border-radius: 4px; 
            border: 1px solid #ccc; 
            background: #fff; 
            max-width: 250px; 
            width: 100%; 
        }
        
        .toggle-container {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        
        .toggle-container input {
            cursor: pointer;
            width: 16px;
            height: 16px;
        }

        /* Map container needs to be relative so the absolute loading overlay sits inside it */
        .map-container {
            flex: 1;
            position: relative;
            width: 100%;
        }

        #map { width: 100%; height: 100%; }
        
        .elevation-marker {
            background-color: #2c3e50;
            color: #fff;
            padding: 5px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 2px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
            white-space: nowrap;
            pointer-events: none;
        }

        /* Loading Overlay Styles */
        #loadingOverlay {
            display: none; /* Hidden by default */
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: #2c3e50;
            font-weight: bold;
        }

        .spinner {
            border: 4px solid #ccc;
            border-top: 4px solid #2c3e50;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 600px) {
            header { 
                flex-direction: column; 
                align-items: flex-start; 
                gap: 12px; 
                padding: 15px; 
            }
        }
    </style>
</head>
<body>

<header>
    <h1>GPX Route Viewer</h1>
    <select id="folderSelect">
        <option value="">-- Select a Folder --</option>
        <?php foreach ($subfolders as $folder): ?>
            <option value="<?php echo htmlspecialchars($folder); ?>"><?php echo htmlspecialchars($folder); ?></option>
        <?php endforeach; ?>
    </select>
    
    <label class="toggle-container">
        <input type="checkbox" id="toggleElevation"> Show Peak Elevations
    </label>
</header>

<div class="map-container">
    <div id="map"></div>
    
    <div id="loadingOverlay">
        <div class="spinner"></div>
        <div>Parsing GPX Files...</div>
    </div>
</div>

<script>
    const mapboxToken = '<?php echo addslashes(MAPBOX_TOKEN); ?>';
    const mapboxStyle = '<?php echo addslashes(MAPBOX_STYLE); ?>';

    mapboxgl.accessToken = mapboxToken;

    const map = new mapboxgl.Map({
        container: 'map',
        style: mapboxStyle,
        center: [-1.884, 53.401],
        zoom: 10
    });

    map.addControl(new mapboxgl.NavigationControl());

    let activeLayerIds = [];
    let activeSourceIds = [];
    let activeMarkers = []; 

    const elevationToggle = document.getElementById('toggleElevation');
    const loadingOverlay = document.getElementById('loadingOverlay');

    function getRandomColor(index, total) {
        const hue = (index * (360 / Math.max(total, 1))) % 360;
        return `hsl(${hue}, 85%, 55%)`;
    }

    function clearMap() {
        activeLayerIds.forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        activeSourceIds.forEach(id => { if (map.getSource(id)) map.removeSource(id); });
        activeMarkers.forEach(marker => marker.remove()); 
        
        activeLayerIds = [];
        activeSourceIds = [];
        activeMarkers = [];
    }

    elevationToggle.addEventListener('change', function() {
        const displayState = this.checked ? 'block' : 'none';
        activeMarkers.forEach(marker => {
            marker.getElement().style.display = displayState;
        });
    });

    document.getElementById('folderSelect').addEventListener('change', function() {
        const folder = this.value;
        clearMap();

        if (!folder) return;

        // Show the loading spinner immediately
        loadingOverlay.style.display = 'flex';

        fetch(`index.php?get_tracks=1&folder=${encodeURIComponent(folder)}`)
            .then(response => response.json())
            .then(tracks => {
                if (tracks.error || tracks.length === 0) {
                    alert(tracks.error || 'No valid GPX files found in this folder.');
                    loadingOverlay.style.display = 'none'; // Hide if there's an error
                    return;
                }

                const bounds = new mapboxgl.LngLatBounds();
                
                tracks.forEach((track, index) => {
                    const sourceId = `source-${index}`;
                    const layerId = `layer-${index}`;
                    const color = getRandomColor(index, tracks.length);

                    map.addSource(sourceId, {
                        'type': 'geojson',
                        'data': {
                            'type': 'Feature',
                            'properties': {},
                            'geometry': { 'type': 'LineString', 'coordinates': track.coordinates }
                        }
                    });

                    map.addLayer({
                        'id': layerId,
                        'type': 'line',
                        'source': sourceId,
                        'layout': { 'line-join': 'round', 'line-cap': 'round' },
                        'paint': { 'line-color': color, 'line-width': 4, 'line-opacity': 0.85 }
                    });

                    activeSourceIds.push(sourceId);
                    activeLayerIds.push(layerId);

                    track.coordinates.forEach(coord => { bounds.extend(coord); });

                    if (track.highestPoint && track.maxElevation !== undefined) {
                        const el = document.createElement('div');
                        el.className = 'elevation-marker';
                        el.style.borderColor = color;
                        el.innerHTML = `▲ ${Math.round(track.maxElevation)}m`;
                        
                        el.style.display = elevationToggle.checked ? 'block' : 'none';

                        const marker = new mapboxgl.Marker({
                            element: el,
                            anchor: 'bottom',
                            offset: [0, -5]
                        })
                        .setLngLat(track.highestPoint)
                        .addTo(map);

                        activeMarkers.push(marker);
                    }
                });

                map.fitBounds(bounds, { padding: 60, maxZoom: 14 });
                
                // Hide the spinner once rendering is complete
                loadingOverlay.style.display = 'none';
            })
            .catch(err => {
                console.error('Error fetching track data:', err);
                loadingOverlay.style.display = 'none'; // Hide if the fetch fails completely
            });
    });
</script>

</body>
</html>