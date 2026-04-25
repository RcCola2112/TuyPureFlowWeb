<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';

if (!isset($_SESSION['consumer_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}

$consumer_id = $_SESSION['consumer_id'];

// Fetch address from database
$stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ?");
$stmt->execute([$consumer_id]);
$address = $stmt->fetch(PDO::FETCH_ASSOC);

// Default coordinates
$defaultLat = $address['latitude'] ?? 13.8602; // Tuy, Batangas lat
$defaultLng = $address['longitude'] ?? 120.7335; // Tuy, Batangas lng

// Remove 'full_name' from address logic since your address table does not have this column
$redirect = $_GET['redirect'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $region = trim($_POST['region'] ?? ''); // Use region input as region column
    $zip_code = trim($_POST['zip_code'] ?? '');
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;

    // Update or insert address (now includes name, contact_number, barangay)
    if ($address) {
        $stmt = $conn->prepare("UPDATE address SET name = ?, contact_number = ?, street = ?, barangay = ?, city = ?, region = ?, zip_code = ?, latitude = ?, longitude = ? WHERE address_id = ?");
        $stmt->execute([$name, $contact_number, $street, $barangay, $city, $region, $zip_code, $latitude, $longitude, $address['address_id']]);
    } else {
        $stmt = $conn->prepare("INSERT INTO address (consumer_id, name, contact_number, street, barangay, city, region, zip_code, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$consumer_id, $name, $contact_number, $street, $barangay, $city, $region, $zip_code, $latitude, $longitude]);
    }

    if ($redirect) {
        header('Location: ' . $redirect);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Address</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    #map {
      height: 300px;
      width: 100%;
    }
  </style>
  <!-- Leaflet OpenStreetMap -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body class="bg-white p-6 text-sm font-sans">
  <h2 class="text-lg font-semibold mb-4">Your Shipping Address</h2>

  <?php if ($address): ?>
    <div class="mb-4">
      <p>
        <?= htmlspecialchars($address['street'] ?? '') ?>, 
        <?= htmlspecialchars($address['city'] ?? '') ?>, 
        <?= htmlspecialchars($address['region'] ?? '') ?> <?= htmlspecialchars($address['zip_code'] ?? '') ?>
      </p>
    </div>
  <?php else: ?>
    <p class="text-gray-500 mb-4">No address found. Please add one below.</p>
  <?php endif; ?>


  <form method="POST" action="address.php?redirect=<?= urlencode($redirect ?: ($_GET['redirect'] ?? '')) ?>" class="space-y-4">
    <input type="hidden" name="latitude" id="latitude" value="<?= $defaultLat ?>">
    <input type="hidden" name="longitude" id="longitude" value="<?= $defaultLng ?>">

    <div class="mb-4">
      <label class="block font-medium mb-2">Select Your Location</label>
      <button type="button" onclick="openMapModal()" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">Pick Location on Map</button>
    </div>

    <!-- Map Modal -->
    <div id="mapModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
      <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 relative overflow-y-auto max-h-screen">
        <button onclick="closeMapModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl">&times;</button>
        <h2 class="text-lg font-semibold mb-4">Select Your Location on Map</h2>
        <div id="map" style="height: 300px; width: 100%;"></div>
        <div class="mt-4 text-right">
          <button type="button" onclick="closeMapModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Done</button>
        </div>
      </div>
    </div>

    <div>
      <label class="block font-medium mb-1">Name</label>
      <input type="text" name="name" id="name" value="<?= $address['name'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block font-medium mb-1">Contact Number</label>
      <input type="text" name="contact_number" id="contact_number" value="<?= $address['contact_number'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block font-medium mb-1">Street</label>
      <input type="text" name="street" id="street" value="<?= $address['street'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block font-medium mb-1">Barangay</label>
      <input type="text" name="barangay" id="barangay" value="<?= $address['barangay'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block font-medium mb-1">City</label>
      <input type="text" name="city" id="city" value="<?= $address['city'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block font-medium mb-1">Region</label>
      <input type="text" name="region" id="region" value="<?= $address['region'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>
    <div>
      <label class="block font-medium mb-1">Zip Code</label>
      <input type="text" name="zip_code" id="zip_code" value="<?= $address['zip_code'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
    </div>

    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mt-4">Save Address</button>
  </form>

  <!-- Address Modal -->
  <div id="addressModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 relative overflow-y-auto max-h-screen">
      <button onclick="closeAddressModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl">&times;</button>
      <h2 class="text-lg font-semibold mb-4">Manage Address</h2>
      <?php if ($address): ?>
        <div class="mb-4">
          <p><?= htmlspecialchars($address['street']) ?>, <?= htmlspecialchars($address['city']) ?>, <?= htmlspecialchars($address['region'] ?? '') ?> <?= htmlspecialchars($address['zip_code'] ?? '') ?></p>
        </div>
      <?php else: ?>
        <p class="text-gray-500 mb-4">No address found. Please add one below.</p>
      <?php endif; ?>
      <form method="POST" action="save_address.php" class="space-y-4">
        <input type="hidden" name="latitude" id="latitude" value="<?= $defaultLat ?>">
        <input type="hidden" name="longitude" id="longitude" value="<?= $defaultLng ?>">
        <div>
          <label class="block font-medium mb-1">Street</label>
          <input type="text" name="street" value="<?= $address['street'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
          <label class="block font-medium mb-1">City</label>
          <input type="text" name="city" value="<?= $address['city'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
          <label class="block font-medium mb-1">Region</label>
          <input type="text" name="region" value="<?= $address['region'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
          <label class="block font-medium mb-1">Zip Code</label>
          <input type="text" name="zip_code" value="<?= $address['zip_code'] ?? '' ?>" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
          <label class="block font-medium mb-2">Select Your Location on Map</label>
          <div id="map"></div>
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mt-4">Save Address</button>
      </form>
    </div>
  </div>
  <script>
    let mapInstance = null;
    let markerInstance = null;
    function openMapModal() {
      document.getElementById('mapModal').classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      setTimeout(() => {
        if (!mapInstance) {
          mapInstance = L.map('map').setView([<?= $defaultLat ?>, <?= $defaultLng ?>], 14);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
          }).addTo(mapInstance);
          markerInstance = L.marker([<?= $defaultLat ?>, <?= $defaultLng ?>], { draggable: true }).addTo(mapInstance);
          function updateAddressFields(lat, lng) {
            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
              .then(response => response.json())
              .then(data => {
                document.getElementById('street').value = data.address.road || '';
                document.getElementById('barangay').value = data.address.neighbourhood || data.address.suburb || data.address.village || data.address.barangay || '';
                document.getElementById('city').value = data.address.city || data.address.town || data.address.village || '';
                document.getElementById('region').value = data.address.state || data.address.province || '';
                document.getElementById('zip_code').value = data.address.postcode || '';
              });
          }
          markerInstance.on('dragend', function (e) {
            var latlng = markerInstance.getLatLng();
            updateAddressFields(latlng.lat, latlng.lng);
          });
          mapInstance.on('click', function (e) {
            markerInstance.setLatLng(e.latlng);
            updateAddressFields(e.latlng.lat, e.latlng.lng);
          });
        }
      }, 100);
    }
    function closeMapModal() {
      document.getElementById('mapModal').classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
  </script>
</body>
</html>
</body>
</html>
