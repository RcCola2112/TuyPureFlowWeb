<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['distributor_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';
$currentPage = 'settings';

// Get distributor info
$distributor_id = $_SESSION['distributor_id'] ?? 1;
$stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
$stmt->execute([$distributor_id]);
$distributor = $stmt->fetch();

$username = $distributor['name'] ?? '';
$email = $distributor['email'] ?? '';
$phone = $distributor['phone'] ?? '';
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

// Fetch shop info
$shop_stmt = $conn->prepare("SELECT shop_id, name, location, contact_number, latitude, longitude, open_time, close_time, logo_image FROM shop WHERE distributor_id = ? LIMIT 1");
$shop_stmt->execute([$distributor_id]);
$shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
$shop_id = $shop['shop_id'] ?? null;
$shop_name = $shop['name'] ?? '';
$shop_location = $shop['location'] ?? '';
$shop_contact = $shop['contact_number'] ?? '';
$shop_latitude = $shop['latitude'] ?? '';
$shop_longitude = $shop['longitude'] ?? '';
$shop_open_time = $shop['open_time'] ?? '';
$shop_close_time = $shop['close_time'] ?? '';
$logo_blob = $shop['logo_image'] ?? null;

$update_msg = '';
$msg_type = '';

// Handle account update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $new_name = trim($_POST['name'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$new_name || !$new_email || !$new_phone) {
        $update_msg = "Name, email, and phone are required.";
        $msg_type = 'error';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $update_msg = "Invalid email address.";
        $msg_type = 'error';
    } elseif ($new_password && $new_password !== $confirm_password) {
        $update_msg = "New passwords do not match.";
        $msg_type = 'error';
    } elseif ($new_password && $current_password) {
        if (!password_verify($current_password, $distributor['password'])) {
            $update_msg = "Current password is incorrect.";
            $msg_type = 'error';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE distributor SET name = ?, email = ?, phone = ?, password = ? WHERE distributor_id = ?");
            $success = $stmt->execute([$new_name, $new_email, $new_phone, $hashed, $distributor_id]);
            if ($success) {
                $update_msg = "Account updated successfully!";
                $msg_type = 'success';
                // Refresh data
                $stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
                $stmt->execute([$distributor_id]);
                $distributor = $stmt->fetch();
                $username = $distributor['name'] ?? '';
                $email = $distributor['email'] ?? '';
                $phone = $distributor['phone'] ?? '';
            } else {
                $update_msg = "Update failed.";
                $msg_type = 'error';
            }
        }
    } else {
        $stmt = $conn->prepare("UPDATE distributor SET name = ?, email = ?, phone = ? WHERE distributor_id = ?");
        $success = $stmt->execute([$new_name, $new_email, $new_phone, $distributor_id]);
        if ($success) {
            $update_msg = "Account updated successfully!";
            $msg_type = 'success';
            // Refresh data
            $stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
            $stmt->execute([$distributor_id]);
            $distributor = $stmt->fetch();
            $username = $distributor['name'] ?? '';
            $email = $distributor['email'] ?? '';
            $phone = $distributor['phone'] ?? '';
        } else {
            $update_msg = "Update failed.";
            $msg_type = 'error';
        }
    }
}

