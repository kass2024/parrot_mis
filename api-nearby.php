<?php
// File: api-nearby.php
$key = 'AIzaSyDhfyiGYTBvMSo3M-JtcyfcQIY9Be_o1DY';

$lat = $_GET['lat'] ?? '';
$lng = $_GET['lng'] ?? '';

if (!$lat || !$lng) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing lat/lng']);
  exit;
}

// Call Google Places Nearby Search REST API
$url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=$lat,$lng&radius=100&key=$key";

$response = file_get_contents($url);

header('Content-Type: application/json');
echo $response;
