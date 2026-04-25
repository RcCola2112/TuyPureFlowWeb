<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

include '../db.php'; // Correct path

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $owner_name = trim($_POST['owner_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $shop_name = trim($_POST['shop_name'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $open_time = $_POST['opening_time'] ?? '';
    $close_time = $_POST['closing_time'] ?? '';

    if (empty($owner_name) || empty($contact_number) || empty($email) || empty($password) ||
        empty($confirm_password) || empty($shop_name)) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    }

    // Upload directories (absolute on server)
    $baseUploads = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    $logoDir = $baseUploads . DIRECTORY_SEPARATOR . 'distributor_logos' . DIRECTORY_SEPARATOR;
    $certDir = $baseUploads . DIRECTORY_SEPARATOR . 'distributor_certificates' . DIRECTORY_SEPARATOR;

    // Ensure directories exist
    if (!$error) {
        if (!is_dir($logoDir) && !mkdir($logoDir, 0755, true)) {
            $error = "Unable to create logo upload directory.";
        }
        if (!is_dir($certDir) && !mkdir($certDir, 0755, true)) {
            $error = "Unable to create certificate upload directory.";
        }
    }

    // track saved files for cleanup on error
    $saved_files = [];

    // --- Logo Upload ---
    $logo_path = null;
    if (!$error && isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['logo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "Error uploading logo (code {$file['error']}).";
        } else {
            $allowed_logo_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $orig = $file['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_logo_ext, true)) {
                $error = "Invalid logo file type. Allowed: " . implode(', ', $allowed_logo_ext);
            } else {
                if (!is_uploaded_file($file['tmp_name'])) {
                    $error = "Logo upload failed (tmp file missing).";
                } else {
                    $newFile = uniqid('logo_', true) . '.' . $ext;
                    $target = $logoDir . $newFile;
                    if (!move_uploaded_file($file['tmp_name'], $target)) {
                        $error = "Failed to move uploaded logo. Check folder permissions.";
                        error_log("Logo move failed: tmp={$file['tmp_name']} target={$target}");
                    } else {
                        $logo_path = 'uploads/distributor_logos/' . $newFile;
                        $saved_files[] = $target;
                    }
                }
            }
        }
    }

    // --- Certificates ---
    $cert_inputs = [
        'mayor_permit' => 1,
        'sanitary_permit' => 2,
        'dti_sec_registration' => 3
    ];
    $uploaded_certs = [];

    if (!$error) {
        foreach ($cert_inputs as $input_name => $type_id) {
            // Form requires these, so if not uploaded, treat as error
            if (!isset($_FILES[$input_name]) || $_FILES[$input_name]['error'] === UPLOAD_ERR_NO_FILE) {
                $error = "Please upload the required file: {$input_name}.";
                break;
            }

            $file = $_FILES[$input_name];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Error uploading {$input_name} (code {$file['error']}).";
                break;
            }

            $allowed_cert_ext = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            $orig = $file['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_cert_ext, true)) {
                $error = "Invalid file type for {$input_name}. Allowed: " . implode(', ', $allowed_cert_ext);
                break;
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                $error = "Upload failed for {$input_name} (tmp file missing).";
                break;
            }

            $newFile = uniqid($input_name . '_', true) . '.' . $ext;
            $target = $certDir . $newFile;
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                $error = "Failed to move uploaded file for {$input_name}. Check permissions.";
                error_log("Cert move failed: input={$input_name} tmp={$file['tmp_name']} target={$target}");
                break;
            }

            $uploaded_certs[] = [
              'certificate_type_id' => $type_id,
              'cert_data' => file_get_contents($target)
            ];
            $saved_files[] = $target;
        }
    }

    // If we encountered an upload error, remove any partial saved files
    if ($error && !empty($saved_files)) {
        foreach ($saved_files as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    // --- Insert into Database ---
    if (!$error) {
        try {
            $conn->beginTransaction();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert distributor
            $stmt = $conn->prepare("INSERT INTO distributor (name, phone, email, password, status, created_at)
                                    VALUES (?, ?, ?, ?, 'Pending', NOW())");
            $stmt->execute([$owner_name, $contact_number, $email, $hashed_password]);
            $distributor_id = $conn->lastInsertId();

            // Insert shop
            $location = $street; // you can include city/region if desired
            // Read logo file as blob if uploaded
            $logo_blob = null;
            if ($logo_path && file_exists(__DIR__ . '/' . $logo_path)) {
              $logo_blob = file_get_contents(__DIR__ . '/' . $logo_path);
            }
            $stmt2 = $conn->prepare("INSERT INTO shop (distributor_id, name, location, contact_number, latitude, longitude, open_time, close_time, logo_image)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->execute([$distributor_id, $shop_name, $location, $contact_number, $latitude, $longitude, $open_time, $close_time, $logo_blob]);

            // Insert certificates
            if (!empty($uploaded_certs)) {
              $stmt3 = $conn->prepare("INSERT INTO distributor_certificates (distributor_id, certificate_type_id, distributor_certificates_images, uploaded_at, status, remarks)
                           VALUES (?, ?, ?, NOW(), 'Pending', NULL)");
              foreach ($uploaded_certs as $cert) {
                $stmt3->execute([$distributor_id, $cert['certificate_type_id'], $cert['cert_data']]);
              }
            }

            $conn->commit();
            $success = true;
            header("Location: login.php?registered=1");
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            // cleanup saved files on DB error
            if (!empty($saved_files)) {
                foreach ($saved_files as $f) {
                    if (file_exists($f)) @unlink($f);
                }
            }
            error_log("Signup error: " . $e->getMessage());
            $error = "Error during registration. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributor Signup - Tuy PureFlow</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        /* keep original input look but avoid overly bright cyan when empty */
        input, select, textarea {
          background-color: #f9fafb !important;
          color: #111827 !important;
          margin-bottom: 1.5rem !important;
          display: block;
          border: 1px solid #e5e7eb !important;
          padding: .5rem .75rem;
          border-radius: .375rem;
        }

        input.filled, select.filled {
          background-color: #ffffff !important;
          color: #000 !important;
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        select:-webkit-autofill,
        select:-webkit-autofill:hover,
        select:-webkit-autofill:focus {
          -webkit-box-shadow: 0 0 0px 1000px #ffffff inset !important;
          -webkit-text-fill-color: #000 !important;
          transition: background-color 5000s ease-in-out 0s;
        }

        /* ensure map container has concrete size so Leaflet can render */
        #mapStep { height: 300px; width: 100%; z-index: 1; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const steps = document.querySelectorAll('.step');
      const nextBtns = document.querySelectorAll('.next-btn');
      const prevBtns = document.querySelectorAll('.prev-btn');
      let currentStep = 0;

      let mapInstance = null;
      let markerInstance = null;
      let infoControl = null;
      const defaultLat = 14.01877;
      const defaultLng = 120.73024;

      function ensureMap() {
        if (mapInstance) {
          setTimeout(() => mapInstance.invalidateSize(), 200);
          return;
        }
        const el = document.getElementById('mapStep');
        if (!el) return;
        mapInstance = L.map('mapStep').setView([defaultLat, defaultLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapInstance);

        // add info control on the map to visualize selected location (address + coords)
        infoControl = L.control({ position: 'bottomleft' });
        infoControl.onAdd = function () {
          this._div = L.DomUtil.create('div', 'bg-white p-2 text-sm rounded shadow');
          this.update = function (data) {
            if (!data) {
              this._div.innerHTML = '<strong>Selected location</strong><br><em>Click map or drag marker</em>';
            } else {
              const addr = data.address ? `<div class="font-medium">${data.address}</div>` : '';
              this._div.innerHTML = `<strong>Selected location</strong><br>${addr}Lat: ${data.lat.toFixed(5)}, Lng: ${data.lng.toFixed(5)}`;
            }
          };
          this.update();
          return this._div;
        };
        infoControl.addTo(mapInstance);

        markerInstance = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(mapInstance);
        markerInstance.on('dragend', function () {
          const latlng = markerInstance.getLatLng();
          updateAddressFields(latlng.lat, latlng.lng);
        });
        mapInstance.on('click', function (e) {
          markerInstance.setLatLng(e.latlng);
          updateAddressFields(e.latlng.lat, e.latlng.lng);
        });

        // initialize with default location visualized
        updateAddressFields(defaultLat, defaultLng);
        setTimeout(()=>{ mapInstance.invalidateSize(); }, 200);
      }

      function showStep(index) {
        steps.forEach((step, i) => step.classList.toggle('hidden', i !== index));

        const circles = [document.getElementById('circle1'), document.getElementById('circle2'), document.getElementById('circle3')];
        const lines = [document.getElementById('line1'), document.getElementById('line2')];

        circles.forEach((c, i) => {
          if (i < index) {
            c.classList.remove('bg-gray-300', 'text-gray-500');
            c.classList.add('bg-[#5ce1e6]', 'text-white');
            c.innerHTML = "✓";
          } else if (i === index) {
            c.classList.remove('bg-gray-300', 'text-gray-500');
            c.classList.add('bg-[#5ce1e6]', 'text-white');
            c.innerHTML = i + 1;
          } else {
            c.classList.remove('bg-[#5ce1e6]', 'text-white');
            c.classList.add('bg-gray-300', 'text-gray-500');
            c.innerHTML = i + 1;
          }
        });

        lines.forEach((line, i) => {
          if (i < index) line.classList.replace('bg-gray-300', 'bg-[#5ce1e6]');
          else line.classList.replace('bg-[#5ce1e6]', 'bg-gray-300');
        });

        if (index === 1) ensureMap();
      }

      nextBtns.forEach(btn => {
        btn.addEventListener('click', e => {
          e.preventDefault();
          const currentFields = steps[currentStep].querySelectorAll('input, select');
          let allFilled = true;
          currentFields.forEach(f => { if (f.hasAttribute('required') && !f.value.trim()) allFilled = false; });
          if (!allFilled) { alert('Please fill in all required fields before proceeding.'); return; }
          if (currentStep < steps.length - 1) { currentStep++; showStep(currentStep); }
        });
      });

      prevBtns.forEach(btn => {
        btn.addEventListener('click', e => {
          e.preventDefault();
          if (currentStep > 0) { currentStep--; showStep(currentStep); }
        });
      });

      document.querySelector('form').addEventListener('submit', function (e) {
        // Only prevent if not on last step
        if (currentStep !== steps.length - 1) {
          e.preventDefault();
          // Move to last step if submit is clicked early
          currentStep = steps.length - 1;
          showStep(currentStep);
        }
      });
      showStep(currentStep);

      const inputs = document.querySelectorAll('input, select');
      inputs.forEach(input => {
        input.addEventListener('input', () => { input.classList.add('filled'); });
        input.addEventListener('blur', () => { if (!input.value) input.classList.remove('filled'); });
      });
    });

    function previewLogo(e) {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function (ev) {
          document.getElementById('logoPreview').src = ev.target.result;
          document.getElementById('logoPreview').classList.remove('hidden');
        };
        reader.readAsDataURL(file);
      }
    }

    function updateAddressFields(lat, lng) {
      // update hidden form inputs
      const latEl = document.getElementById('latitude');
      const lngEl = document.getElementById('longitude');
      if (latEl) latEl.value = lat;
      if (lngEl) lngEl.value = lng;

      // reverse geocode and update the map info control + inline location info display
      fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
        .then(res => res.json())
        .then(data => {
          const displayAddress = data.display_name || '';
          // update visible location info below the map (user-facing)
          const infoEl = document.getElementById('locationInfo');
          if (infoEl) {
            infoEl.innerHTML = `<div class="font-medium text-[#004c8c] mb-1">${displayAddress}</div><div class="text-xs text-gray-600">Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}</div>`;
          }
          // if the map info control exists, update it too
          try {
            if (window.mapInstance && window.mapInstance._container && window.mapInstance.infoControl) {
              // nothing — prefer local variables
            }
          } catch (e) {}
          // attempt to update the control added in ensureMap (if exists)
          if (typeof L !== 'undefined') {
            // find control DOM and update if present
            const ctl = document.querySelector('#mapStep').closest('div')?.querySelector('.leaflet-control');
            // update custom control via stored reference if available
            if (window._leaflet_info_control_instance && window._leaflet_info_control_instance.update) {
              window._leaflet_info_control_instance.update({ lat, lng, address: displayAddress });
            }
          }

          // also try updating the control attached as closure variable (if mapInstance in scope)
          if (typeof mapInstance !== 'undefined' && mapInstance && mapInstance.eachLayer) {
            // If we added infoControl as a variable in the closure, it's not global.
            // We'll attempt to update by selecting the control DOM we created earlier:
            const controlDiv = document.querySelector('#mapStep + .leaflet-control-container .bg-white');
            if (controlDiv) {
              controlDiv.innerHTML = `<strong>Selected location</strong><br>${displayAddress ? `<div class="font-medium">${displayAddress}</div>` : ''}Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}`;
            }
          }

          // mark inputs as filled visually
          ['street','city','region'].forEach(name => {
            const el = document.getElementsByName(name)[0];
            if (el && el.value) el.classList.add('filled');
          });
        })
        .catch(() => {
          const infoEl = document.getElementById('locationInfo');
          if (infoEl) infoEl.innerHTML = `<div class="text-xs text-gray-600">Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}</div>`;
        });
    }
    </script>
</head>
<body class="bg-gradient-to-br from-white via-[#d9f6ff] to-[#b0ecfa] flex justify-center items-start min-h-screen">

<header class="fixed top-0 left-0 w-full bg-white shadow z-50">
  <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
    <div class="flex items-center gap-2 bg-transparent p-0 rounded">
      <img src="../images/logo.png" alt="Logo" class="h-10 w-auto object-contain">
      <span class="text-xl font-bold text-[#004c8c]">Tuy PureFlow</span>
    </div>
    <a href="login.php" class="px-4 py-2 bg-white border border-[#004c8c] text-[#004c8c] rounded hover:bg-[#004c8c] hover:text-white font-medium">Login</a>
  </div>
</header>

<div class="w-full max-w-3xl p-6 bg-white rounded shadow-lg mt-32">
  <?php if (!empty($error)): ?>
    <div class="mb-4 p-3 bg-red-100 text-red-700 rounded border border-red-300">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>
  <!-- Step Progress Bar -->
  <div class="flex items-center justify-center mb-8">
    <div class="flex items-center">
      <div id="circle1" class="w-8 h-8 flex items-center justify-center rounded-full bg-[#5ce1e6] text-white font-bold">1</div>
      <div id="line1" class="w-16 h-1 bg-gray-300"></div>
      <div id="circle2" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-300 text-gray-500 font-bold">2</div>
      <div id="line2" class="w-16 h-1 bg-gray-300"></div>
      <div id="circle3" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-300 text-gray-500 font-bold">3</div>
    </div>
  </div>

  <h2 class="text-2xl font-bold mb-6 text-center text-[#004c8c]">Distributor Signup</h2>

  <form action="signup.php" method="POST" enctype="multipart/form-data">
    <!-- Step 1 -->
    <div class="step">
      <input type="text" name="owner_name" placeholder="Owner Name *" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <input type="text" name="contact_number" placeholder="Contact Number *" required maxlength="11" class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <input type="email" name="email" placeholder="Email Address *" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <input type="password" name="password" placeholder="Password *" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <input type="password" name="confirm_password" placeholder="Confirm Password *" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <div class="flex justify-end mt-4">
        <button type="button" class="next-btn bg-[#004c8c] text-white px-4 py-2 rounded">Next</button>
      </div>
    </div>

    <!-- Step 2 -->
    <div class="step hidden">
      <input type="text" name="shop_name" placeholder="Shop Name *" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <button type="button" onclick="openMapModal()" class="bg-[#004c8c] hover:bg-[#003b6b] text-white px-4 py-2 mb-2 rounded">Set Location on Map</button>
      <div class="mb-2 text-sm text-gray-600">Click on the map to set your shop location or drag the marker.</div>
      <div id="mapStep" class="h-64 w-full rounded mb-2"></div>

      <!-- Visible location visualization: reverse-geocoded address + coords (map control also shows it) -->
      <div id="locationInfo" class="text-sm text-gray-700 mb-4">Click the map or drag the marker to select location.</div>

      <input type="hidden" name="latitude" id="latitude" required>
      <input type="hidden" name="longitude" id="longitude" required>
      <input type="text" name="street" placeholder="Street/House Number *" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
      <input type="hidden" name="city" id="city" value="Tuy">
      <input type="hidden" name="region" id="region" value="Region IV-A">
      <div class="mb-3 text-sm text-gray-700">
        <span class="font-medium text-[#004c8c]">City:</span> Tuy
        <span class="mx-2">|</span>
        <span class="font-medium text-[#004c8c]">Region:</span> Region IV-A
      </div>
      <div class="flex gap-4">
        <div class="flex-1">
          <label for="opening_time" class="block mb-1 font-medium text-[#004c8c]">Opening Time *</label>
          <input type="time" id="opening_time" name="opening_time" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
        </div>
        <div class="flex-1">
          <label for="closing_time" class="block mb-1 font-medium text-[#004c8c]">Closing Time *</label>
          <input type="time" id="closing_time" name="closing_time" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">
        </div>
      </div>

      <div class="mt-4">
        <label class="block mb-1 font-medium text-[#004c8c]">Shop Logo (PNG/JPG/WEBP, max 2MB)</label>
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none" onchange="previewLogo(event)">
        <img id="logoPreview" src="#" alt="Logo Preview" class="hidden w-32 h-32 object-contain mt-2"/>
      </div>

      <div class="flex justify-between mt-4">
        <button type="button" class="prev-btn bg-gray-600 text-white px-4 py-2 rounded">Back</button>
        <button type="button" class="next-btn bg-[#004c8c] hover:bg-[#003b6b] text-white px-4 py-2 rounded">Next</button>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="step hidden">
      <label class="block mb-1 font-medium text-[#004c8c]">Upload Mayor's / Business Permit *</label>
      <input type="file" name="mayor_permit" accept=".png,.jpg,.jpeg,.pdf,.webp" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">

      <label class="block mb-1 font-medium text-[#004c8c] mt-4">Upload Sanitary Permit *</label>
      <input type="file" name="sanitary_permit" accept=".png,.jpg,.jpeg,.pdf,.webp" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">

      <label class="block mb-1 font-medium text-[#004c8c] mt-4">DTI or SEC Registration *</label>
      <input type="file" name="dti_sec_registration" accept=".png,.jpg,.jpeg,.pdf,.webp" required class="w-full px-3 py-2 rounded border border-gray-300 focus:outline-none">

      <div class="flex justify-between mt-6">
        <button type="button" class="prev-btn bg-gray-600 text-white px-4 py-2 rounded">Back</button>
        <button type="submit" class="bg-[#004c8c] hover:bg-[#003b6b] text-white px-6 py-2 rounded">Submit</button>
      </div>
    </div>
  </form>
</div>

<!-- MAP MODAL -->
<div id="mapModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
  <div class="bg-white rounded-lg w-11/12 md:w-3/4 lg:w-1/2 p-4 relative">
    <button onclick="closeMapModal()" class="absolute top-2 right-2 bg-red-600 text-white px-2 py-1 rounded">✕</button>
    <h3 class="text-lg font-semibold text-[#004c8c] mb-3">Set Shop Location</h3>
    <div id="map" class="h-96 w-full rounded"></div>
  </div>
</div>

</body>
</html>