// Handle shop information update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_shop_info']) && $shop_id) {
    $new_shop_name = trim($_POST['shop_name'] ?? '');
    $new_location = trim($_POST['location'] ?? '');
    $new_contact = trim($_POST['contact_number'] ?? '');
    $new_email = trim($_POST['shop_email'] ?? '');
    $new_open_time = trim($_POST['open_time'] ?? '');
    $new_close_time = trim($_POST['close_time'] ?? '');
    $new_latitude = trim($_POST['latitude'] ?? '');
    $new_longitude = trim($_POST['longitude'] ?? '');
    
    if (!$new_shop_name) {
        $update_msg = "Business name is required.";
        $msg_type = 'error';
    } elseif ($new_email && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $update_msg = "Invalid email address.";
        $msg_type = 'error';
    } else {
        // Update shop information
        $stmt = $conn->prepare("UPDATE shop SET name = ?, location = ?, contact_number = ?, open_time = ?, close_time = ?, latitude = ?, longitude = ? WHERE shop_id = ?");
        $success = $stmt->execute([$new_shop_name, $new_location, $new_contact, $new_open_time, $new_close_time, $new_latitude ?: null, $new_longitude ?: null, $shop_id]);
        
        // Update distributor email if provided
        if ($new_email) {
            $stmt2 = $conn->prepare("UPDATE distributor SET email = ? WHERE distributor_id = ?");
            $stmt2->execute([$new_email, $distributor_id]);
        }
        
        if ($success) {
            $update_msg = "Shop information updated successfully!";
            $msg_type = 'success';
            // Refresh shop data
            $shop_stmt = $conn->prepare("SELECT shop_id, name, location, contact_number, latitude, longitude, open_time, close_time, logo_image FROM shop WHERE distributor_id = ? LIMIT 1");
            $shop_stmt->execute([$distributor_id]);
            $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
            $shop_name = $shop['name'] ?? '';
            $shop_location = $shop['location'] ?? '';
            $shop_contact = $shop['contact_number'] ?? '';
            $shop_latitude = $shop['latitude'] ?? '';
            $shop_longitude = $shop['longitude'] ?? '';
            $shop_open_time = $shop['open_time'] ?? '';
            $shop_close_time = $shop['close_time'] ?? '';
        } else {
            $update_msg = "Failed to update shop information.";
            $msg_type = 'error';
        }
    }
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_logo'])) {
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logoTmp = $_FILES['logo']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','webp'];
        if (!in_array($ext, $allowed)) {
            $update_msg = "Logo must be PNG, JPG, JPEG, or WEBP.";
            $msg_type = 'error';
        } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            $update_msg = "Logo file too large (max 2MB).";
            $msg_type = 'error';
        } else {
            $uploadDir = __DIR__ . '/uploads/distributor_logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

            $filename = "dist_{$distributor_id}_logo_" . time() . "." . $ext;
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($logoTmp, $destPath)) {
                // Read file as blob for database storage
                $logo_data = file_get_contents($destPath);
                $stmt = $conn->prepare("UPDATE shop SET logo_image = ? WHERE distributor_id = ?");
                $stmt->execute([$logo_data, $distributor_id]);
                $update_msg = "Logo updated successfully!";
                $msg_type = 'success';
                // Refresh logo blob
                $logo_blob = $logo_data;
            } else {
                $update_msg = "Failed to upload logo.";
                $msg_type = 'error';
            }
        }
    } else {
        $update_msg = "No logo file selected.";
        $msg_type = 'error';
    }
}

