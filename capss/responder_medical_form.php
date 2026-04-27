<?php
// responder_medical_form.php - Complete medical form with map and photos
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    header('Location: responder_login.php');
    exit;
}

$incident_id = $_GET['incident_id'];
$incident = $conn->query("SELECT * FROM tbl_incidents WHERE incident_id = $incident_id")->fetch_assoc();
$photos = $conn->query("SELECT photo_path FROM tbl_incident_photos WHERE incident_id = $incident_id");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save medical form
    $stmt = $conn->prepare("INSERT INTO tbl_medical_forms 
        (incident_id, responder_id, patient_name, patient_age, patient_gender, 
         patient_address, emergency_contact, emergency_phone, blood_pressure, 
         pulse_rate, respiratory_rate, temperature, symptoms, allergies, 
         medications, past_history, last_intake, events_leading, chief_complaint, 
         actions_taken, injury_map_data, transport_status, receiving_hospital, 
         receiving_person, patient_signature, responder_signature) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Bind parameters and execute...
    
    // Update incident status
    $conn->query("UPDATE tbl_incidents SET status = 'completed' WHERE incident_id = $incident_id");
    
    header('Location: responder_dashboard.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Response Form - MDRRMO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h4>Medical Response Form - <?= $incident['tracking_id'] ?></h4>
                <small>Incident: <?= $incident['incident_type'] ?> | Severity: <?= $incident['severity'] ?></small>
            </div>
            <div class="card-body">
                <!-- Incident Location Map -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h5>Incident Location</h5>
                        <div id="incidentMap" style="height: 300px; border-radius: 10px;"></div>
                        <p class="text-muted mt-2">📍 <?= $incident['location_address'] ?></p>
                    </div>
                </div>
                
                <!-- Incident Photos -->
                <?php if ($photos->num_rows > 0): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <h5>Incident Photos</h5>
                        <div class="row">
                            <?php while($photo = $photos->fetch_assoc()): ?>
                            <div class="col-md-3 mb-2">
                                <img src="uploads/incidents/<?= $photo['photo_path'] ?>" class="img-fluid rounded" style="cursor:pointer" onclick="window.open('uploads/incidents/<?= $photo['photo_path'] ?>')">
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="medicalForm">
                    <h5 class="mt-3">Patient Information</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Patient Name</label>
                            <input type="text" name="patient_name" class="form-control" required>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label>Age</label>
                            <input type="number" name="patient_age" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Gender</label>
                            <select name="patient_gender" class="form-control">
                                <option>Male</option>
                                <option>Female</option>
                            </select>
                        </div>
                    </div>
                    
                    <h5 class="mt-3">Vital Signs</h5>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label>Blood Pressure</label>
                            <input type="text" name="blood_pressure" placeholder="120/80" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Pulse Rate</label>
                            <input type="text" name="pulse_rate" placeholder="bpm" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Respiratory Rate</label>
                            <input type="text" name="respiratory_rate" placeholder="breaths/min" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Temperature</label>
                            <input type="text" name="temperature" placeholder="°C" class="form-control">
                        </div>
                    </div>
                    
                    <h5 class="mt-3">SAMPLE History</h5>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label>Symptoms</label>
                            <textarea name="symptoms" rows="2" class="form-control"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Allergies</label>
                            <input type="text" name="allergies" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Medications</label>
                            <input type="text" name="medications" class="form-control">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label>Past Medical History</label>
                            <input type="text" name="past_history" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Last Intake/Output</label>
                            <input type="text" name="last_intake" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Events Leading to Injury</label>
                            <input type="text" name="events_leading" class="form-control">
                        </div>
                    </div>
                    
                    <h5 class="mt-3">Assessment & Intervention</h5>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label>Chief Complaint</label>
                            <textarea name="chief_complaint" rows="2" class="form-control" required></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label>Actions Taken</label>
                            <textarea name="actions_taken" rows="3" class="form-control" required></textarea>
                        </div>
                    </div>
                    
                    <h5 class="mt-3">Transport & Handover</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label>Transport Status</label>
                            <select name="transport_status" class="form-control">
                                <option value="treated_on_scene">Treated on Scene</option>
                                <option value="transported">Transported to Hospital</option>
                                <option value="refused_transport">Refused Transport</option>
                                <option value="deceased">Deceased</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Receiving Hospital</label>
                            <input type="text" name="receiving_hospital" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Receiving Person</label>
                            <input type="text" name="receiving_person" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <label>Responder Signature</label>
                            <div class="border rounded p-2">
                                <canvas id="responderSigCanvas" width="400" height="100" style="border:1px solid #ddd; width:100%"></canvas>
                                <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="clearSignature()">Clear</button>
                            </div>
                            <input type="hidden" name="responder_signature" id="responderSignature">
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-success btn-lg w-100" onclick="saveSignature()">
                            <i class="fas fa-save"></i> Submit Medical Report to Admin
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize map
        const map = L.map('incidentMap').setView([<?= $incident['location_lat'] ?? 15.6333 ?>, <?= $incident['location_lng'] ?? 121.3167 ?>], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        L.marker([<?= $incident['location_lat'] ?? 15.6333 ?>, <?= $incident['location_lng'] ?? 121.3167 ?>]).addTo(map).bindPopup('Incident Location').openPopup();
        
        // Signature pad
        let signaturePad;
        const canvas = document.getElementById('responderSigCanvas');
        canvas.width = canvas.clientWidth;
        canvas.height = 100;
        signaturePad = new SignaturePad(canvas, { backgroundColor: 'white' });
        
        function clearSignature() {
            signaturePad.clear();
        }
        
        function saveSignature() {
            if (!signaturePad.isEmpty()) {
                document.getElementById('responderSignature').value = signaturePad.toDataURL();
            }
            return true;
        }
    </script>
</body>
</html>