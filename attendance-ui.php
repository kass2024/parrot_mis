<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin-login.php");
    exit;
}
require_once __DIR__ . '/includes/company_branding.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Parrot Canada Visa Consultant - Attendance</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
  /* Brand color palette */
  --deep-navy: #427431;
  --secondary-blue: #3661B9;
  --dark-blue: #2f5a26;
  --gold: #E21D1E;
  --white: #FFFFFF;
  
  /* Derived variables */
  --primary: var(--deep-navy);
  --primary-dark: var(--dark-blue);
  --accent: var(--gold);
  --bg: var(--white);
  --card: #f8fafc; /* Light background for card */
  --text: #1e293b;
  --muted: #64748b;
  --success: #2e7d32;
  --danger: #c62828;
  --shadow: 0 12px 30px rgba(1, 47, 107, 0.12); /* Using deep navy for shadow tint */
}

* { 
  box-sizing: border-box; 
  margin: 0;
  padding: 0;
}

body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ===== HEADER WITH LOGO ===== */
.pcvc-brand-banner {
  background: linear-gradient(135deg, var(--deep-navy) 0%, var(--secondary-blue) 100%);
  padding: 20px 0;
  text-align: center;
  box-shadow: 0 4px 12px rgba(0, 39, 101, 0.15);
}

.logo-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.logo-main {
  font-size: 2.5rem;
  font-weight: 800;
  color: var(--white);
  letter-spacing: 1px;
  position: relative;
  display: inline-block;
}

.logo-main::after {
  content: '🎓';
  position: absolute;
  top: -5px;
  right: -35px;
  font-size: 1.8rem;
}

.logo-subtitle {
  font-size: 1.1rem;
  font-weight: 500;
  color: var(--gold);
  letter-spacing: 0.5px;
}

/* ===== MAIN CONTENT ===== */
.attendance-wrapper {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  background: linear-gradient(180deg, var(--white) 0%, #f0f4f8 100%);
}

.attendance-card {
  width: 100%;
  max-width: 420px;
  background: var(--card);
  border-radius: 22px;
  padding: 28px;
  box-shadow: var(--shadow);
  text-align: center;
  border: 1px solid rgba(1, 47, 107, 0.1);
}

/* ===== STATUS ===== */
.status {
  font-size: 1.15rem;
  font-weight: 700;
  margin-bottom: 8px;
  padding: 12px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.9);
}

.waiting { 
  color: var(--muted);
  background: rgba(100, 116, 139, 0.1);
}
.inside { 
  color: var(--success);
  background: rgba(46, 125, 50, 0.1);
}
.outside { 
  color: var(--danger);
  background: rgba(198, 40, 40, 0.1);
}

.distance {
  margin-top: 8px;
  color: var(--muted);
  font-size: .95rem;
  font-weight: 500;
}

/* ===== LOADER ===== */
.loader {
  display: none;
  margin: 18px 0;
  color: var(--deep-navy);
  font-weight: 600;
}

/* ===== BUTTONS ===== */
.btn {
  width: 100%;
  border: none;
  border-radius: 999px;
  padding: 16px;
  font-size: 1rem;
  font-weight: 700;
  color: var(--white);
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  margin-top: 14px;
  letter-spacing: 0.5px;
}

.btn:disabled {
  opacity: .5;
  cursor: not-allowed;
}

.btn.green {
  background: linear-gradient(135deg, var(--success), #1b5e20);
  border: 2px solid transparent;
}

.btn.red {
  background: linear-gradient(135deg, var(--danger), #8e0000);
  border: 2px solid transparent;
}

.btn:hover:not(:disabled) {
  transform: translateY(-3px);
  box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
}

.btn:active:not(:disabled) {
  transform: translateY(-1px);
}

/* ===== FOOTER REMOVED ===== */

/* ===== RESPONSIVE ===== */
@media (min-width: 768px) {
  .attendance-card {
    max-width: 480px;
    padding: 34px;
  }
  
  .logo-main {
    font-size: 3rem;
  }
  
  .logo-subtitle {
    font-size: 1.3rem;
  }
}

/* Animation for status updates */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-5px); }
  to { opacity: 1; transform: translateY(0); }
}