// Handle certificate upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_certificate'])) {
    $cert_type_id = intval($_POST['certificate_type_id'] ?? 0);
    $type_stmt = $conn->query("SELECT certificate_type_id FROM certificate_type");
    $valid_type_ids = $type_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($cert_type_id, $valid_type_ids, true)) {
        $update_msg = "Please select a valid certificate type.";
        $msg_type = 'error';
    } elseif (isset($_FILES['certificate']) && $_FILES['certificate']['error'] === UPLOAD_ERR_OK) {
        $cert_tmp = $_FILES['certificate']['tmp_name'];
        $cert_ext = strtolower(pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        if (!in_array($cert_ext, $allowed)) {
            $update_msg = "Certificate must be PDF, JPG, or PNG.";
            $msg_type = 'error';
        } elseif ($_FILES['certificate']['size'] > 5*1024*1024) {
            $update_msg = "Certificate file too large (max 5MB).";
            $msg_type = 'error';
        } else {
            $cert_filename = "dist_{$distributor_id}_certtype{$cert_type_id}_" . time() . "." . $cert_ext;
            $cert_dir = __DIR__ . '/uploads/distributor_certificates/';
            if (!is_dir($cert_dir)) mkdir($cert_dir, 0777, true);
            $cert_fullpath = $cert_dir . $cert_filename;

            if (move_uploaded_file($cert_tmp, $cert_fullpath)) {
                $cert_data = file_get_contents($cert_fullpath);
                $stmt = $conn->prepare("
                    INSERT INTO distributor_certificates (distributor_id, certificate_type_id, distributor_certificates_images, uploaded_at, status, remarks)
                    VALUES (?, ?, ?, NOW(), 'Pending', NULL)
                ");
                $stmt->execute([$distributor_id, $cert_type_id, $cert_data]);
                $update_msg = "Certificate uploaded successfully!";
                $msg_type = 'success';
            } else {
                $update_msg = "Failed to upload certificate.";
                $msg_type = 'error';
            }
        }
    } else {
        $update_msg = "No certificate file selected.";
        $msg_type = 'error';
    }
}

// Fetch certificates
$cert_stmt = $conn->prepare("
    SELECT dc.certificate_id, dc.certificate_type_id, ct.certificate_name, dc.uploaded_at, dc.status, dc.remarks
    FROM distributor_certificates dc
    LEFT JOIN certificate_type ct ON dc.certificate_type_id = ct.certificate_type_id
    WHERE dc.distributor_id = ?
    ORDER BY dc.uploaded_at DESC
    LIMIT 5
");
$cert_stmt->execute([$distributor_id]);
$certificates = $cert_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Settings | Tuy PureFlow Distributor</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .gradient-text {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }
    .settings-card {
      background: white;
      border-radius: 16px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      transition: all 0.3s ease;
      border: 1px solid #e5e7eb;
      min-width: 0;
    }
    .cards-container {
      display: flex;
      gap: 20px;
      align-items: stretch;
    }
    .card {
      background: white;
      border-radius: 15px;
      padding: 20px;
      flex: 1;
      min-width: 600px;
      display: flex;
      flex-direction: column;
    }
    .card.large {
      width: 1600px;
    }
    .card form {
      display: flex;
      flex-direction: column;
      flex: 1;
    }
    .card form .space-y-4 {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    .card button[type="submit"],
    .card form > button[type="submit"] {
      margin-top: auto;
    }
    .settings-card:hover {
      box-shadow: 0 8px 24px rgba(59, 130, 246, 0.1);
      transform: translateY(-2px);
    }
    .input-group {
      position: relative;
    }
    .input-group input,
    .input-group select {
      transition: all 0.2s ease;
    }
    .input-group input:focus,
    .input-group select:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
      outline: none;
    }
    .section-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      color: white;
      font-size: 20px;
    }
    .btn-primary {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      transition: all 0.3s ease;
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
    }
    .alert {
      animation: slideDown 0.3s ease-out;
    }
    @keyframes slideDown {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
  </style>
</head>
<body class="flex bg-gray-100 min-h-screen">
  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>
  <!-- Main Content -->
  <div class="ml-64 flex flex-col flex-1">
    <!-- Header -->
    <?php include 'header.php'; ?>
    <!-- Page Content -->
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8 fade-in">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-cog text-blue-600"></i>
          Settings
        </h1>
        <p class="text-gray-600">Manage your account, shop, and certificates</p>
      </div>

      <!-- Alert Message -->
      <?php if ($update_msg): ?>
        <div class="mb-6 alert <?= $msg_type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?> border rounded-lg p-4 flex items-center gap-3 fade-in">
          <i class="fas <?= $msg_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> text-lg"></i>
          <span class="font-medium"><?= htmlspecialchars($update_msg) ?></span>
        </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 fade-in">
        <!-- Account Information Card -->
        <div class="settings-card p-6">
          <div class="flex items-center gap-4 mb-6">
            <div class="section-icon">
              <i class="fas fa-user"></i>
            </div>
            <div>
              <h2 class="text-2xl font-bold text-gray-800">Account Information</h2>
              <p class="text-sm text-gray-500">Update your personal details</p>
            </div>
          </div>
          <form method="POST" class="space-y-4">
            <input type="hidden" name="update_account" value="1">
            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-user mr-2 text-blue-600"></i>Full Name
              </label>
              <input type="text" name="name" value="<?= htmlspecialchars($username) ?>" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     required>
            </div>
            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-envelope mr-2 text-blue-600"></i>Email Address
              </label>
              <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     required>
            </div>
            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-phone mr-2 text-blue-600"></i>Phone Number
              </label>
              <input type="text" name="phone" value="<?= htmlspecialchars($phone) ?>" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     required>
            </div>
            <button type="submit" class="w-full btn-primary text-white px-6 py-3 rounded-lg font-semibold">
              <i class="fas fa-save mr-2"></i>Save Account Changes
            </button>
          </form>
        </div>

        <!-- Password Change Card -->
        <div class="settings-card p-6">
          <div class="flex items-center gap-4 mb-6">
            <div class="section-icon">
              <i class="fas fa-lock"></i>
            </div>
            <div>
              <h2 class="text-2xl font-bold text-gray-800">Change Password</h2>
              <p class="text-sm text-gray-500">Update your account password</p>
            </div>
          </div>
          <form method="POST" class="space-y-4">
            <input type="hidden" name="update_account" value="1">
            <input type="hidden" name="name" value="<?= htmlspecialchars($username) ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-key mr-2 text-blue-600"></i>Current Password
              </label>
              <input type="password" name="current_password" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     placeholder="Enter current password">
            </div>
            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-lock mr-2 text-blue-600"></i>New Password
              </label>
              <input type="password" name="new_password" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     placeholder="Enter new password">
            </div>
            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-lock mr-2 text-blue-600"></i>Confirm New Password
              </label>
              <input type="password" name="confirm_password" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     placeholder="Confirm new password">
            </div>
            <button type="submit" class="w-full btn-primary text-white px-6 py-3 rounded-lg font-semibold">
              <i class="fas fa-key mr-2"></i>Update Password
            </button>
            <p class="text-xs text-gray-500 text-center mt-2">
              <i class="fas fa-info-circle mr-1"></i>Leave password fields empty if you don't want to change it
            </p>
          </form>
        </div>

        <!-- Shop Information Card -->
        <div class="settings-card p-6 lg:col-span-2">
          <div class="flex items-center gap-4 mb-6">
            <div class="section-icon">
              <i class="fas fa-store"></i>
            </div>
            <div>
              <h2 class="text-2xl font-bold text-gray-800">Shop Information</h2>
              <p class="text-sm text-gray-500">Manage your shop details and business information</p>
            </div>
          </div>
          <form method="POST" class="space-y-4" id="shopInfoForm">
            <input type="hidden" name="update_shop_info" value="1">
            <input type="hidden" name="latitude" id="shop_latitude" value="<?= htmlspecialchars($shop_latitude) ?>">
            <input type="hidden" name="longitude" id="shop_longitude" value="<?= htmlspecialchars($shop_longitude) ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="input-group">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                  <i class="fas fa-store mr-2 text-blue-600"></i>Business Name <span class="text-red-500">*</span>
                </label>
                <input type="text" name="shop_name" value="<?= htmlspecialchars($shop_name) ?>" 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       required>
              </div>
              
              <div class="input-group">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                  <i class="fas fa-phone mr-2 text-blue-600"></i>Phone Number
                </label>
                <input type="text" name="contact_number" value="<?= htmlspecialchars($shop_contact) ?>" 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="Enter shop phone number">
              </div>
            </div>

            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-map-marker-alt mr-2 text-blue-600"></i>Address
              </label>
              <div class="flex gap-2">
                <input type="text" name="location" id="shop_address" value="<?= htmlspecialchars($shop_location) ?>" 
                       class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="Enter shop address">
                <button type="button" onclick="openMapPicker()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold whitespace-nowrap">
                  <i class="fas fa-map-pin mr-2"></i>Pin on Map
                </button>
              </div>
            </div>

            <div class="input-group">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-envelope mr-2 text-blue-600"></i>Email
              </label>
              <input type="email" name="shop_email" value="<?= htmlspecialchars($email) ?>" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                     placeholder="Enter shop email">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="input-group">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                  <i class="fas fa-clock mr-2 text-blue-600"></i>Open Time
                </label>
                <input type="text" name="open_time" value="<?= htmlspecialchars($shop_open_time) ?>" 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="e.g., 9:00 AM">
              </div>
              
              <div class="input-group">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                  <i class="fas fa-clock mr-2 text-blue-600"></i>Close Time
                </label>
                <input type="text" name="close_time" value="<?= htmlspecialchars($shop_close_time) ?>" 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="e.g., 6:00 PM">
              </div>
            </div>

            <button type="submit" class="w-full btn-primary text-white px-6 py-3 rounded-lg font-semibold">
              <i class="fas fa-save mr-2"></i>Update Shop Info
            </button>
          </form>
        </div>

        <!-- Store Logo and Certificates Row -->
        <div class="cards-container">
          <!-- Store Logo Card -->
          <div class="card large">
            <div class="flex items-center gap-4 mb-6">
              <div class="section-icon">
                <i class="fas fa-image"></i>
              </div>
              <div>
                <h2 class="text-2xl font-bold text-gray-800">Store Logo</h2>
                <p class="text-sm text-gray-500">Upload your shop logo</p>
              </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-4 flex flex-col flex-1">
              <input type="hidden" name="update_logo" value="1">
              <div class="flex-1 space-y-4">
                <?php if ($logo_blob): ?>
                  <div class="p-5 bg-gray-50 rounded-lg border border-gray-200">
                    <label class="block text-sm font-semibold text-gray-700 mb-3">Current Logo</label>
                    <div class="flex justify-center items-center min-h-[120px]">
                      <img src="data:image/png;base64,<?= base64_encode($logo_blob) ?>" 
                           alt="Store Logo" 
                           class="max-h-28 w-auto rounded-lg border-2 border-gray-300 shadow-sm object-contain">
                    </div>
                  </div>
                <?php else: ?>
                  <div class="p-8 bg-gray-50 rounded-lg border border-gray-200 text-center">
                    <i class="fas fa-image text-gray-400 text-5xl mb-3 block"></i>
                    <p class="text-sm text-gray-500 font-medium">No logo uploaded</p>
                  </div>
                <?php endif; ?>
                <div class="input-group">
                  <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fas fa-upload mr-2 text-blue-600"></i>Upload Logo (PNG, JPG, WEBP - Max 2MB)
                  </label>
                  <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" 
                         class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                </div>
              </div>
              <div class="mt-auto">
                <button type="submit" class="w-full btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                  <i class="fas fa-upload mr-2"></i>Upload Logo
                </button>
              </div>
            </form>
          </div>

          <!-- Certificates Upload Card -->
          <div class="card">
            <div class="flex items-center gap-4 mb-6">
              <div class="section-icon">
                <i class="fas fa-certificate"></i>
              </div>
              <div>
                <h2 class="text-2xl font-bold text-gray-800">Certificates</h2>
                <p class="text-sm text-gray-500">Upload verification certificates</p>
              </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-4 flex flex-col flex-1">
              <input type="hidden" name="upload_certificate" value="1">
              <div class="flex-1 space-y-4">
                <div class="input-group">
                  <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fas fa-certificate mr-2 text-blue-600"></i>Certificate Type
                  </label>
                  <select name="certificate_type_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    <option value="">Select certificate type</option>
                    <?php
                    $type_stmt = $conn->query("SELECT certificate_type_id, certificate_name FROM certificate_type");
                    $types = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($types as $type): ?>
                      <option value="<?= (int)$type['certificate_type_id'] ?>"><?= htmlspecialchars($type['certificate_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="input-group">
                  <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fas fa-file mr-2 text-blue-600"></i>Certificate File (PDF/JPG/PNG - Max 5MB)
                  </label>
                  <input type="file" name="certificate" accept="application/pdf,image/png,image/jpeg" 
                         class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" 
                         required>
                </div>
              </div>
              <div class="mt-auto">
                <div class="text-center mb-3">
                  <a href="certificates.php" class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 font-semibold transition-colors">
                    <i class="fas fa-eye mr-2"></i>View All Certificates
                  </a>
                </div>
                <button type="submit" class="w-full btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                  <i class="fas fa-upload mr-2"></i>Upload Certificate
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

    </main>
  </div>

  <!-- Map Picker Modal -->
  <div id="mapModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-xl font-bold text-gray-800">Select Location on Map</h3>
        <button onclick="closeMapPicker()" class="text-gray-500 hover:text-gray-700">
          <i class="fas fa-times text-2xl"></i>
        </button>
      </div>
      <div class="p-6">
        <div id="map" style="height: 400px; width: 100%; border-radius: 8px; overflow: hidden;" class="border border-gray-300"></div>
        <div class="mt-4 flex gap-3">
          <input type="text" id="map_search" placeholder="Search for address..." 
                 class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <button onclick="searchAddress()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
            <i class="fas fa-search mr-2"></i>Search
          </button>
        </div>
        <div class="mt-4 flex gap-3">
          <button onclick="confirmLocation()" class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-semibold">
            <i class="fas fa-check mr-2"></i>Confirm Location
          </button>
          <button onclick="closeMapPicker()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-semibold">
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Map functionality - requires Google Maps API key
    // Replace 'YOUR_API_KEY' with your actual Google Maps API key
    const GOOGLE_MAPS_API_KEY = ''; // Add your API key here
    
    let map;
    let marker;
    let geocoder;
    let selectedLat = <?= $shop_latitude ? $shop_latitude : 'null' ?>;
    let selectedLng = <?= $shop_longitude ? $shop_longitude : 'null' ?>;
    let autocomplete;
    let mapLoaded = false;

    function openMapPicker() {
      if (!GOOGLE_MAPS_API_KEY) {
        alert('Map functionality requires a Google Maps API key. Please contact the administrator.');
        return;
      }
      
      document.getElementById('mapModal').classList.remove('hidden');
      
      if (!mapLoaded) {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${GOOGLE_MAPS_API_KEY}&libraries=places&callback=initMap`;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
        mapLoaded = true;
      } else if (map) {
        // Map already initialized, just show modal
        const defaultCenter = selectedLat && selectedLng 
          ? { lat: parseFloat(selectedLat), lng: parseFloat(selectedLng) }
          : { lat: 14.5995, lng: 120.9842 };
        map.setCenter(defaultCenter);
        if (selectedLat && selectedLng && marker) {
          marker.setPosition(defaultCenter);
        }
      }
    }

    function closeMapPicker() {
      document.getElementById('mapModal').classList.add('hidden');
    }

    function initMap() {
      const defaultCenter = selectedLat && selectedLng 
        ? { lat: parseFloat(selectedLat), lng: parseFloat(selectedLng) }
        : { lat: 14.5995, lng: 120.9842 }; // Default to Manila, Philippines

      map = new google.maps.Map(document.getElementById('map'), {
        center: defaultCenter,
        zoom: selectedLat && selectedLng ? 15 : 10,
      });

      geocoder = new google.maps.Geocoder();

      if (selectedLat && selectedLng) {
        marker = new google.maps.Marker({
          position: defaultCenter,
          map: map,
          draggable: true,
        });
      }

      // Add click listener to map
      map.addListener('click', (e) => {
        placeMarker(e.latLng);
      });

      // Initialize autocomplete for search
      autocomplete = new google.maps.places.Autocomplete(document.getElementById('map_search'));
      autocomplete.bindTo('bounds', map);
      autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (!place.geometry) {
          return;
        }
        map.setCenter(place.geometry.location);
        map.setZoom(15);
        placeMarker(place.geometry.location);
      });
    }

    function placeMarker(location) {
      if (marker) {
        marker.setPosition(location);
      } else {
        marker = new google.maps.Marker({
          position: location,
          map: map,
          draggable: true,
        });
      }
      selectedLat = location.lat();
      selectedLng = location.lng();
      
      // Reverse geocode to get address
      geocoder.geocode({ location: location }, (results, status) => {
        if (status === 'OK' && results[0]) {
          document.getElementById('shop_address').value = results[0].formatted_address;
        }
      });
    }

    function searchAddress() {
      if (!geocoder) {
        alert('Map is not loaded yet. Please wait a moment and try again.');
        return;
      }
      
      const address = document.getElementById('map_search').value;
      if (address) {
        geocoder.geocode({ address: address }, (results, status) => {
          if (status === 'OK' && results[0]) {
            map.setCenter(results[0].geometry.location);
            map.setZoom(15);
            placeMarker(results[0].geometry.location);
            document.getElementById('shop_address').value = results[0].formatted_address;
          } else {
            alert('Address not found. Please try a different address.');
          }
        });
      }
    }

    function confirmLocation() {
      if (selectedLat && selectedLng) {
        document.getElementById('shop_latitude').value = selectedLat;
        document.getElementById('shop_longitude').value = selectedLng;
        closeMapPicker();
      } else {
        alert('Please select a location on the map first.');
      }
    }

    // Close modal when clicking outside
    document.getElementById('mapModal').addEventListener('click', (e) => {
      if (e.target.id === 'mapModal') {
        closeMapPicker();
      }
    });
  </script>
</body>
</html>
