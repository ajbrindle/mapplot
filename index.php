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
        // Sort files by server modification time (Oldest to Newest)
        usort($gpxFiles, function($a, $b) {
            $timeA = @filemtime($a);
            $timeB = @filemtime($b);
            if ($timeA == $timeB) return 0;
            return ($timeA < $timeB) ? -1 : 1; 
        });

        foreach ($gpxFiles as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml) continue; 

            // Extract Route Name (fallback to filename without extension)
            $fallbackName = preg_replace('/\.gpx$/i', '', basename($file));
            $trackName = $fallbackName;
            
            if (isset($xml->trk) && isset($xml->trk[0]->name)) {
                $parsedName = trim((string)$xml->trk[0]->name);
                if (!empty($parsedName)) {
                    $trackName = $parsedName;
                }
            }

            $coordinates = array();
            $maxEle = -99999;
            $highestPoint = null;
            $startPoint = null;
            
            if (isset($xml->trk)) {
                foreach ($xml->trk as $trk) {
                    if (isset($trk->trkseg)) {
                        foreach ($trk->trkseg as $trkseg) {
                            if (isset($trkseg->trkpt)) {
                                foreach ($trkseg->trkpt as $trkpt) {
                                    $lat = (float)$trkpt['lat'];
                                    $lon = (float)$trkpt['lon'];
                                    
                                    if ($startPoint === null) {
                                        $startPoint = array($lon, $lat);
                                    }

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
                    'trackName' => $trackName,
                    'startPoint' => $startPoint,
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
            margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            display: flex; flex-direction: column; height: 100vh; 
        }
        
        header { 
            background: #2c3e50; color: #fff; padding: 12px 20px; z-index: 10; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.2); display: flex; align-items: center; 
            justify-content: flex-start; gap: 20px; flex-wrap: wrap; 
        }
        
        header h1 { margin: 0; font-size: 1.2rem; white-space: nowrap; }
        
        select { 
            padding: 8px 12px; font-size: 1rem; border-radius: 4px; border: 1px solid #ccc; 
            background: #fff; max-width: 250px; width: 100%; 
        }
        
        .controls-group {
            display: flex; 
            align-items: center; 
            gap: 20px; 
        }

        .toggle-container {
            display: flex; align-items: center; gap: 8px; font-size: 0.95rem; 
            cursor: pointer; user-select: none; white-space: nowrap;
        }
        
        .toggle-container input { cursor: pointer; width: 16px; height: 16px; margin: 0; }

        .map-container { flex: 1; position: relative; width: 100%; overflow: hidden; }
        #map { width: 100%; height: 100%; }
        
        /* Updated Background Opacity and Max-Height */
        #legendPanel {
            display: none; position: absolute; top: 15px; right: 15px; 
            background: rgba(255, 255, 255, 0.70); /* Frosted glass transparency */
            padding: 15px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 500;
            max-width: 300px; max-height: 25%; overflow-y: auto; color: #333; 
            backdrop-filter: blur(6px); /* Extra blur for readability */
        }
        #legendPanel h3 { margin: 0 0 10px 0; font-size: 1.1rem; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 5px; }
        .legend-item { display: flex; align-items: center; margin-bottom: 10px; font-size: 0.9rem; }
        
        .legend-number { 
            width: 22px; height: 22px; background: #333; color: #fff; border-radius: 50%;
            display: flex; justify-content: center; align-items: center; font-weight: bold;
            font-size: 0.75rem; margin-right: 10px; flex-shrink: 0; border: 2px solid;
            box-sizing: border-box; text-align: center; padding: 0;
        }
        .legend-name { word-break: break-word; line-height: 1.3; font-weight: 500; }

        .elevation-marker {
            background-color: #2c3e50; color: #fff; padding: 5px 8px; border-radius: 6px;
            font-size: 0.85rem; font-weight: 600; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.4);
            white-space: nowrap; pointer-events: none;
        }

        .start-marker {
            width: 22px; height: 22px; background-color: #fff; color: #333; border-radius: 50%;
            display: flex; justify-content: center; align-items: center; font-weight: bold;
            font-size: 0.8rem; border: 4px solid; box-shadow: 0 2px 6px rgba(0,0,0,0.4);
            pointer-events: none; box-sizing: border-box; text-align: center; padding: 0;
        }

        #loadingOverlay {
            display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255, 255, 255, 0.7); z-index: 1000; justify-content: center;
            align-items: center; flex-direction: column; color: #2c3e50; font-weight: bold;
        }

        .spinner {
            border: 4px solid #ccc; border-top: 4px solid #2c3e50; border-radius: 50%;
            width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 10px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        @media (max-width: 600px) {
            header { flex-direction: column; align-items: flex-start; gap: 12px; padding: 15px; }
            /* Capped mobile legend at 25% height as well */
            #legendPanel { top: auto; bottom: 20px; right: 10px; left: 10px; max-width: none; max-height: 25%; }
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
    
    <div class="controls-group">
        <label class="toggle-container">
            <input type="checkbox" id="toggleElevation"> Show Peak Elevations
        </label>

        <label class="toggle-container">
            <input type="checkbox" id="toggleKey"> Key
        </label>
    </div>
</header>

<div class="map-container">
    <div id="map"></div>
    
    <div id="legendPanel">
        <h3>Route Key</h3>
        <div id="legendContent"></div>
    </div>

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
    let elevationMarkers = []; 
    let startMarkers = [];

    const elevationToggle = document.getElementById('toggleElevation');
    const keyToggle = document.getElementById('toggleKey');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const legendPanel = document.getElementById('legendPanel');
    const legendContent = document.getElementById('legendContent');

    function getRandomColor(index, total) {
        const hue = (index * (360 / Math.max(total, 1))) % 360;
        return `hsl(${hue}, 85%, 55%)`;
    }

    function clearMap() {
        activeLayerIds.forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        activeSourceIds.forEach(id => { if (map.getSource(id)) map.removeSource(id); });
        elevationMarkers.forEach(marker => marker.remove()); 
        startMarkers.forEach(marker => marker.remove());
        
        activeLayerIds = [];
        activeSourceIds = [];
        elevationMarkers = [];
        startMarkers = [];
        legendContent.innerHTML = ''; 
    }

    elevationToggle.addEventListener('change', function() {
        const displayState = this.checked ? 'block' : 'none';
        elevationMarkers.forEach(marker => {
            marker.getElement().style.display = displayState;
        });
    });

    keyToggle.addEventListener('change', function() {
        legendPanel.style.display = this.checked ? 'block' : 'none';
        
        startMarkers.forEach(marker => {
            marker.getElement().style.display = this.checked ? 'flex' : 'none';
        });
    });

    document.getElementById('folderSelect').addEventListener('change', function() {
        const folder = this.value;
        clearMap();

        if (!folder) return;

        loadingOverlay.style.display = 'flex';

        fetch(`index.php?get_tracks=1&folder=${encodeURIComponent(folder)}`)
            .then(response => response.json())
            .then(tracks => {
                if (tracks.error || tracks.length === 0) {
                    alert(tracks.error || 'No valid GPX files found in this folder.');
                    loadingOverlay.style.display = 'none';
                    return;
                }

                const bounds = new mapboxgl.LngLatBounds();
                let legendHTML = '';
                
                tracks.forEach((track, index) => {
                    const sourceId = `source-${index}`;
                    const layerId = `layer-${index}`;
                    const color = getRandomColor(index, tracks.length);
                    const sequenceNumber = index + 1;

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

                    legendHTML += `
                        <div class="legend-item">
                            <div class="legend-number" style="background-color: ${color}; border-color: ${color};">${sequenceNumber}</div>
                            <div class="legend-name">${track.trackName}</div>
                        </div>
                    `;

                    if (track.startPoint) {
                        const startEl = document.createElement('div');
                        startEl.className = 'start-marker';
                        startEl.style.borderColor = color;
                        startEl.innerHTML = sequenceNumber;
                        startEl.style.display = keyToggle.checked ? 'flex' : 'none';

                        const sMarker = new mapboxgl.Marker({
                            element: startEl,
                            anchor: 'center'
                        })
                        .setLngLat(track.startPoint)
                        .addTo(map);

                        startMarkers.push(sMarker);
                    }

                    if (track.highestPoint && track.maxElevation !== undefined) {
                        const eleEl = document.createElement('div');
                        eleEl.className = 'elevation-marker';
                        eleEl.style.borderColor = color;
                        eleEl.innerHTML = `▲ ${Math.round(track.maxElevation)}m`;
                        eleEl.style.display = elevationToggle.checked ? 'block' : 'none';

                        const eMarker = new mapboxgl.Marker({
                            element: eleEl,
                            anchor: 'bottom',
                            offset: [0, -5]
                        })
                        .setLngLat(track.highestPoint)
                        .addTo(map);

                        elevationMarkers.push(eMarker);
                    }
                });

                legendContent.innerHTML = legendHTML;
                map.fitBounds(bounds, { padding: 60, maxZoom: 14 });
                loadingOverlay.style.display = 'none';
            })
            .catch(err => {
                console.error('Error fetching track data:', err);
                loadingOverlay.style.display = 'none'; 
            });
    });
</script>

</body>
</html>