.status, .distance, .btn {
  animation: fadeIn 0.3s ease-out;
}
</style>
</head>

<body>

<div class="pcvc-brand-banner">
  <div class="logo-container">
    <div class="logo-main"><?= htmlspecialchars(PCVC_COMPANY_DISPLAY_NAME, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="logo-subtitle">Attendance</div>
  </div>
</div>

<div class="attendance-wrapper">
  <div class="attendance-card">

    <div id="statusText" class="status waiting">
      📡 Waiting for GPS…
    </div>
    <div id="distanceText" class="distance">
      Distance: -- m
    </div>

    <div id="loader" class="loader">⏳ Processing…</div>

    <button id="btnIn" class="btn green" disabled>CHECK IN</button>
    <button id="btnOut" class="btn red" disabled>CHECK OUT</button>

  </div>
</div>

<script>
const PCVC_LOCATION_LABEL = <?= json_encode(PCVC_COMPANY_DISPLAY_NAME . ' Web-App', JSON_UNESCAPED_UNICODE) ?>;

/* ==============================
   SILENT LOGGER
================================ */
function logEvent(message, data = {}) {
  fetch("log.php", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({source: "attendance-ui", message, data})
  }).catch(() => {});
}

/* ==============================
   STATE
================================ */
let lat = null, lng = null, gpsReady = false, insideOffice = false, office = null;

const statusText = document.getElementById("statusText");
const distanceText = document.getElementById("distanceText");
const btnIn = document.getElementById("btnIn");
const btnOut = document.getElementById("btnOut");
const loader = document.getElementById("loader");

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/* ==============================
   HELPERS
================================ */
function setStatus(text, cls) {
  statusText.innerText = text;
  statusText.className = "status " + cls;
}

function enableButtons(enable) {
  btnIn.disabled = !enable;
  btnOut.disabled = !enable;
}

function haversine(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
    Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/* ==============================
   INIT
================================ */
document.addEventListener("DOMContentLoaded", () => {
  logEvent("Attendance UI loaded");
  startGPS();
});

/* ==============================
   GPS
================================ */
function startGPS() {
  if (!navigator.geolocation) {
    setStatus("❌ GPS not supported", "outside");
    return;
  }

  navigator.geolocation.watchPosition(
    pos => {
      lat = pos.coords.latitude;
      lng = pos.coords.longitude;
      gpsReady = true;
      if (!office) loadOffice();
      else evaluate();
    },
    () => {
      setStatus("❌ GPS permission denied", "outside");
    },
    {enableHighAccuracy: true}
  );
}

/* ==============================
   OFFICE
================================ */
function loadOffice() {
  fetch("office-info.php")
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        setStatus(res.message, "outside");
        return;
      }
      office = res.office;
      evaluate();
    })
    .catch(() => setStatus("❌ Office load failed", "outside"));
}

/* ==============================
   EVALUATE
================================ */
function evaluate() {
  if (!gpsReady || !office) return;

  const dist = Math.round(haversine(lat, lng, office.lat, office.lng));
  distanceText.innerText = "Distance: " + dist + " m";

  if (dist <= office.radius) {
    insideOffice = true;
    setStatus("✅ Inside Office", "inside");
    enableButtons(true);
  } else {
    insideOffice = false;
    setStatus("❌ Outside Office", "outside");
    enableButtons(false);
  }
}

/* ==============================
   SUBMIT
================================ */
function sendAttendance(action) {
  if (!gpsReady || !insideOffice) return;

  loader.style.display = "block";
  enableButtons(false);

  fetch("attendance-records.php", {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: new URLSearchParams({
      action, lat, lng, location: PCVC_LOCATION_LABEL, timezone, mock: 0
    })
  })
  .then(r => r.json())
  .then(res => {
    alert(res.message);
    logEvent("Attendance " + action, {lat, lng, distance: distanceText.innerText});
  })
  .catch(() => alert("Network error"))
  .finally(() => {
    loader.style.display = "none";
    enableButtons(true);
  });
}

btnIn.onclick = () => sendAttendance("checkin");
btnOut.onclick = () => sendAttendance("checkout");
</script>

</body>
</html>