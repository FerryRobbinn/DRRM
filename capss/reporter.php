<?php
// reporter.php - Complete emergency reporter with database submission
session_start();
require_once 'config/db_connect.php';

$success = false;
$error = null;
$tracking = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $phone = trim($_POST['phone'] ?? '');
    $reporter_name = trim($_POST['reporter_name'] ?? '');
    $incident_type = trim($_POST['incident_type'] ?? '');
    $other_incident_type = trim($_POST['other_incident_type'] ?? '');
    $severity = trim($_POST['severity'] ?? 'Minor');
    $description = trim($_POST['description'] ?? '');
    $lat = !empty($_POST['lat']) ? floatval($_POST['lat']) : null;
    $lng = !empty($_POST['lng']) ? floatval($_POST['lng']) : null;
    $address_manual = trim($_POST['address_manual'] ?? '');
    
    // If "Other" is selected, combine with specified type
    if ($incident_type === 'Other' && !empty($other_incident_type)) {
        $incident_type = 'Other: ' . $other_incident_type;
    } elseif ($incident_type === 'Other') {
        $error = "Please specify the type of emergency.";
    }
    
    // Validate required fields
    if (empty($phone)) {
        $error = "Please enter your phone number.";
    } elseif (empty($incident_type)) {
        $error = "Please select the type of emergency.";
    } elseif (empty($description)) {
        $error = "Please provide an incident description.";
    } elseif ((empty($lat) || empty($lng)) && empty($address_manual)) {
        $error = "Please provide a location (use GPS/map or enter address manually).";
    }
    
    // Generate tracking ID
    $tracking_id = 'INC-' . strtoupper(uniqid());
    
    // Prepare location address
    $location_address = !empty($address_manual) ? $address_manual : 'Location from GPS';
    
    // Handle file uploads (photos/videos)
    $uploaded_files = [];
    if (!empty($_FILES['media_files']['name'][0])) {
        $upload_dir = 'uploads/incidents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['media_files']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['media_files']['error'][$key] === UPLOAD_ERR_OK) {
                $file_name = time() . '_' . uniqid() . '_' . basename($_FILES['media_files']['name'][$key]);
                $file_path = $upload_dir . $file_name;
                
                // Check file type (allow images and videos)
                $file_type = mime_content_type($tmp_name);
                if (strpos($file_type, 'image/') === 0 || strpos($file_type, 'video/') === 0) {
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        $uploaded_files[] = $file_name;
                    }
                }
            }
        }
    }
    
    // Insert into database if no errors
    if (!$error) {
        $stmt = $conn->prepare("
            INSERT INTO tbl_incidents 
            (tracking_id, incident_type, severity, description, location_address, location_lat, location_lng, 
             reporter_phone, reporter_name, status, created_at, media_files) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)
        ");
        
        $media_files_json = !empty($uploaded_files) ? json_encode($uploaded_files) : null;
        $stmt->bind_param("ssssssdsss", 
            $tracking_id, $incident_type, $severity, $description, 
            $location_address, $lat, $lng, $phone, $reporter_name, $media_files_json
        );
        
        if ($stmt->execute()) {
            $success = true;
            $tracking = $tracking_id;
            
            // Log that a new incident was created
            error_log("New incident created: $tracking_id");
        } else {
            $error = "Database error: " . $conn->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Bongabon MDRRMO - Emergency Incident Reporter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        /* Severity Card Styles - Original Button Layout with Icons */
        .severity-option {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .severity-option:hover {
            transform: translateY(-3px);
        }
        /* Green - Minor: Walking wounded */
        .severity-minor { 
            background: linear-gradient(135deg, #10b981, #059669); 
            color: white; 
        }
        /* Yellow - Delayed: Serious but stable */
        .severity-delayed { 
            background: linear-gradient(135deg, #f59e0b, #d97706); 
            color: white; 
        }
        /* Red - Immediate: Life-threatening */
        .severity-immediate { 
            background: linear-gradient(135deg, #ef4444, #dc2626); 
            color: white; 
        }
        /* Black - Dead: Deceased */
        .severity-dead { 
            background: linear-gradient(135deg, #1f2937, #111827); 
            color: white; 
        }
        input:checked + .severity-minor, 
        .severity-radio:checked + .severity-minor { 
            box-shadow: 0 0 0 3px #10b981, 0 0 0 6px rgba(16, 185, 129, 0.3);
            transform: scale(1.02);
        }
        input:checked + .severity-delayed,
        .severity-radio:checked + .severity-delayed { 
            box-shadow: 0 0 0 3px #f59e0b, 0 0 0 6px rgba(245, 158, 11, 0.3);
            transform: scale(1.02);
        }
        input:checked + .severity-immediate,
        .severity-radio:checked + .severity-immediate { 
            box-shadow: 0 0 0 3px #ef4444, 0 0 0 6px rgba(239, 68, 68, 0.3);
            transform: scale(1.02);
        }
        input:checked + .severity-dead,
        .severity-radio:checked + .severity-dead { 
            box-shadow: 0 0 0 3px #1f2937, 0 0 0 6px rgba(31, 41, 55, 0.3);
            transform: scale(1.02);
        }
        .severity-description {
            font-size: 10px;
            opacity: 0.9;
            margin-top: 4px;
            display: block;
        }
        
        /* Media preview styling */
        .media-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
            max-height: 200px;
            overflow-y: auto;
            padding: 5px;
        }
        .preview-item {
            position: relative;
            width: 85px;
            height: 85px;
            border-radius: 12px;
            overflow: hidden;
            background: #1e293b;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            flex-shrink: 0;
        }
        .preview-item img, .preview-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .preview-badge {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(0,0,0,0.65);
            border-radius: 20px;
            font-size: 10px;
            padding: 2px 6px;
            color: white;
            font-weight: bold;
        }
        .remove-media {
            position: absolute;
            top: 2px;
            right: 2px;
            background: rgba(220, 38, 38, 0.9);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            transition: 0.1s;
        }
        .remove-media:hover {
            background: #dc2626;
            transform: scale(1.05);
        }
        
        /* Camera modal styles */
        .camera-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .camera-modal video, .camera-modal canvas {
            max-width: 100%;
            max-height: 55vh;
            border-radius: 12px;
        }
        .camera-controls {
            margin-top: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        /* Button styling */
        .camera-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        .camera-btn:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }
        .gallery-btn {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        .gallery-btn:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }
        .recording-indicator {
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(220, 38, 38, 0.9);
            color: white;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: bold;
            animation: pulse 1s infinite;
            z-index: 10;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
        .camera-mode-switch {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            background: #334155;
            padding: 5px;
            border-radius: 40px;
        }
        .mode-btn {
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }
        .mode-btn.active {
            background: #3b82f6;
            color: white;
        }
        .mode-btn:not(.active) {
            background: #475569;
            color: #cbd5e1;
        }
        .counter-badge {
            background: #ef4444;
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 11px;
            margin-left: 8px;
        }
        .call-button {
            transition: all 0.2s ease;
        }
        .call-button:hover {
            transform: scale(1.05);
            background: #16a34a !important;
        }
        .hotline-card {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .hotline-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3);
        }
        .flash-effect {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: white;
            opacity: 0;
            pointer-events: none;
            z-index: 10000;
            transition: opacity 0.1s;
        }
        /* Hidden file input */
        #galleryInput {
            display: none;
        }
    </style>
</head>
<body class="bg-slate-900 font-sans antialiased">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-gradient-to-r from-red-700 to-red-800 text-white py-5 shadow-lg">
            <div class="container mx-auto px-4">
                <div class="flex justify-between items-center flex-wrap gap-3">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight">MDRRMO Bongabon</h1>
                        <p class="text-red-200 text-sm">Emergency Response Center - 24/7 Dispatch</p>
                    </div>
                    <a href="tel:09525620223" class="hotline-card bg-black/30 backdrop-blur-sm px-5 py-3 rounded-xl text-center transition-all hover:bg-green-600 group">
                        <div class="text-2xl font-mono font-bold flex items-center gap-2">
                            <i class="fas fa-phone-alt text-green-400 group-hover:animate-pulse"></i>
                            09525620223
                        </div>
                        <div class="text-xs text-red-200 group-hover:text-white">Tap to Call Emergency Hotline</div>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container mx-auto px-4 py-8">
            <div class="max-w-2xl mx-auto">
                <?php if ($success === true): ?>
                    <div class="bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl p-6 mb-6 text-center shadow-2xl animate-pulse">
                        <i class="fas fa-check-circle text-5xl mb-3"></i>
                        <h2 class="text-2xl font-bold mb-2">Report Submitted Successfully!</h2>
                        <p class="mb-2">Tracking ID: <strong class="text-xl bg-white text-green-700 px-4 py-1 rounded-full inline-block"><?= htmlspecialchars($tracking) ?></strong></p>
                        <p>Emergency responders have been notified. Save this ID to check status.</p>
                        <a href="reporter.php" class="inline-block mt-4 bg-white text-green-700 px-6 py-2 rounded-full font-bold shadow hover:bg-gray-100 transition"><i class="fas fa-plus"></i> Submit New Report</a>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-2xl overflow-hidden border border-gray-200">
                        <div class="bg-gradient-to-r from-red-600 to-red-700 text-white px-6 py-5">
                            <h2 class="text-xl font-bold flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i> Report an Emergency</h2>
                            <p class="text-sm text-red-100">Fill out this form to report an incident. Responders will be dispatched based on severity.</p>
                        </div>
                        
                        <?php if (isset($error)): ?>
                            <div class="bg-red-100 border-l-4 border-red-600 text-red-800 p-4 m-4 rounded-lg"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" class="p-6" id="emergencyForm">
                            <!-- Reporter Phone -->
                            <div class="mb-5">
                                <label class="block text-gray-800 font-bold mb-2"><i class="fas fa-phone-alt text-red-500 mr-2"></i>Your Phone Number *</label>
                                <input type="tel" name="phone" required class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-red-400 transition" placeholder="09xx xxx xxxx">
                                <p class="text-xs text-gray-500 mt-1">We'll call you to verify the report</p>
                            </div>
                            
                            <!-- Reporter Name Optional -->
                            <div class="mb-5">
                                <label class="block text-gray-800 font-bold mb-2"><i class="fas fa-user text-red-500 mr-2"></i>Your Name (Optional)</label>
                                <input type="text" name="reporter_name" class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-red-400">
                            </div>
                            
                            <!-- Incident Type -->
                            <div class="mb-5">
                                <label class="block text-gray-800 font-bold mb-2"><i class="fas fa-fire text-red-500 mr-2"></i>Type of Emergency *</label>
                                <select name="incident_type" id="incident_type" required class="w-full border border-gray-300 rounded-xl px-4 py-3 bg-white focus:ring-2 focus:ring-red-400">
                                    <option value="">Select incident type...</option>
                                    <option value="Medical Emergency">Medical Emergency</option>
                                    <option value="Vehicular Accident">Vehicular Accident</option>
                                    <option value="Fire">Fire</option>
                                    <option value="Flood">Flood / Rescue</option>
                                    <option value="Crime">Crime / Police Assistance</option>
                                    <option value="Natural Disaster">Natural Disaster</option>
                                    <option value="Other">Other (Please specify)</option>
                                </select>
                                <div id="otherIncidentContainer" class="mt-3 hidden">
                                    <label class="block text-gray-800 font-bold mb-2 text-sm"><i class="fas fa-pencil text-red-500 mr-2"></i>Specify Emergency Type *</label>
                                    <input type="text" name="other_incident_type" id="other_incident_type" placeholder="e.g., Animal Bite, Electrical Hazard, etc." class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-red-400">
                                </div>
                            </div>
                            
                            <!-- SEVERITY LEVEL -->
                            <div class="mb-6">
                                <label class="block text-gray-800 font-bold mb-3"><i class="fas fa-chart-line text-red-500 mr-2"></i>Severity Level *</label>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="severity" value="Minor" class="hidden peer severity-radio" required checked>
                                        <div class="severity-option severity-minor rounded-xl py-3 px-2 text-center font-bold transition-all">
                                            <i class="fa-regular fa-face-smile text-2xl block mb-1"></i>
                                            MINOR
                                            <span class="severity-description">Walking wounded</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="severity" value="Delayed" class="hidden peer severity-radio">
                                        <div class="severity-option severity-delayed rounded-xl py-3 px-2 text-center font-bold transition-all">
                                            <i class="fa-regular fa-face-meh text-2xl block mb-1"></i>
                                            DELAYED
                                            <span class="severity-description">Serious but stable</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="severity" value="Immediate" class="hidden peer severity-radio">
                                        <div class="severity-option severity-immediate rounded-xl py-3 px-2 text-center font-bold transition-all">
                                            <i class="fa-regular fa-face-frown text-2xl block mb-1"></i>
                                            IMMEDIATE
                                            <span class="severity-description">Life-threatening</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="severity" value="Dead" class="hidden peer severity-radio">
                                        <div class="severity-option severity-dead rounded-xl py-3 px-2 text-center font-bold transition-all">
                                            <i class="fa-regular fa-face-dizzy text-2xl block mb-1"></i>
                                            DEAD
                                            <span class="severity-description">Deceased</span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- LOCATION -->
                            <div class="mb-5">
                                <label class="block text-gray-800 font-bold mb-2"><i class="fas fa-map-marker-alt text-red-500 mr-2"></i>Location *</label>
                                <div id="map" style="height: 280px; border-radius: 16px; margin-bottom: 12px; z-index: 1; border: 1px solid #ddd;"></div>
                                <div class="flex flex-wrap gap-3 items-center mb-2">
                                    <button type="button" id="getLocationBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl shadow transition flex items-center gap-2 font-medium">
                                        <i class="fas fa-location-dot"></i> Get My Current Location
                                    </button>
                                    <div id="locationTimerMsg" class="text-xs bg-amber-50 text-amber-700 px-3 py-1.5 rounded-full hidden items-center gap-1">
                                        <i class="fa-regular fa-clock"></i> <span id="countdownSec">10</span>s remaining - manual entry
                                    </div>
                                </div>
                                <input type="hidden" name="lat" id="lat" value="">
                                <input type="hidden" name="lng" id="lng" value="">
                                <textarea name="address_manual" id="address_manual" rows="2" placeholder="Or type location manually (street, barangay, landmark) - required if GPS fails" class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-red-400"></textarea>
                                <div id="locationStatus" class="text-xs mt-1 text-gray-500 flex items-center gap-1">
                                    <i class="fa-regular fa-map"></i> Use map or click location button
                                </div>
                            </div>
                            
                            <!-- Incident Description -->
                            <div class="mb-5">
                                <label class="block text-gray-800 font-bold mb-2"><i class="fas fa-align-left text-red-500 mr-2"></i>Incident Description *</label>
                                <textarea name="description" rows="4" required placeholder="Describe what happened, how many people are involved, any specific details that responders should know..." class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-red-400"></textarea>
                            </div>
                            
                            <!-- MEDIA: Gallery Button + Camera Button -->
                            <div class="mb-5">
                                <label class="block text-gray-800 font-bold mb-2">
                                    <i class="fas fa-images text-red-500 mr-2"></i>Photos & Videos 
                                    <span id="mediaCount" class="counter-badge">0</span>
                                </label>
                                <div class="flex flex-wrap gap-3 items-center mb-3">
                                    <input type="file" name="media_files[]" id="galleryInput" accept="image/*,video/mp4,video/quicktime,video/x-msvideo,video/*" multiple>
                                    <button type="button" id="openGalleryBtn" class="gallery-btn text-white px-5 py-2.5 rounded-xl shadow transition flex items-center gap-2 font-medium">
                                        <i class="fas fa-images"></i> Open Gallery
                                    </button>
                                    <button type="button" id="cameraBtn" class="camera-btn text-white px-5 py-2.5 rounded-xl shadow transition flex items-center gap-2 font-medium">
                                        <i class="fas fa-camera"></i> Open Camera
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    Select from gallery or take photos/videos directly. Supports multiple files.
                                </p>
                                <div id="mediaPreviewContainer" class="media-preview">
                                    <div class="text-xs text-gray-400">No media selected</div>
                                </div>
                            </div>
                            
                            <!-- Legal Notice -->
                            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-xl mb-5 text-sm">
                                <i class="fas fa-info-circle text-red-600 mr-2"></i> <strong>Important:</strong> Please provide accurate information. False reports may be subject to legal action.
                            </div>
                            
                            <button type="submit" class="w-full bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white font-bold py-4 rounded-xl text-lg transition shadow-lg flex items-center justify-center gap-3">
                                <i class="fas fa-paper-plane"></i> Submit Emergency Report
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-slate-800 text-white py-4 mt-8 text-center text-sm">
            (c) <?= date('Y') ?> MDRRMO Bongabon - Emergency Response Center | 24/7 Hotline: <a href="tel:09525620223" class="text-green-400 hover:underline">09525620223</a>
        </div>
    </div>
    
    <script>
        // ---------- MAP & LOCATION ----------
        let map;
        let marker;
        let locationTimeoutId = null;
        let countdownInterval = null;
        let locationAttemptActive = false;
        
        const locationBtn = document.getElementById('getLocationBtn');
        const locationStatusDiv = document.getElementById('locationStatus');
        const latInput = document.getElementById('lat');
        const lngInput = document.getElementById('lng');
        const addressManual = document.getElementById('address_manual');
        const timerMsgSpan = document.getElementById('locationTimerMsg');
        const countdownSpan = document.getElementById('countdownSec');
        
        const defaultLat = 15.6333;
        const defaultLng = 121.3167;
        
        function initMap(lat = defaultLat, lng = defaultLng) {
            if (map) map.remove();
            map = L.map('map').setView([lat, lng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '(c) OpenStreetMap contributors'
            }).addTo(map);
            
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            
            marker.on('dragend', function() {
                const pos = marker.getLatLng();
                latInput.value = pos.lat.toFixed(6);
                lngInput.value = pos.lng.toFixed(6);
                locationStatusDiv.innerHTML = '<i class="fa-regular fa-check-circle text-green-600"></i> Location set by dragging marker.';
                stopLocationAttempt();
            });
            
            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                latInput.value = e.latlng.lat.toFixed(6);
                lngInput.value = e.latlng.lng.toFixed(6);
                locationStatusDiv.innerHTML = '<i class="fa-regular fa-check-circle text-green-600"></i> Location selected on map.';
                stopLocationAttempt();
            });
            
            if (lat !== defaultLat || lng !== defaultLng) {
                latInput.value = lat.toFixed(6);
                lngInput.value = lng.toFixed(6);
            }
        }
        
        initMap();
        
        function stopLocationAttempt() {
            if (locationTimeoutId) {
                clearTimeout(locationTimeoutId);
                locationTimeoutId = null;
            }
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }
            if (timerMsgSpan) timerMsgSpan.classList.add('hidden');
            locationAttemptActive = false;
        }
        
        function startGeolocationWithTimeout() {
            if (!navigator.geolocation) {
                locationStatusDiv.innerHTML = '<i class="fa-regular fa-circle-exclamation text-red-500"></i> Geolocation not supported. Please type address manually or click on map.';
                addressManual.focus();
                return;
            }
            
            if (locationAttemptActive) {
                locationStatusDiv.innerHTML = '<i class="fa-regular fa-spinner fa-pulse"></i> Already attempting location...';
                return;
            }
            
            locationAttemptActive = true;
            timerMsgSpan.classList.remove('hidden');
            let secondsLeft = 10;
            countdownSpan.innerText = secondsLeft;
            
            if (countdownInterval) clearInterval(countdownInterval);
            countdownInterval = setInterval(() => {
                secondsLeft--;
                countdownSpan.innerText = secondsLeft;
                if (secondsLeft <= 0) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                    if (locationAttemptActive) {
                        stopLocationAttempt();
                        locationStatusDiv.innerHTML = '<i class="fa-regular fa-clock text-amber-600"></i> Location timeout. Please type address manually or click on map.';
                        addressManual.classList.add('border-amber-400');
                        addressManual.focus();
                        locationAttemptActive = false;
                    }
                }
            }, 1000);
            
            locationTimeoutId = setTimeout(() => {
                if (locationAttemptActive) {
                    if (countdownInterval) clearInterval(countdownInterval);
                    stopLocationAttempt();
                    locationStatusDiv.innerHTML = '<i class="fa-regular fa-triangle-exclamation text-red-500"></i> Location fetch timed out. Please type address manually.';
                    addressManual.classList.add('border-amber-400');
                    locationAttemptActive = false;
                }
            }, 10000);
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    if (!locationAttemptActive) return;
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    latInput.value = lat.toFixed(6);
                    lngInput.value = lng.toFixed(6);
                    map.setView([lat, lng], 16);
                    marker.setLatLng([lat, lng]);
                    locationStatusDiv.innerHTML = '<i class="fa-regular fa-location-dot text-green-600"></i> GPS location captured! You can adjust marker on map.';
                    stopLocationAttempt();
                    addressManual.classList.remove('border-amber-400');
                    
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.display_name && addressManual.value.trim() === '') {
                                addressManual.value = data.display_name.substring(0, 200);
                            }
                        })
                        .catch(() => {});
                },
                function(error) {
                    if (!locationAttemptActive) return;
                    stopLocationAttempt();
                    let errorMsg = '';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMsg = 'Location permission denied. Please enable GPS or type address manually.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMsg = 'Location unavailable. Please select on map or type address.';
                            break;
                        case error.TIMEOUT:
                            errorMsg = 'Location request timeout. Please type address manually.';
                            break;
                        default:
                            errorMsg = 'Could not get location. Please enter address manually.';
                    }
                    locationStatusDiv.innerHTML = `<i class="fa-regular fa-circle-exclamation text-red-500"></i> ${errorMsg}`;
                    addressManual.classList.add('border-amber-400');
                    addressManual.focus();
                    locationAttemptActive = false;
                },
                { enableHighAccuracy: true, timeout: 9000 }
            );
        }
        
        locationBtn.addEventListener('click', function(e) {
            e.preventDefault();
            startGeolocationWithTimeout();
        });
        
        addressManual.addEventListener('input', function() {
            if (this.value.trim().length > 3) {
                locationStatusDiv.innerHTML = '<i class="fa-regular fa-pen-to-square text-blue-600"></i> Manual address provided.';
                addressManual.classList.remove('border-amber-400');
                stopLocationAttempt();
            } else if (this.value.trim().length === 0) {
                locationStatusDiv.innerHTML = '<i class="fa-regular fa-map"></i> Use map or click location button.';
            }
        });
        
        // ---------- DYNAMIC OTHER FIELD FOR INCIDENT TYPE ----------
        const incidentTypeSelect = document.getElementById('incident_type');
        const otherIncidentContainer = document.getElementById('otherIncidentContainer');
        const otherIncidentInput = document.getElementById('other_incident_type');
        
        incidentTypeSelect.addEventListener('change', function() {
            if (this.value === 'Other') {
                otherIncidentContainer.classList.remove('hidden');
                otherIncidentInput.setAttribute('required', 'required');
                otherIncidentInput.focus();
            } else {
                otherIncidentContainer.classList.add('hidden');
                otherIncidentInput.removeAttribute('required');
                otherIncidentInput.value = '';
            }
        });
        
        // Check on page load (in case of form resubmission)
        if (incidentTypeSelect.value === 'Other') {
            otherIncidentContainer.classList.remove('hidden');
            otherIncidentInput.setAttribute('required', 'required');
        }
        
        // ---------- MEDIA: Gallery and Camera with preview ----------
        const galleryInput = document.getElementById('galleryInput');
        const openGalleryBtn = document.getElementById('openGalleryBtn');
        const previewContainer = document.getElementById('mediaPreviewContainer');
        const mediaCountSpan = document.getElementById('mediaCount');
        let selectedFiles = [];
        
        function updateMediaCount() {
            if (mediaCountSpan) {
                mediaCountSpan.textContent = selectedFiles.length;
            }
        }
        
        function updateFileInput() {
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(f => dataTransfer.items.add(f));
            galleryInput.files = dataTransfer.files;
        }
        
        // Open gallery when button is clicked
        openGalleryBtn.addEventListener('click', function(e) {
            e.preventDefault();
            galleryInput.click();
        });
        
        galleryInput.addEventListener('change', function(e) {
            const files = Array.from(e.target.files);
            selectedFiles = files;
            updateMediaCount();
            renderPreviews();
        });
        
        function renderPreviews() {
            previewContainer.innerHTML = '';
            if (selectedFiles.length === 0) {
                previewContainer.innerHTML = '<div class="text-xs text-gray-400">No media selected</div>';
                return;
            }
            
            selectedFiles.forEach((file, index) => {
                const isVideo = file.type.startsWith('video/');
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'preview-item';
                    
                    if (isVideo) {
                        const videoElem = document.createElement('video');
                        videoElem.src = ev.target.result;
                        videoElem.muted = true;
                        videoElem.style.width = '100%';
                        videoElem.style.height = '100%';
                        videoElem.onmouseenter = () => videoElem.play().catch(e=>{});
                        videoElem.onmouseleave = () => videoElem.pause();
                        wrapper.appendChild(videoElem);
                        const badge = document.createElement('div');
                        badge.className = 'preview-badge';
                        badge.innerHTML = '<i class="fa-regular fa-video"></i> video';
                        wrapper.appendChild(badge);
                    } else {
                        const img = document.createElement('img');
                        img.src = ev.target.result;
                        wrapper.appendChild(img);
                        const badge = document.createElement('div');
                        badge.className = 'preview-badge';
                        badge.innerHTML = '<i class="fa-regular fa-image"></i> photo';
                        wrapper.appendChild(badge);
                    }
                    
                    const removeBtn = document.createElement('div');
                    removeBtn.className = 'remove-media';
                    removeBtn.innerHTML = 'X';
                    removeBtn.onclick = (e) => {
                        e.stopPropagation();
                        selectedFiles.splice(index, 1);
                        updateFileInput();
                        updateMediaCount();
                        renderPreviews();
                    };
                    wrapper.appendChild(removeBtn);
                    previewContainer.appendChild(wrapper);
                };
                reader.readAsDataURL(file);
            });
        }
        
        // ---------- ENHANCED CAMERA: Flash, Front/Back toggle, Multiple photos + Video Recording ----------
        const cameraBtn = document.getElementById('cameraBtn');
        let cameraStream = null;
        let cameraModal = null;
        let mediaRecorder = null;
        let recordedChunks = [];
        let isRecording = false;
        let currentMode = 'photo';
        let currentFacingMode = 'environment';
        let currentTrack = null;
        let flashSupported = false;
        let flashOn = false;
        
        cameraBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openEnhancedCamera();
        });
        
        async function startCamera(facingMode = 'environment') {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
            }
            
            const constraints = {
                video: { facingMode: { exact: facingMode } },
                audio: currentMode === 'video'
            };
            
            try {
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                cameraStream = stream;
                currentTrack = stream.getVideoTracks()[0];
                
                if (currentTrack && currentTrack.getCapabilities) {
                    const capabilities = currentTrack.getCapabilities();
                    flashSupported = capabilities.torch === true;
                }
                
                const video = document.getElementById('cameraVideo');
                if (video) {
                    video.srcObject = stream;
                    video.play();
                }
                return true;
            } catch (err) {
                try {
                    const fallbackStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: currentMode === 'video' });
                    cameraStream = fallbackStream;
                    currentTrack = fallbackStream.getVideoTracks()[0];
                    const video = document.getElementById('cameraVideo');
                    if (video) {
                        video.srcObject = fallbackStream;
                        video.play();
                    }
                    return true;
                } catch (fallbackErr) {
                    console.error('Camera error:', fallbackErr);
                    return false;
                }
            }
        }
        
        function toggleFlash() {
            if (!currentTrack || !flashSupported) {
                alert('Flash not supported on this camera or browser');
                return;
            }
            
            flashOn = !flashOn;
            currentTrack.applyConstraints({
                advanced: [{ torch: flashOn }]
            }).catch(err => console.error('Flash toggle error:', err));
            
            const flashBtn = document.getElementById('flashToggleBtn');
            if (flashBtn) {
                if (flashOn) {
                    flashBtn.innerHTML = '<i class="fas fa-bolt"></i> Flash ON';
                    flashBtn.classList.add('bg-yellow-500', 'text-black');
                    flashBtn.classList.remove('bg-gray-600');
                } else {
                    flashBtn.innerHTML = '<i class="fas fa-bolt"></i> Flash OFF';
                    flashBtn.classList.add('bg-gray-600');
                    flashBtn.classList.remove('bg-yellow-500', 'text-black');
                }
            }
        }
        
        async function switchCamera() {
            currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
            await startCamera(currentFacingMode);
        }
        
        function openEnhancedCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Your browser does not support camera access. Please use the gallery option instead.');
                return;
            }
            
            cameraModal = document.createElement('div');
            cameraModal.className = 'camera-modal';
            cameraModal.innerHTML = `
                <div style="background: #1e293b; padding: 20px; border-radius: 24px; text-align: center; max-width: 95%;">
                    <div class="camera-mode-switch">
                        <div id="modePhotoBtn" class="mode-btn active">Photo Mode</div>
                        <div id="modeVideoBtn" class="mode-btn">Video Mode</div>
                    </div>
                    <div style="position: relative;">
                        <video id="cameraVideo" autoplay playsinline style="border-radius: 16px; max-width: 100%; max-height: 55vh;"></video>
                        <canvas id="cameraCanvas" style="display: none;"></canvas>
                        <div id="recordingIndicator" class="recording-indicator" style="display: none;">RECORDING...</div>
                    </div>
                    <div class="camera-controls">
                        <button id="capturePhotoBtn" class="bg-red-600 hover:bg-red-700 text-white px-5 py-3 rounded-full font-bold shadow transition"><i class="fas fa-camera"></i> Take Photo</button>
                        <button id="startVideoBtn" class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-3 rounded-full font-bold shadow transition" style="display: none;"><i class="fas fa-video"></i> Start Recording</button>
                        <button id="stopVideoBtn" class="bg-red-600 hover:bg-red-700 text-white px-5 py-3 rounded-full font-bold shadow transition" style="display: none;"><i class="fas fa-stop"></i> Stop Recording</button>
                        <button id="flashToggleBtn" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-3 rounded-full font-bold shadow transition"><i class="fas fa-bolt"></i> Flash OFF</button>
                        <button id="switchCameraBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-full font-bold shadow transition"><i class="fas fa-sync-alt"></i> Switch Camera</button>
                        <button id="closeCameraBtn" class="bg-gray-600 hover:bg-gray-700 text-white px-5 py-3 rounded-full font-bold shadow transition"><i class="fas fa-times"></i> Close</button>
                    </div>
                    <p class="text-white text-xs mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <span id="cameraHelpText">Take as many photos as you need. Each photo will be added to your report.</span>
                    </p>
                </div>
            `;
            document.body.appendChild(cameraModal);
            
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            const captureBtn = document.getElementById('capturePhotoBtn');
            const startVideoBtn = document.getElementById('startVideoBtn');
            const stopVideoBtn = document.getElementById('stopVideoBtn');
            const closeBtn = document.getElementById('closeCameraBtn');
            const modePhotoBtn = document.getElementById('modePhotoBtn');
            const modeVideoBtn = document.getElementById('modeVideoBtn');
            const recordingIndicator = document.getElementById('recordingIndicator');
            const cameraHelpText = document.getElementById('cameraHelpText');
            const flashToggleBtn = document.getElementById('flashToggleBtn');
            const switchCameraBtn = document.getElementById('switchCameraBtn');
            
            startCamera('environment');
            
            flashToggleBtn.addEventListener('click', toggleFlash);
            switchCameraBtn.addEventListener('click', switchCamera);
            
            modePhotoBtn.addEventListener('click', () => {
                currentMode = 'photo';
                modePhotoBtn.classList.add('active');
                modeVideoBtn.classList.remove('active');
                captureBtn.style.display = 'flex';
                startVideoBtn.style.display = 'none';
                stopVideoBtn.style.display = 'none';
                if (isRecording) stopRecording();
                cameraHelpText.textContent = 'Take as many photos as you need. Each photo will be added to your report.';
                startCamera(currentFacingMode);
            });
            
            modeVideoBtn.addEventListener('click', () => {
                currentMode = 'video';
                modeVideoBtn.classList.add('active');
                modePhotoBtn.classList.remove('active');
                captureBtn.style.display = 'none';
                startVideoBtn.style.display = 'flex';
                stopVideoBtn.style.display = 'none';
                cameraHelpText.textContent = 'Record a video of the incident. Click Start Recording, then Stop when done.';
                startCamera(currentFacingMode);
            });
            
            captureBtn.addEventListener('click', function() {
                if (!video.videoWidth || !video.videoHeight) {
                    alert('Camera not ready yet. Please wait a moment.');
                    return;
                }
                
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                canvas.toBlob(function(blob) {
                    if (blob) {
                        const fileName = `camera_photo_${Date.now()}.jpg`;
                        const file = new File([blob], fileName, { type: 'image/jpeg' });
                        selectedFiles.push(file);
                        updateFileInput();
                        renderPreviews();
                        updateMediaCount();
                        
                        const flashDiv = document.createElement('div');
                        flashDiv.className = 'flash-effect';
                        flashDiv.style.opacity = '0.8';
                        document.body.appendChild(flashDiv);
                        setTimeout(() => flashDiv.remove(), 150);
                        
                        showToast('Photo captured and added!');
                    }
                }, 'image/jpeg', 0.85);
            });
            
            function stopRecording() {
                if (mediaRecorder && isRecording) {
                    mediaRecorder.stop();
                }
            }
            
            startVideoBtn.addEventListener('click', function() {
                if (isRecording) return;
                if (!cameraStream) {
                    alert('Camera not ready.');
                    return;
                }
                
                recordedChunks = [];
                try {
                    const mimeType = MediaRecorder.isTypeSupported('video/webm') ? 'video/webm' : 'video/mp4';
                    mediaRecorder = new MediaRecorder(cameraStream, { mimeType });
                    
                    mediaRecorder.ondataavailable = function(event) {
                        if (event.data.size > 0) {
                            recordedChunks.push(event.data);
                        }
                    };
                    
                    mediaRecorder.onstop = function() {
                        if (recordedChunks.length > 0) {
                            const blob = new Blob(recordedChunks, { type: 'video/webm' });
                            const fileName = `camera_video_${Date.now()}.webm`;
                            const file = new File([blob], fileName, { type: 'video/webm' });
                            selectedFiles.push(file);
                            updateFileInput();
                            renderPreviews();
                            updateMediaCount();
                            showToast('Video recorded and added!');
                        }
                        recordedChunks = [];
                        isRecording = false;
                        startVideoBtn.style.display = 'flex';
                        stopVideoBtn.style.display = 'none';
                        recordingIndicator.style.display = 'none';
                    };
                    
                    mediaRecorder.start(1000);
                    isRecording = true;
                    startVideoBtn.style.display = 'none';
                    stopVideoBtn.style.display = 'flex';
                    recordingIndicator.style.display = 'block';
                    showToast('Recording... Click Stop when finished', 3000);
                    
                } catch (err) {
                    console.error('Recording error:', err);
                    alert('Could not start recording. Your browser may not support video recording.');
                }
            });
            
            stopVideoBtn.addEventListener('click', function() {
                stopRecording();
            });
            
            closeBtn.addEventListener('click', function() {
                closeCamera();
            });
            
            function closeCamera() {
                if (mediaRecorder && isRecording) {
                    mediaRecorder.stop();
                }
                if (cameraStream) {
                    cameraStream.getTracks().forEach(track => track.stop());
                    cameraStream = null;
                }
                if (cameraModal) {
                    document.body.removeChild(cameraModal);
                    cameraModal = null;
                }
            }
        }
        
        function showToast(message, duration = 2000) {
            const toast = document.createElement('div');
            toast.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-50';
            toast.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), duration);
        }
        
        // Form validation before submit
        const form = document.getElementById('emergencyForm');
        form.addEventListener('submit', function(e) {
            const phone = document.querySelector('input[name="phone"]').value.trim();
            if (!phone) {
                alert('Please enter your phone number.');
                e.preventDefault();
                return false;
            }
            const incidentType = document.querySelector('select[name="incident_type"]').value;
            if (!incidentType) {
                alert('Please select the type of emergency.');
                e.preventDefault();
                return false;
            }
            
            // Check if "Other" is selected and validate the specification
            if (incidentType === 'Other') {
                const otherSpec = document.getElementById('other_incident_type').value.trim();
                if (!otherSpec) {
                    alert('Please specify the type of emergency.');
                    e.preventDefault();
                    return false;
                }
            }
            
            const severityRadios = document.querySelectorAll('input[name="severity"]');
            let severitySelected = false;
            severityRadios.forEach(radio => { if (radio.checked) severitySelected = true; });
            if (!severitySelected) {
                alert('Please select severity level.');
                e.preventDefault();
                return false;
            }
            const description = document.querySelector('textarea[name="description"]').value.trim();
            if (!description) {
                alert('Please provide incident description.');
                e.preventDefault();
                return false;
            }
            const latVal = latInput.value;
            const lngVal = lngInput.value;
            const addrManualVal = addressManual.value.trim();
            if ((!latVal || !lngVal) && addrManualVal === '') {
                alert('Please provide location: either use GPS/map OR type address manually.');
                e.preventDefault();
                return false;
            }
            return true;
        });
        
        updateMediaCount();
    </script>
</body>
</html>