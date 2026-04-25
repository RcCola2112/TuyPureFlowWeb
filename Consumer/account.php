<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';

// Use PHP redirect for reliability
if (!isset($_SESSION['consumer_id'])) {
    header("Location: ../index.html");
    exit;
}

$user_id = $_SESSION['consumer_id'];
$user_name = $_SESSION['consumer_name']; // will update to full_name below

// Fetch current user info
$stmt = $conn->prepare("SELECT * FROM consumer WHERE consumer_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$user_name = $user['full_name'];

$update_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $new_full_name = trim($_POST['full_name'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_contact_number = trim($_POST['contact_number'] ?? '');
    $new_username = trim($_POST['username'] ?? '');
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $profile_pic_data = null;
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
      $profile_pic_data = file_get_contents($_FILES['profile_pic']['tmp_name']);
    }
    if (!$new_full_name || !$new_email || !$new_contact_number || !$new_username) {
      $update_msg = "Full name, email, contact number, and username are required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $update_msg = "Invalid email address.";
    } elseif ($new_password && $new_password !== $confirm_password) {
      $update_msg = "Passwords do not match.";
    } else {
      $stmt = $conn->prepare("SELECT consumer_id FROM consumer WHERE (email = ? OR username = ?) AND consumer_id != ?");
      $stmt->execute([$new_email, $new_username, $user_id]);
      if ($stmt->fetch()) {
        $update_msg = "Email or username already in use.";
      } else {
        // Update fields
        if ($new_password) {
          $hashed = password_hash($new_password, PASSWORD_DEFAULT);
          if ($profile_pic_data) {
            $stmt = $conn->prepare("UPDATE consumer SET full_name = ?, email = ?, contact_number = ?, username = ?, password = ?, profile_pic = ? WHERE consumer_id = ?");
            $success = $stmt->execute([$new_full_name, $new_email, $new_contact_number, $new_username, $hashed, $profile_pic_data, $user_id]);
          } else {
            $stmt = $conn->prepare("UPDATE consumer SET full_name = ?, email = ?, contact_number = ?, username = ?, password = ? WHERE consumer_id = ?");
            $success = $stmt->execute([$new_full_name, $new_email, $new_contact_number, $new_username, $hashed, $user_id]);
          }
        } else {
          if ($profile_pic_data) {
            $stmt = $conn->prepare("UPDATE consumer SET full_name = ?, email = ?, contact_number = ?, username = ?, profile_pic = ? WHERE consumer_id = ?");
            $success = $stmt->execute([$new_full_name, $new_email, $new_contact_number, $new_username, $profile_pic_data, $user_id]);
          } else {
            $stmt = $conn->prepare("UPDATE consumer SET full_name = ?, email = ?, contact_number = ?, username = ? WHERE consumer_id = ?");
            $success = $stmt->execute([$new_full_name, $new_email, $new_contact_number, $new_username, $user_id]);
          }
        }
        if ($success) {
          $_SESSION['consumer_name'] = $new_full_name;
          $update_msg = "Account updated successfully!";
          $stmt = $conn->prepare("SELECT * FROM consumer WHERE consumer_id = ?");
          $stmt->execute([$user_id]);
          $user = $stmt->fetch();
        } else {
          $update_msg = "Update failed. Please try again.";
        }
      }
    }
}

// Fetch consumer addresses, default first
$stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ? ORDER BY is_default DESC, address_id ASC");
$stmt->execute([$user_id]);
$addresses = $stmt->fetchAll();

// --- NEW: Handle set default address ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_default_address'])) {
    $address_id = intval($_POST['address_id']);
    // unset previous defaults for this user
    $stmt = $conn->prepare("UPDATE address SET is_default = 0 WHERE consumer_id = ?");
    $stmt->execute([$user_id]);
    // set selected address as default (only if it belongs to user)
    $stmt = $conn->prepare("UPDATE address SET is_default = 1 WHERE address_id = ? AND consumer_id = ?");
    $stmt->execute([$address_id, $user_id]);
    header('Location: account.php');
    exit;
}

// Handle edit address
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_address'])) {
    $address_id = intval($_POST['address_id']);
    $street = trim($_POST['street'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');
    if ($street && $city && $region && $zip_code) {
        $stmt = $conn->prepare("UPDATE address SET street = ?, city = ?, region = ?, zip_code = ? WHERE address_id = ? AND consumer_id = ?");
        $stmt->execute([$street, $city, $region, $zip_code, $address_id, $user_id]);
        header('Location: account.php');
        exit;
    }
}

// Handle add address (via modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_address_modal'])) {
  $name = trim($_POST['name'] ?? $user['full_name'] ?? '');
  $contact_number = trim($_POST['contact_number'] ?? $user['contact_number'] ?? '');
  $street = trim($_POST['street'] ?? '');
  $barangay = trim($_POST['barangay'] ?? '');
  $city = trim($_POST['city'] ?? '');
  $region = trim($_POST['region'] ?? '');
  $zip_code = trim($_POST['zip_code'] ?? '');
  $latitude = $_POST['latitude'] ?? null;
  $longitude = $_POST['longitude'] ?? null;
  if (!$name) {
    $add_address_msg = "Name is required.";
  } elseif (!$contact_number) {
    $add_address_msg = "Contact number is required.";
  } elseif ($street && $barangay && $city && $region && $zip_code) {
    $stmt = $conn->prepare("INSERT INTO address (consumer_id, name, contact_number, street, barangay, city, region, zip_code, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $name, $contact_number, $street, $barangay, $city, $region, $zip_code, $latitude, $longitude]);
    header('Location: account.php');
    exit;
  }
}

$change_pass_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    // Fetch hashed password
    $stmt = $conn->prepare("SELECT password FROM consumer WHERE consumer_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$current_password || !$new_password || !$confirm_password) {
        $change_pass_msg = "All password fields are required.";
    } elseif (!password_verify($current_password, $row['password'])) {
        $change_pass_msg = "Current password is incorrect.";
    } elseif ($new_password !== $confirm_password) {
        $change_pass_msg = "New passwords do not match.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE consumer SET password = ? WHERE consumer_id = ?");
        if ($stmt->execute([$hashed, $user_id])) {
            $change_pass_msg = "Password changed successfully!";
        } else {
            $change_pass_msg = "Failed to change password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Account - Tuy PureFlow</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 font-sans">
  <!-- Header -->
  <header class="header-gradient sticky top-0 z-50">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
      <a href="landing_page.php" class="brand-link flex items-center gap-2 text-xl">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
        <span>Tuy PureFlow</span>
      </a>
      <div class="flex items-center gap-4">
        <?php include 'notification_icon.php'; ?>
        <?php
          // Fetch consumer profile picture
          $consumer_profile = null;
          $profile_stmt = $conn->prepare("SELECT profile_pic FROM consumer WHERE consumer_id = ? LIMIT 1");
          $profile_stmt->execute([$user_id]);
          $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);
          if ($profile_data && isset($profile_data['profile_pic'])) {
              $profile_blob = $profile_data['profile_pic'];
              
              // Check if it's NULL or empty
              if ($profile_blob === null || $profile_blob === '') {
                  $consumer_profile = '../images/default-profile.jpg';
              } else {
                  // Handle both string and binary data
                  if (is_resource($profile_blob)) {
                      $profile_blob = stream_get_contents($profile_blob);
                  }
                  
                  // Ensure it's a string and has content
                  if (is_string($profile_blob) && strlen($profile_blob) > 10) {
                      // Detect image type from magic bytes
                      $imgType = 'png'; // default
                      $firstBytes = substr($profile_blob, 0, 12);
                      
                      if (substr($firstBytes, 0, 2) === "\xFF\xD8") {
                          $imgType = 'jpeg';
                      } elseif (substr($firstBytes, 0, 4) === "\x89PNG") {
                          $imgType = 'png';
                      } elseif (substr($firstBytes, 0, 4) === "RIFF" && substr($firstBytes, 8, 4) === "WEBP") {
                          $imgType = 'webp';
                      }
                      
                      $consumer_profile = 'data:image/' . $imgType . ';base64,' . base64_encode($profile_blob);
                  } else {
                      $consumer_profile = '../images/default-profile.jpg';
                  }
              }
          } else {
              $consumer_profile = '../images/default-profile.jpg';
          }
        ?>
        <div class="flex items-center gap-2 text-white font-semibold px-3 py-2 rounded-lg hover:bg-white hover:bg-opacity-20 transition-all">
          <img src="<?= htmlspecialchars($consumer_profile) ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-md hover:scale-110 transition-transform cursor-pointer" onclick="openProfileModal()" onerror="this.src='../images/default-profile.jpg'">
          <a href="account.php" class="hover:underline">
            <span><?= htmlspecialchars($user_name) ?></span>
          </a>
        </div>
        <a href="logout.php" class="text-white hover:bg-white hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all font-medium">Logout</a>
      </div>
    </div>
  </header>

  <main class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-2 text-gradient">My Account</h1>
    <p class="text-gray-600 mb-8">Manage your profile, addresses, and preferences</p>
    <div class="grid md:grid-cols-4 gap-6">
      <!-- Sidebar -->
      <aside class="card md:col-span-1">
        <nav class="space-y-2">
          <a href="account.php" class="block px-4 py-3 rounded-lg bg-gradient-to-r from-cyan-400 to-blue-600 text-white font-semibold">
            <i class="fas fa-user-circle mr-2"></i>Account Info
          </a>
          <a href="my_purchases.php" class="block px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
            <i class="fas fa-shopping-bag mr-2"></i>My Purchases
          </a>
          <a href="notification.php" class="block px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
            <i class="fas fa-bell mr-2"></i>Notifications
          </a>
          <a href="logout.php" class="block px-4 py-3 rounded-lg text-red-600 hover:bg-red-50 transition-colors">
            <i class="fas fa-sign-out-alt mr-2"></i>Logout
          </a>
        </nav>
      </aside>

      <!-- Content -->
      <section class="md:col-span-3 space-y-6">
        <!-- Account Info Tabs/List -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
          <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
              <i class="fas fa-user-cog text-white text-xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Account Settings</h2>
          </div>
          <ul class="flex gap-2 mb-6 text-sm font-medium border-b border-gray-200">
            <li>
              <button type="button" class="px-6 py-3 focus:outline-none tab-btn text-gray-600 hover:text-cyan-600 transition-colors border-b-2 border-transparent hover:border-cyan-500" onclick="showTab('profileTab')">
                <i class="fas fa-user mr-2"></i>Profile
              </button>
            </li>
            <li>
              <button type="button" class="px-6 py-3 focus:outline-none tab-btn text-gray-600 hover:text-cyan-600 transition-colors border-b-2 border-transparent hover:border-cyan-500" onclick="showTab('addressTab')">
                <i class="fas fa-map-marker-alt mr-2"></i>Addresses
              </button>
            </li>
            <li>
              <button type="button" class="px-6 py-3 focus:outline-none tab-btn text-gray-600 hover:text-cyan-600 transition-colors border-b-2 border-transparent hover:border-cyan-500" onclick="showTab('passwordTab')">
                <i class="fas fa-lock mr-2"></i>Change Password
              </button>
            </li>
            <li>
              <button type="button" class="px-6 py-3 focus:outline-none tab-btn text-gray-600 hover:text-cyan-600 transition-colors border-b-2 border-transparent hover:border-cyan-500" onclick="showTab('trackOrderTab')">
                <i class="fas fa-map-marker-alt mr-2"></i>Track Order
              </button>
            </li>
            <li>
              <button type="button" class="px-6 py-3 focus:outline-none tab-btn text-gray-600 hover:text-cyan-600 transition-colors border-b-2 border-transparent hover:border-cyan-500" onclick="showTab('feedbackTab')">
                <i class="fas fa-comment-dots mr-2"></i>Feedback
              </button>
            </li>
            <li>
              <button type="button" class="px-6 py-3 focus:outline-none tab-btn text-gray-600 hover:text-cyan-600 transition-colors border-b-2 border-transparent hover:border-cyan-500" onclick="showTab('helpSupportTab')">
                <i class="fas fa-life-ring mr-2"></i>Help & Support
              </button>
            </li>
          </ul>
          <!-- Profile Tab -->
          <div id="profileTab" class="tab-content">
            <?php if ($update_msg): ?>
              <div class="mb-4 p-3 rounded-lg text-sm <?= strpos($update_msg, 'success') !== false ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                <i class="fas <?= strpos($update_msg, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($update_msg) ?>
              </div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
              <input type="hidden" name="update_account" value="1">
              <div class="flex items-center gap-6 p-6 bg-gray-50 rounded-lg border border-gray-200">
                <?php 
                  $profile_img = '../images/default-profile.jpg';
                  if (isset($user['profile_pic']) && $user['profile_pic'] !== null && $user['profile_pic'] !== '') {
                    $imgData = $user['profile_pic'];
                    // Handle both string and binary data
                    if (is_resource($imgData)) {
                        $imgData = stream_get_contents($imgData);
                    }
                    if (is_string($imgData) && strlen($imgData) > 10) {
                      $imgType = 'png';
                      $firstBytes = substr($imgData, 0, 12);
                      if (substr($firstBytes, 0, 2) === "\xFF\xD8") {
                          $imgType = 'jpeg';
                      } elseif (substr($firstBytes, 0, 4) === "\x89PNG") {
                          $imgType = 'png';
                      } elseif (substr($firstBytes, 0, 4) === "RIFF" && substr($firstBytes, 8, 4) === "WEBP") {
                          $imgType = 'webp';
                      }
                      $profile_img = 'data:image/' . $imgType . ';base64,' . base64_encode($imgData);
                    }
                  }
                ?>
                <img src="<?= htmlspecialchars($profile_img) ?>" alt="Profile Picture" class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-lg cursor-pointer hover:opacity-90 transition-opacity" onclick="openProfilePictureModal('<?= htmlspecialchars($profile_img) ?>')">
                <div class="flex-1">
                  <label class="block mb-2 text-sm font-semibold text-gray-700">Profile Picture</label>
                  <input type="file" name="profile_pic" accept="image/*" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all">
                  <div class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>Max size: 2MB. JPG, PNG, or WEBP formats.
                  </div>
                </div>
              </div>
              <div class="grid md:grid-cols-2 gap-6">
              <div>
                  <label class="block mb-2 text-sm font-semibold text-gray-700">
                    <i class="fas fa-user mr-2 text-cyan-600"></i>Full Name
                  </label>
                  <input type="text" name="full_name" required class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Enter your full name">
              </div>
              <div>
                  <label class="block mb-2 text-sm font-semibold text-gray-700">
                    <i class="fas fa-envelope mr-2 text-cyan-600"></i>Email Address
                  </label>
                  <input type="email" name="email" required class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your.email@example.com">
              </div>
              <div>
                  <label class="block mb-2 text-sm font-semibold text-gray-700">
                    <i class="fas fa-phone mr-2 text-cyan-600"></i>Contact Number
                  </label>
                  <input type="text" name="contact_number" required class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" placeholder="09XX XXX XXXX">
              </div>
              <div>
                  <label class="block mb-2 text-sm font-semibold text-gray-700">
                    <i class="fas fa-at mr-2 text-cyan-600"></i>Username
                  </label>
                  <input type="text" name="username" required class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" value="<?= htmlspecialchars($user['username'] ?? '') ?>" placeholder="Choose a username">
                </div>
              </div>
              <div class="flex justify-end pt-4 border-t border-gray-200">
                <button type="submit" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white py-3 px-8 rounded-lg font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2">
                  <i class="fas fa-save"></i> Save Changes
                </button>
              </div>
            </form>
          </div>
          <!-- Addresses Tab -->
          <div id="addressTab" class="tab-content hidden">
            <div class="flex justify-between items-center mb-6">
              <h3 class="text-lg font-semibold text-gray-800">Saved Addresses</h3>
              <button type="button" onclick="openAddAddressModal()" class="bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600 text-white px-6 py-2.5 rounded-lg font-semibold shadow-md hover:shadow-lg transition-all flex items-center gap-2">
                <i class="fas fa-plus"></i> Add New Address
              </button>
            </div>
              <div class="space-y-4">
              <?php if (empty($addresses)): ?>
                <div class="text-center py-12 bg-gray-50 rounded-lg border border-gray-200">
                  <i class="fas fa-map-marker-alt text-4xl text-gray-400 mb-4"></i>
                  <p class="text-gray-600 font-medium">No addresses saved yet</p>
                  <p class="text-sm text-gray-500 mt-2">Add your first address to get started</p>
                </div>
              <?php else: ?>
                <?php foreach ($addresses as $addr): ?>
                  <div class="border-2 rounded-xl p-5 <?= !empty($addr['is_default']) && $addr['is_default'] == 1 ? 'border-cyan-500 bg-cyan-50' : 'border-gray-200 bg-white' ?> hover:shadow-md transition-all">
                    <div class="flex justify-between items-start">
                      <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                          <i class="fas fa-map-marker-alt text-cyan-600 text-lg"></i>
                    <div>
                            <div class="font-bold text-gray-800 flex items-center gap-2">
                              <?= htmlspecialchars($addr['name']) ?>
                        <?php if (!empty($addr['is_default']) && $addr['is_default'] == 1): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 bg-cyan-500 text-white text-xs font-semibold rounded-full">
                                  <i class="fas fa-star mr-1"></i>Default
                                </span>
                        <?php endif; ?>
                      </div>
                            <div class="text-sm text-gray-600 mt-1">
                              <i class="fas fa-phone text-cyan-600 mr-1"></i><?= htmlspecialchars($addr['contact_number']) ?>
                            </div>
                          </div>
                        </div>
                        <div class="ml-8 text-sm text-gray-700">
                          <?= htmlspecialchars($addr['street']) ?>, <?= htmlspecialchars($addr['barangay']) ?><br>
                        <?= htmlspecialchars($addr['city']) ?>, <?= htmlspecialchars($addr['region']) ?> <?= htmlspecialchars($addr['zip_code']) ?>
                      </div>
                    </div>
                      <div class="flex items-center gap-2 ml-4">
                      <?php if (empty($addr['is_default']) || $addr['is_default'] != 1): ?>
                        <form method="POST" class="inline">
                          <input type="hidden" name="set_default_address" value="1">
                          <input type="hidden" name="address_id" value="<?= intval($addr['address_id']) ?>">
                            <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all shadow-sm hover:shadow">
                              <i class="fas fa-star mr-1"></i>Set Default
                            </button>
                        </form>
                      <?php endif; ?>
                      <button type="button"
                          class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all shadow-sm hover:shadow"
                        onclick="openEditAddressModal(
                          <?= intval($addr['address_id']) ?>,
                          '<?= htmlspecialchars($addr['name'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['contact_number'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['street'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['barangay'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['city'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['region'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['zip_code'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['latitude'], ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($addr['longitude'], ENT_QUOTES) ?>'
                        )">
                          <i class="fas fa-edit mr-1"></i>Edit
                      </button>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <!-- Change Password Tab -->
          <div id="passwordTab" class="tab-content hidden">
            <?php if ($change_pass_msg): ?>
              <div class="mb-6 p-4 rounded-lg text-sm <?= strpos($change_pass_msg, 'success') !== false ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                <i class="fas <?= strpos($change_pass_msg, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($change_pass_msg) ?>
              </div>
            <?php endif; ?>
            <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mb-6">
              <div class="flex items-center gap-3 mb-4">
                <i class="fas fa-shield-alt text-cyan-600 text-xl"></i>
                <div>
                  <h3 class="font-semibold text-gray-800">Password Security</h3>
                  <p class="text-sm text-gray-600">Use a strong password to protect your account</p>
                </div>
              </div>
              <ul class="text-sm text-gray-600 space-y-2 ml-8">
                <li><i class="fas fa-check text-green-500 mr-2"></i>At least 8 characters long</li>
                <li><i class="fas fa-check text-green-500 mr-2"></i>Mix of letters, numbers, and symbols</li>
                <li><i class="fas fa-check text-green-500 mr-2"></i>Avoid using personal information</li>
              </ul>
            </div>
            <form method="POST" class="space-y-6">
              <input type="hidden" name="change_password" value="1">
              <div>
                <label class="block mb-2 text-sm font-semibold text-gray-700">
                  <i class="fas fa-key mr-2 text-cyan-600"></i>Current Password
                </label>
                <div class="relative">
                  <input type="password" name="current_password" id="current_password" class="w-full border border-gray-300 rounded-lg px-4 py-3 pr-12 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" required placeholder="Enter your current password">
                  <span class="absolute inset-y-0 right-0 flex items-center pr-4 cursor-pointer" onclick="togglePassword('current_password', this)">
                    <i id="icon_current_password" class="fas fa-eye text-gray-500 hover:text-cyan-600 transition-colors"></i>
                  </span>
                </div>
              </div>
              <div>
                <label class="block mb-2 text-sm font-semibold text-gray-700">
                  <i class="fas fa-lock mr-2 text-cyan-600"></i>New Password
                </label>
                <div class="relative">
                  <input type="password" name="password" id="new_password" class="w-full border border-gray-300 rounded-lg px-4 py-3 pr-12 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" required placeholder="Enter your new password">
                  <span class="absolute inset-y-0 right-0 flex items-center pr-4 cursor-pointer" onclick="togglePassword('new_password', this)">
                    <i id="icon_new_password" class="fas fa-eye text-gray-500 hover:text-cyan-600 transition-colors"></i>
                  </span>
                </div>
              </div>
              <div>
                <label class="block mb-2 text-sm font-semibold text-gray-700">
                  <i class="fas fa-lock mr-2 text-cyan-600"></i>Confirm New Password
                </label>
                <div class="relative">
                  <input type="password" name="confirm_password" id="confirm_password" class="w-full border border-gray-300 rounded-lg px-4 py-3 pr-12 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all" required placeholder="Confirm your new password">
                  <span class="absolute inset-y-0 right-0 flex items-center pr-4 cursor-pointer" onclick="togglePassword('confirm_password', this)">
                    <i id="icon_confirm_password" class="fas fa-eye text-gray-500 hover:text-cyan-600 transition-colors"></i>
                  </span>
                </div>
              </div>
              <div class="flex justify-end pt-4 border-t border-gray-200">
                <button type="submit" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white py-3 px-8 rounded-lg font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2">
                  <i class="fas fa-key"></i> Change Password
                </button>
              </div>
            </form>
          </div>
          <!-- Track Order Tab -->
          <div id="trackOrderTab" class="tab-content hidden">
            <div id="trackOrderContent">
              <div class="text-center py-12">
                <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-cyan-600 mb-4"></div>
                <p class="text-gray-600">Loading order information...</p>
              </div>
            </div>
          </div>
          
          <!-- Feedback Tab -->
          <div id="feedbackTab" class="tab-content hidden">
            <div class="space-y-6">
              <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-xl p-6 border border-cyan-200">
                <div class="flex items-center gap-3 mb-2">
                  <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
                    <i class="fas fa-comment-dots text-white text-xl"></i>
                  </div>
                  <div>
                    <h3 class="text-2xl font-bold text-gray-800">Help us improve PureFlow</h3>
                    <p class="text-gray-600 mt-1">Tell us what you love and what we can do better.</p>
                  </div>
                </div>
              </div>
              
              <?php if ($feedback_msg): ?>
                <div class="p-4 rounded-lg text-sm <?= strpos($feedback_msg, 'Thank you') !== false ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                  <i class="fas <?= strpos($feedback_msg, 'Thank you') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                  <?= htmlspecialchars($feedback_msg) ?>
                </div>
              <?php endif; ?>
              
              <form method="POST" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                <input type="hidden" name="submit_feedback" value="1">
                
                <!-- Overall Experience Section -->
                <div class="mb-8">
                  <label class="block text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-star text-cyan-600 mr-2"></i>Overall experience
                  </label>
                  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <label class="experience-option cursor-pointer">
                      <input type="radio" name="experience_rating" value="excellent" class="hidden peer" required>
                      <div class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 bg-white hover:border-cyan-500 hover:bg-cyan-50 transition-all peer-checked:border-cyan-500 peer-checked:bg-cyan-100 peer-checked:shadow-md">
                        <div class="text-3xl mb-2">😊</div>
                        <span class="text-sm font-medium text-gray-700">Excellent</span>
                      </div>
                    </label>
                    <label class="experience-option cursor-pointer">
                      <input type="radio" name="experience_rating" value="good" class="hidden peer" required>
                      <div class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 bg-white hover:border-cyan-500 hover:bg-cyan-50 transition-all peer-checked:border-cyan-500 peer-checked:bg-cyan-100 peer-checked:shadow-md">
                        <div class="text-3xl mb-2">👍</div>
                        <span class="text-sm font-medium text-gray-700">Good</span>
                      </div>
                    </label>
                    <label class="experience-option cursor-pointer">
                      <input type="radio" name="experience_rating" value="okay" class="hidden peer" required>
                      <div class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 bg-white hover:border-cyan-500 hover:bg-cyan-50 transition-all peer-checked:border-cyan-500 peer-checked:bg-cyan-100 peer-checked:shadow-md">
                        <div class="text-3xl mb-2">😐</div>
                        <span class="text-sm font-medium text-gray-700">Okay</span>
                      </div>
                    </label>
                    <label class="experience-option cursor-pointer">
                      <input type="radio" name="experience_rating" value="poor" class="hidden peer" required>
                      <div class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 bg-white hover:border-cyan-500 hover:bg-cyan-50 transition-all peer-checked:border-cyan-500 peer-checked:bg-cyan-100 peer-checked:shadow-md">
                        <div class="text-3xl mb-2">😔</div>
                        <span class="text-sm font-medium text-gray-700">Poor</span>
                      </div>
                    </label>
                    <label class="experience-option cursor-pointer">
                      <input type="radio" name="experience_rating" value="terrible" class="hidden peer" required>
                      <div class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 bg-white hover:border-cyan-500 hover:bg-cyan-50 transition-all peer-checked:border-cyan-500 peer-checked:bg-cyan-100 peer-checked:shadow-md">
                        <div class="text-3xl mb-2">👎</div>
                        <span class="text-sm font-medium text-gray-700">Terrible</span>
                      </div>
                    </label>
                  </div>
                </div>
                
                <!-- Share Your Thoughts Section -->
                <div class="mb-6">
                  <label class="block text-lg font-semibold text-gray-800 mb-3">
                    <i class="fas fa-edit text-cyan-600 mr-2"></i>Share your thoughts
                  </label>
                  <textarea 
                    name="feedback_comments" 
                    rows="6" 
                    class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all resize-none" 
                    placeholder="Tell us about your delivery, the app, or anything else..."></textarea>
                </div>
                
                <div class="flex justify-end">
                  <button type="submit" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white py-3 px-8 rounded-xl font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2">
                    <i class="fas fa-paper-plane"></i> Submit Feedback
                  </button>
                </div>
              </form>
            </div>
          </div>
          
          <!-- Help & Support Tab -->
          <div id="helpSupportTab" class="tab-content hidden">
            <div class="space-y-6">
              <!-- Need Quick Help Section -->
              <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
                    <i class="fas fa-headset text-white text-xl"></i>
                  </div>
                  <div>
                    <h3 class="text-xl font-bold text-gray-800">Need quick help?</h3>
                    <p class="text-sm text-gray-600">We're here 7 days a week, 8am - 8pm</p>
                  </div>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                  <a href="tel:+639123456789" class="flex items-center justify-center gap-3 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white py-4 px-6 rounded-xl font-semibold shadow-md hover:shadow-lg transition-all">
                    <i class="fas fa-phone text-xl"></i>
                    Call Support
                  </a>
                  <a href="mailto:support@tuypureflow.com" class="flex items-center justify-center gap-3 bg-blue-100 hover:bg-blue-200 text-blue-700 py-4 px-6 rounded-xl font-semibold transition-all border-2 border-blue-300">
                    <i class="fas fa-envelope text-xl"></i>
                    Email Us
                  </a>
                </div>
              </div>
              
              <!-- Frequently Asked Questions Section -->
              <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                <div class="flex items-center gap-3 mb-6">
                  <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
                    <i class="fas fa-question-circle text-white text-xl"></i>
                  </div>
                  <h3 class="text-xl font-bold text-gray-800">Frequently Asked Questions</h3>
                </div>
                <div class="space-y-3">
                  <div class="faq-item border-2 border-gray-200 rounded-lg overflow-hidden">
                    <button type="button" onclick="toggleFAQ(this)" class="w-full flex items-center justify-between p-4 bg-white hover:bg-gray-50 transition-colors text-left">
                      <span class="font-semibold text-gray-800">How do I order through text?</span>
                      <i class="fas fa-chevron-down text-cyan-600 transition-transform"></i>
                    </button>
                    <div class="faq-content hidden px-4 pb-4 text-gray-600">
                      <p class="mb-2">You can place an order by sending a text message to the shop number. Here's how:</p>
                      <ol class="list-decimal list-inside space-y-1 ml-2">
                        <li>Find the shop number (e.g., 09197511005)</li>
                        <li>Send a text message with the following format:
                          <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                            <li>Gallon (quantity): [number]</li>
                            <li>Gallon type (Round Container, Container W/ faucet, Slim Container): [type]</li>
                          </ul>
                        </li>
                      </ol>
                      <p class="mt-3 font-semibold">Example message:</p>
                      <div class="bg-gray-100 p-3 rounded-lg mt-2 font-mono text-sm">
                        Gallon (quantity): 2<br>
                        Gallon type (Round Container, Container W/ faucet, Slim Container): Round Container
                      </div>
                      <p class="mt-3">The shop will process your order and confirm via text message.</p>
                    </div>
                  </div>
                  
                  <div class="faq-item border-2 border-gray-200 rounded-lg overflow-hidden">
                    <button type="button" onclick="toggleFAQ(this)" class="w-full flex items-center justify-between p-4 bg-white hover:bg-gray-50 transition-colors text-left">
                      <span class="font-semibold text-gray-800">How do I reschedule a delivery?</span>
                      <i class="fas fa-chevron-down text-cyan-600 transition-transform"></i>
                    </button>
                    <div class="faq-content hidden px-4 pb-4 text-gray-600">
                      <p>Go to Scheduled Orders, choose the order, and tap "Reschedule". You can pick a new date and time instantly.</p>
                    </div>
                  </div>
                  
                  <div class="faq-item border-2 border-gray-200 rounded-lg overflow-hidden">
                    <button type="button" onclick="toggleFAQ(this)" class="w-full flex items-center justify-between p-4 bg-white hover:bg-gray-50 transition-colors text-left">
                      <span class="font-semibold text-gray-800">What should I do if my order is delayed?</span>
                      <i class="fas fa-chevron-down text-cyan-600 transition-transform"></i>
                    </button>
                    <div class="faq-content hidden px-4 pb-4 text-gray-600">
                      <p>Use Track Order to check the rider location. If the status is stale, tap "Contact Rider" from the detail screen or message support below.</p>
                    </div>
                  </div>
                  
                  <div class="faq-item border-2 border-gray-200 rounded-lg overflow-hidden">
                    <button type="button" onclick="toggleFAQ(this)" class="w-full flex items-center justify-between p-4 bg-white hover:bg-gray-50 transition-colors text-left">
                      <span class="font-semibold text-gray-800">How can I update my delivery address?</span>
                      <i class="fas fa-chevron-down text-cyan-600 transition-transform"></i>
                    </button>
                    <div class="faq-content hidden px-4 pb-4 text-gray-600">
                      <p>Open Profile > Account Information > Change Address. You can pin your new location on the map and save it.</p>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Still Need Help Section -->
              <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-xl p-6 border border-cyan-200">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center">
                    <i class="fas fa-comments text-white text-xl"></i>
                  </div>
                  <div>
                    <h3 class="text-xl font-bold text-gray-800">Still need help?</h3>
                    <p class="text-sm text-gray-600">Send us a message and our team will reply within a few minutes.</p>
                  </div>
                </div>
                <button type="button" onclick="startLiveChat()" class="w-full bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white py-4 px-6 rounded-xl font-semibold shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-3">
                  <i class="fas fa-comment-dots text-xl"></i>
                  Start Live Chat
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <!-- Add Address Modal (Leaflet Map) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <div id="addAddressModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl p-6 relative overflow-y-auto max-h-screen">
    <button onclick="closeAddAddressModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl">&times;</button>
    <h2 class="text-lg font-semibold mb-4">Add Address</h2>
    <form method="POST" class="space-y-2" id="addAddressForm">
      <input type="hidden" name="add_address_modal" value="1">
      <div>
        <label class="block text-sm font-medium mb-1">Set location on map</label>
        <div id="leafletMap" class="w-full h-56 rounded border mt-3"></div>
        <div id="leafletLocationInfo" class="text-xs text-gray-500 mt-2">Click the map or drag the marker to select your address.</div>
      </div>
      <div class="grid grid-cols-2 gap-2 mt-3">
        <div>
          <label class="block mb-1 text-sm font-medium">Name</label>
          <input type="text" name="name" id="addr_name" required class="w-full border px-3 py-2 rounded" placeholder="Full Name">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Contact Number</label>
          <input type="text" name="contact_number" id="addr_contact" required class="w-full border px-3 py-2 rounded" placeholder="Contact Number">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Street</label>
          <input type="text" name="street" id="addr_street" required class="w-full border px-3 py-2 rounded" placeholder="Street">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Barangay</label>
          <input type="text" name="barangay" id="addr_barangay" required class="w-full border px-3 py-2 rounded" placeholder="Barangay">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">City</label>
          <input type="text" name="city" id="addr_city" required class="w-full border px-3 py-2 rounded" placeholder="City">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Region</label>
          <input type="text" name="region" id="addr_region" required class="w-full border px-3 py-2 rounded" placeholder="Region">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Zip Code</label>
          <input type="text" name="zip_code" id="addr_zip" required class="w-full border px-3 py-2 rounded" placeholder="Zip Code">
        </div>
      </div>
      <input type="hidden" name="latitude" id="addr_lat">
      <input type="hidden" name="longitude" id="addr_lng">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" onclick="closeAddAddressModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">Cancel</button>
        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded font-semibold">Add Address</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Address Modal -->

<div id="editAddressModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl p-6 relative overflow-y-auto max-h-screen">
    <button onclick="closeEditAddressModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl">&times;</button>
    <h2 class="text-lg font-semibold mb-4">Edit Address</h2>
    <form method="POST" class="space-y-2" id="editAddressForm">
      <input type="hidden" name="edit_address" value="1">
      <input type="hidden" name="address_id" id="edit_address_id">
      <div>
        <label class="block text-sm font-medium mb-1">Set location on map</label>
        <div id="editLeafletMap" class="w-full h-56 rounded border mt-3"></div>
        <div id="editLeafletLocationInfo" class="text-xs text-gray-500 mt-2">Click the map or drag the marker to select your address.</div>
      </div>
      <div class="grid grid-cols-2 gap-2 mt-3">
        <div>
          <label class="block mb-1 text-sm font-medium">Name</label>
          <input type="text" name="name" id="edit_addr_name" required class="w-full border px-3 py-2 rounded" placeholder="Full Name">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Contact Number</label>
          <input type="text" name="contact_number" id="edit_addr_contact" required class="w-full border px-3 py-2 rounded" placeholder="Contact Number">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Street</label>
          <input type="text" name="street" id="edit_addr_street" required class="w-full border px-3 py-2 rounded" placeholder="Street">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Barangay</label>
          <input type="text" name="barangay" id="edit_addr_barangay" required class="w-full border px-3 py-2 rounded" placeholder="Barangay">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">City</label>
          <input type="text" name="city" id="edit_addr_city" required class="w-full border px-3 py-2 rounded" placeholder="City">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Region</label>
          <input type="text" name="region" id="edit_addr_region" required class="w-full border px-3 py-2 rounded" placeholder="Region">
        </div>
        <div>
          <label class="block mb-1 text-sm font-medium">Zip Code</label>
          <input type="text" name="zip_code" id="edit_addr_zip" required class="w-full border px-3 py-2 rounded" placeholder="Zip Code">
        </div>
      </div>
      <input type="hidden" name="latitude" id="edit_addr_lat">
      <input type="hidden" name="longitude" id="edit_addr_lng">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" onclick="closeEditAddressModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">Cancel</button>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded font-semibold">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Profile Picture View Modal -->
<div id="profilePictureModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden" onclick="closeProfilePictureModal()">
  <div class="relative p-4" onclick="event.stopPropagation()">
    <button onclick="closeProfilePictureModal()" class="absolute top-4 right-4 text-white hover:text-red-400 text-3xl font-bold z-10 bg-black bg-opacity-50 rounded-full w-10 h-10 flex items-center justify-center transition-all">
      &times;
    </button>
    <div class="bg-white rounded-full p-2 shadow-2xl">
      <div class="w-96 h-96 rounded-full overflow-hidden border-8 border-white shadow-xl">
        <img id="profilePictureModalImage" src="" alt="Profile Picture" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</div>

<script>
  function openAddAddressModal() {
    document.getElementById('addAddressModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
  }
  function closeAddAddressModal() {
    document.getElementById('addAddressModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }
  // Leaflet Map for Edit Address Modal
  let editLeafletMap, editLeafletMarker, editLeafletMapInitialized = false;
  function openEditAddressModal(id, name, contact, street, barangay, city, region, zip, lat, lng) {
    document.getElementById('edit_address_id').value = id;
    document.getElementById('edit_addr_name').value = name;
    document.getElementById('edit_addr_contact').value = contact;
    document.getElementById('edit_addr_street').value = street;
    document.getElementById('edit_addr_barangay').value = barangay;
    document.getElementById('edit_addr_city').value = city;
    document.getElementById('edit_addr_region').value = region;
    document.getElementById('edit_addr_zip').value = zip;
    document.getElementById('edit_addr_lat').value = lat;
    document.getElementById('edit_addr_lng').value = lng;
    document.getElementById('editAddressModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    setTimeout(() => {
      const defaultLat = lat && !isNaN(parseFloat(lat)) ? parseFloat(lat) : 14.0169230;
      const defaultLng = lng && !isNaN(parseFloat(lng)) ? parseFloat(lng) : 120.7289851;
      if (!editLeafletMapInitialized) {
        editLeafletMapInitialized = true;
        editLeafletMap = L.map('editLeafletMap').setView([defaultLat, defaultLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(editLeafletMap);
        editLeafletMarker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(editLeafletMap);
        editLeafletMarker.on('dragend', function () {
          const latlng = editLeafletMarker.getLatLng();
          updateEditLeafletAddressFields(latlng.lat, latlng.lng);
        });
        editLeafletMap.on('click', function (e) {
          editLeafletMarker.setLatLng(e.latlng);
          updateEditLeafletAddressFields(e.latlng.lat, e.latlng.lng);
        });
        updateEditLeafletAddressFields(defaultLat, defaultLng);
        setTimeout(() => { editLeafletMap.invalidateSize(); }, 200);
      } else {
        editLeafletMarker.setLatLng([defaultLat, defaultLng]);
        editLeafletMap.setView([defaultLat, defaultLng], 14);
        updateEditLeafletAddressFields(defaultLat, defaultLng);
        setTimeout(() => { editLeafletMap.invalidateSize(); }, 200);
      }
    }, 200);
  }
  function updateEditLeafletAddressFields(lat, lng) {
    document.getElementById('edit_addr_lat').value = lat;
    document.getElementById('edit_addr_lng').value = lng;
    // Reverse geocode using Nominatim
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
      .then(res => res.json())
      .then(data => {
        const displayAddress = data.display_name || '';
        document.getElementById('editLeafletLocationInfo').innerHTML = `<div class='font-medium text-[#004c8c] mb-1'>${displayAddress}</div><div class='text-xs text-gray-600'>Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}</div>`;
        // Fill address fields if available
        if (data.address) {
          document.getElementById('edit_addr_street').value = data.address.road || data.address.pedestrian || data.address.house_number || '';
          document.getElementById('edit_addr_city').value = data.address.city || data.address.town || data.address.village || '';
          document.getElementById('edit_addr_region').value = data.address.state || data.address.region || '';
          document.getElementById('edit_addr_zip').value = data.address.postcode || '';
        }
      })
      .catch(() => {
        document.getElementById('editLeafletLocationInfo').innerHTML = `<div class='text-xs text-gray-600'>Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}</div>`;
      });
  }
  function closeEditAddressModal() {
    document.getElementById('editAddressModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }
  function showTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(function(tab) {
      tab.classList.add('hidden');
    });
    document.getElementById(tabId).classList.remove('hidden');
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
      btn.classList.remove('text-cyan-600', 'border-cyan-500');
      btn.classList.add('text-gray-600', 'border-transparent');
    });
    // Highlight active tab button
    let tabMap = {
      'profileTab': 'Profile',
      'addressTab': 'Addresses',
      'passwordTab': 'Change Password',
      'trackOrderTab': 'Track Order',
      'feedbackTab': 'Feedback',
      'helpSupportTab': 'Help & Support'
    };
    const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => btn.textContent.includes(tabMap[tabId]));
    if (activeBtn) {
      activeBtn.classList.add('text-cyan-600', 'border-cyan-500');
      activeBtn.classList.remove('text-gray-600', 'border-transparent');
    }
  }
  // Show Profile tab by default
  document.addEventListener('DOMContentLoaded', function() {
    showTab('profileTab');
  });

  // Profile Picture Modal Functions (for Profile tab image)
  function openProfilePictureModal(imgSrc) {
    document.getElementById('profilePictureModalImage').src = imgSrc;
    document.getElementById('profilePictureModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
  }

  function closeProfilePictureModal() {
    document.getElementById('profilePictureModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }

  // Close modal on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeProfilePictureModal();
    }
  });

  // Leaflet Map for Address Modal
  let leafletMap, leafletMarker, leafletMapInitialized = false;
  function openAddAddressModal() {
    document.getElementById('addAddressModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    setTimeout(() => {
      if (!leafletMapInitialized) {
        leafletMapInitialized = true;
        const defaultLat = 14.0169230, defaultLng = 120.7289851;
        leafletMap = L.map('leafletMap').setView([defaultLat, defaultLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(leafletMap);
        leafletMarker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(leafletMap);
        leafletMarker.on('dragend', function () {
          const latlng = leafletMarker.getLatLng();
          updateLeafletAddressFields(latlng.lat, latlng.lng);
        });
        leafletMap.on('click', function (e) {
          leafletMarker.setLatLng(e.latlng);
          updateLeafletAddressFields(e.latlng.lat, e.latlng.lng);
        });
        updateLeafletAddressFields(defaultLat, defaultLng);
        setTimeout(() => { leafletMap.invalidateSize(); }, 200);
      } else {
        setTimeout(() => { leafletMap.invalidateSize(); }, 200);
      }
    }, 200);
  }
  function closeAddAddressModal() {
    document.getElementById('addAddressModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }
  function updateLeafletAddressFields(lat, lng) {
    document.getElementById('addr_lat').value = lat;
    document.getElementById('addr_lng').value = lng;
    // Reverse geocode using Nominatim
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
      .then(res => res.json())
      .then(data => {
        const displayAddress = data.display_name || '';
        document.getElementById('leafletLocationInfo').innerHTML = `<div class='font-medium text-[#004c8c] mb-1'>${displayAddress}</div><div class='text-xs text-gray-600'>Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}</div>`;
        // Fill address fields if available
        if (data.address) {
          document.getElementById('addr_street').value = data.address.road || data.address.pedestrian || data.address.house_number || '';
          document.getElementById('addr_city').value = data.address.city || data.address.town || data.address.village || '';
          document.getElementById('addr_region').value = data.address.state || data.address.region || '';
          document.getElementById('addr_zip').value = data.address.postcode || '';
        }
      })
      .catch(() => {
        document.getElementById('leafletLocationInfo').innerHTML = `<div class='text-xs text-gray-600'>Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}</div>`;
      });
  }
function togglePassword(fieldId, iconSpan) {
  var input = document.getElementById(fieldId);
  var icon = iconSpan.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.remove('fa-eye');
    icon.classList.add('fa-eye-slash');
    icon.classList.add('text-cyan-600');
  } else {
    input.type = 'password';
    icon.classList.remove('fa-eye-slash');
    icon.classList.add('fa-eye');
    icon.classList.remove('text-cyan-600');
  }
}

  // Track Order Functions
  let trackOrderMap = null;
  let trackOrderMarkers = {};
  let trackOrderRoute = null;
  let trackOrderInterval = null;
  let currentOrderId = null;

  async function loadTrackOrder() {
    const consumerId = <?= $user_id ?>;
    const contentDiv = document.getElementById('trackOrderContent');
    
    try {
      // Fetch active order
      const response = await fetch(`../get_active_order.php?consumer_id=${consumerId}`);
      const data = await response.json();
      
      if (data.success && data.order) {
        const order = data.order;
        currentOrderId = order.order_id;
        
        // Display order information
        const statusBadge = getStatusBadge(order.status);
        const orderDate = new Date(order.order_date).toLocaleString('en-US', {
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit'
        });
        
        contentDiv.innerHTML = `
          <div class="space-y-6">
            <!-- Order Info Card -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
              <div class="flex items-center justify-between mb-4">
                <div>
                  <h3 class="text-2xl font-bold text-gray-800">Order #${order.order_id}</h3>
                  <p class="text-sm text-gray-500 mt-1">Ordered at: ${orderDate}</p>
                </div>
                <div class="flex flex-col items-end gap-2">
                  ${statusBadge}
                  ${order.delivery_number ? `<span class="inline-flex items-center px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-sm font-medium">
                    <i class="fas fa-box mr-1"></i>${order.delivery_number}${getOrdinalSuffix(order.delivery_number)} Delivery
                  </span>` : ''}
                </div>
              </div>
              
              <div class="grid md:grid-cols-2 gap-4 mt-4">
                <div class="p-4 bg-gray-50 rounded-lg">
                  <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-store text-cyan-600"></i>
                    <span class="font-semibold text-gray-700">Shop</span>
                  </div>
                  <p class="text-gray-800">${escapeHtml(order.business_name || 'N/A')}</p>
                  <p class="text-sm text-gray-600 mt-1">${escapeHtml(order.location || '')}</p>
                  <p class="text-sm text-gray-600"><i class="fas fa-phone mr-1"></i>${escapeHtml(order.contact_number || 'N/A')}</p>
                </div>
                
                <div class="p-4 bg-gray-50 rounded-lg">
                  <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-map-marker-alt text-green-600"></i>
                    <span class="font-semibold text-gray-700">Delivery Address</span>
                  </div>
                  <p class="text-gray-800">${escapeHtml(order.delivery_address || 'No address on file')}</p>
                  <p class="text-sm text-gray-600 mt-1">Total: ₱${parseFloat(order.total_amount || 0).toFixed(2)}</p>
                </div>
              </div>
              
              ${order.rider ? `
                <div class="mt-4 p-4 bg-gradient-to-r from-green-50 to-blue-50 rounded-lg border border-green-200">
                  <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-motorcycle text-green-600"></i>
                    <span class="font-semibold text-gray-700">Rider Information</span>
                  </div>
                  <p class="text-gray-800"><strong>${escapeHtml(order.rider.rider_name || 'N/A')}</strong></p>
                  <p class="text-sm text-gray-600"><i class="fas fa-phone mr-1"></i>${escapeHtml(order.rider.rider_phone || 'N/A')}</p>
                </div>
              ` : `
                <div class="mt-4 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                  <p class="text-yellow-800"><i class="fas fa-exclamation-triangle mr-2"></i>No rider assigned to this order yet</p>
                </div>
              `}
            </div>
            
            <!-- Map Card -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                  <i class="fas fa-map text-cyan-600"></i>
                  Delivery Tracking
                </h3>
                <button onclick="refreshRiderLocation()" class="px-4 py-2 bg-cyan-500 hover:bg-cyan-600 text-white rounded-lg text-sm font-medium transition-all flex items-center gap-2">
                  <i class="fas fa-sync-alt"></i> Refresh Location
                </button>
              </div>
              <div id="trackOrderMap" style="height: 500px; width: 100%; border-radius: 8px; overflow: hidden;"></div>
              <p id="riderLocationStatus" class="text-sm text-gray-600 mt-2"></p>
            </div>
            
            <!-- Order Controls -->
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
              <h3 class="text-lg font-semibold text-gray-800 mb-4">Order Controls</h3>
              <div class="flex gap-4">
                <button onclick="modifyOrder(${order.order_id})" class="flex-1 px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-lg font-semibold transition-all flex items-center justify-center gap-2">
                  <i class="fas fa-edit"></i> Modify Order
                </button>
                ${order.status === 'Pending' ? `
                  <button onclick="cancelOrder(${order.order_id})" class="flex-1 px-6 py-3 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-times"></i> Cancel Order
                  </button>
                ` : ''}
              </div>
            </div>
          </div>
        `;
        
        // Initialize map
        setTimeout(() => {
          initializeTrackOrderMap(order);
          // Start auto-refresh for rider location
          if (order.status === 'Out for Delivery' || order.status === 'Processing') {
            startRiderLocationRefresh(order.order_id);
          }
        }, 100);
        
      } else {
        // No active order
        contentDiv.innerHTML = `
          <div class="text-center py-16 bg-white rounded-xl shadow-lg border border-gray-100">
            <div class="mx-auto w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
              <i class="fas fa-shopping-bag text-4xl text-gray-400"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Active Orders</h3>
            <p class="text-gray-500 mb-6">You don't have any active orders to track right now.</p>
            <a href="my_purchases.php" class="inline-block px-6 py-3 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white rounded-lg font-semibold transition-all">
              <i class="fas fa-shopping-bag mr-2"></i>View My Purchases
            </a>
          </div>
        `;
      }
    } catch (error) {
      console.error('Error loading track order:', error);
      contentDiv.innerHTML = `
        <div class="text-center py-16 bg-red-50 rounded-xl border border-red-200">
          <i class="fas fa-exclamation-circle text-4xl text-red-500 mb-4"></i>
          <h3 class="text-xl font-semibold text-red-700 mb-2">Error Loading Order</h3>
          <p class="text-red-600">${escapeHtml(error.message)}</p>
          <button onclick="loadTrackOrder()" class="mt-4 px-6 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg">Retry</button>
        </div>
      `;
    }
  }
  
  function getStatusBadge(status) {
    const badges = {
      'Pending': 'bg-yellow-100 text-yellow-700',
      'Processing': 'bg-blue-100 text-blue-700',
      'Out for Delivery': 'bg-green-100 text-green-700',
      'Completed': 'bg-green-100 text-green-700',
      'Cancelled': 'bg-red-100 text-red-700'
    };
    const colors = badges[status] || 'bg-gray-100 text-gray-700';
    return `<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${colors}">${escapeHtml(status)}</span>`;
  }
  
  function getOrdinalSuffix(num) {
    const j = num % 10;
    const k = num % 100;
    if (j === 1 && k !== 11) return 'st';
    if (j === 2 && k !== 12) return 'nd';
    if (j === 3 && k !== 13) return 'rd';
    return 'th';
  }
  
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  function initializeTrackOrderMap(order) {
    // Clear existing map if any
    if (trackOrderMap) {
      trackOrderMap.remove();
    }
    
    // Determine center point
    let centerLat = 14.0169230; // Default to Tuy, Batangas
    let centerLng = 120.7289851;
    let zoom = 13;
    
    if (order.delivery_latitude && order.delivery_longitude) {
      centerLat = parseFloat(order.delivery_latitude);
      centerLng = parseFloat(order.delivery_longitude);
      zoom = 14;
    } else if (order.shop_latitude && order.shop_longitude) {
      centerLat = parseFloat(order.shop_latitude);
      centerLng = parseFloat(order.shop_longitude);
    }
    
    // Initialize map
    trackOrderMap = L.map('trackOrderMap').setView([centerLat, centerLng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap'
    }).addTo(trackOrderMap);
    
    // Add shop marker
    if (order.shop_latitude && order.shop_longitude) {
      const shopIcon = L.divIcon({
        className: 'custom-marker',
        html: '<div style="background: #3b82f6; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-store" style="color: white; font-size: 14px;"></i></div>',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
      });
      trackOrderMarkers.shop = L.marker([parseFloat(order.shop_latitude), parseFloat(order.shop_longitude)], {
        icon: shopIcon
      }).addTo(trackOrderMap).bindPopup(`<strong>${escapeHtml(order.business_name || 'Shop')}</strong><br>${escapeHtml(order.location || '')}`);
    }
    
    // Add delivery address marker
    if (order.delivery_latitude && order.delivery_longitude) {
      const deliveryIcon = L.divIcon({
        className: 'custom-marker',
        html: '<div style="background: #10b981; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-map-marker-alt" style="color: white; font-size: 14px;"></i></div>',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
      });
      trackOrderMarkers.delivery = L.marker([parseFloat(order.delivery_latitude), parseFloat(order.delivery_longitude)], {
        icon: deliveryIcon
      }).addTo(trackOrderMap).bindPopup(`<strong>Delivery Address</strong><br>${escapeHtml(order.delivery_address || '')}`);
    }
    
    // Fetch and display rider location
    if (order.order_id) {
      fetchRiderLocation(order.order_id);
    }
    
    // Fit map to show all markers
    if (Object.keys(trackOrderMarkers).length > 0) {
      const group = new L.featureGroup(Object.values(trackOrderMarkers));
      trackOrderMap.fitBounds(group.getBounds().pad(0.1));
    }
  }
  
  async function fetchRiderLocation(orderId) {
    try {
      const response = await fetch(`../get_rider_location.php?order_id=${orderId}`);
      const data = await response.json();
      
      const statusDiv = document.getElementById('riderLocationStatus');
      
      if (data.success && data.data) {
        const riderData = data.data;
        
        // Update status message
        if (riderData.rider) {
          statusDiv.innerHTML = `<i class="fas fa-motorcycle text-green-600 mr-2"></i><strong>${escapeHtml(riderData.rider.name)}</strong> is on the way`;
        } else {
          statusDiv.innerHTML = `<i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>No rider assigned to this order yet`;
        }
        
        // Add/update rider marker if location is available
        if (riderData.location && riderData.location.latitude && riderData.location.longitude) {
          const lat = parseFloat(riderData.location.latitude);
          const lng = parseFloat(riderData.location.longitude);
          
          // Remove existing rider marker
          if (trackOrderMarkers.rider) {
            trackOrderMap.removeLayer(trackOrderMarkers.rider);
          }
          
          // Add rider marker
          const riderIcon = L.divIcon({
            className: 'custom-marker',
            html: '<div style="background: #f59e0b; width: 35px; height: 35px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; animation: pulse 2s infinite;"><i class="fas fa-motorcycle" style="color: white; font-size: 16px;"></i></div>',
            iconSize: [35, 35],
            iconAnchor: [17, 17]
          });
          
          trackOrderMarkers.rider = L.marker([lat, lng], {
            icon: riderIcon
          }).addTo(trackOrderMap);
          
          if (riderData.rider) {
            trackOrderMarkers.rider.bindPopup(`
              <strong>${escapeHtml(riderData.rider.name)}</strong><br>
              <i class="fas fa-phone"></i> ${escapeHtml(riderData.rider.phone || 'N/A')}<br>
              ${riderData.location.is_recent ? '<span class="text-green-600">Location updated recently</span>' : '<span class="text-yellow-600">Location may be outdated</span>'}
            `);
          }
          
          // Draw route if delivery address is available
          if (riderData.delivery && trackOrderMarkers.delivery) {
            drawRoute([lat, lng], [
              parseFloat(riderData.delivery.latitude),
              parseFloat(riderData.delivery.longitude)
            ]);
          }
          
          // Update status with distance if available
          if (riderData.order && riderData.order.distance_to_destination_km) {
            statusDiv.innerHTML += ` • ${riderData.order.distance_to_destination_km} km away`;
          }
        } else {
          statusDiv.innerHTML = `<i class="fas fa-info-circle text-gray-600 mr-2"></i>Rider location not available yet`;
        }
      } else {
        statusDiv.innerHTML = `<i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>${escapeHtml(data.message || 'Unable to fetch rider location')}`;
      }
    } catch (error) {
      console.error('Error fetching rider location:', error);
      const statusDiv = document.getElementById('riderLocationStatus');
      if (statusDiv) {
        statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-red-600 mr-2"></i>Error loading rider location`;
      }
    }
  }
  
  async function drawRoute(origin, destination) {
    try {
      const response = await fetch(`../get_simulated_route.php?origin=${origin[0]},${origin[1]}&destination=${destination[0]},${destination[1]}`);
      const data = await response.json();
      
      if (data.success && data.route && data.route.coordinates) {
        // Remove existing route
        if (trackOrderRoute) {
          trackOrderMap.removeLayer(trackOrderRoute);
        }
        
        // Convert coordinates to LatLng array
        const latlngs = data.route.coordinates.map(coord => [coord.latitude, coord.longitude]);
        
        // Draw route line
        trackOrderRoute = L.polyline(latlngs, {
          color: '#3b82f6',
          weight: 4,
          opacity: 0.7
        }).addTo(trackOrderMap);
        
        // Fit map to show route
        trackOrderMap.fitBounds(trackOrderRoute.getBounds().pad(0.1));
      }
    } catch (error) {
      console.error('Error drawing route:', error);
    }
  }
  
  function startRiderLocationRefresh(orderId) {
    // Clear existing interval
    if (trackOrderInterval) {
      clearInterval(trackOrderInterval);
    }
    
    // Refresh every 10 seconds
    trackOrderInterval = setInterval(() => {
      if (currentOrderId === orderId) {
        fetchRiderLocation(orderId);
      }
    }, 10000);
  }
  
  function refreshRiderLocation() {
    if (currentOrderId) {
      fetchRiderLocation(currentOrderId);
    }
  }
  
  function modifyOrder(orderId) {
    alert('Modify order functionality will be implemented soon.');
  }
  
  function cancelOrder(orderId) {
    if (confirm('Are you sure you want to cancel this order?')) {
      fetch('my_purchases.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax_cancel_order_id=${orderId}`
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          alert('Order cancelled successfully.');
          loadTrackOrder();
        } else {
          alert('Failed to cancel order.');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('Error cancelling order.');
      });
    }
  }
  
  // Override showTab to load track order when tab is shown
  const originalShowTab = window.showTab;
  window.showTab = function(tabId) {
    if (typeof originalShowTab === 'function') {
      originalShowTab(tabId);
    } else {
      // Fallback if originalShowTab is not available
      document.querySelectorAll('.tab-content').forEach(function(tab) {
        tab.classList.add('hidden');
      });
      document.getElementById(tabId).classList.remove('hidden');
      document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.classList.remove('text-cyan-600', 'border-cyan-500');
        btn.classList.add('text-gray-600', 'border-transparent');
      });
      let tabMap = {
        'profileTab': 'Profile',
        'addressTab': 'Addresses',
        'passwordTab': 'Change Password',
        'trackOrderTab': 'Track Order',
        'feedbackTab': 'Feedback',
        'helpSupportTab': 'Help & Support'
      };
      const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => btn.textContent.includes(tabMap[tabId]));
      if (activeBtn) {
        activeBtn.classList.add('text-cyan-600', 'border-cyan-500');
        activeBtn.classList.remove('text-gray-600', 'border-transparent');
      }
    }
    
    if (tabId === 'trackOrderTab') {
      loadTrackOrder();
    } else {
      // Clean up when leaving track order tab
      if (trackOrderInterval) {
        clearInterval(trackOrderInterval);
        trackOrderInterval = null;
      }
      if (trackOrderMap) {
        trackOrderMap.remove();
        trackOrderMap = null;
      }
      trackOrderMarkers = {};
      trackOrderRoute = null;
      currentOrderId = null;
    }
  };
  
  // FAQ Toggle Function
  function toggleFAQ(button) {
    const faqItem = button.closest('.faq-item');
    const content = faqItem.querySelector('.faq-content');
    const icon = button.querySelector('i');
    
    // Close all other FAQs
    document.querySelectorAll('.faq-item').forEach(item => {
      if (item !== faqItem) {
        item.querySelector('.faq-content').classList.add('hidden');
        item.querySelector('i').classList.remove('fa-chevron-up');
        item.querySelector('i').classList.add('fa-chevron-down');
      }
    });
    
    // Toggle current FAQ
    content.classList.toggle('hidden');
    icon.classList.toggle('fa-chevron-up');
    icon.classList.toggle('fa-chevron-down');
  }
  
  // Live Chat Function
  function startLiveChat() {
    alert('Live chat feature will be available soon. For now, please use Email or Call Support.');
    // Future implementation: Open chat interface
  }
  
  // Experience Rating Selection Handler
  document.addEventListener('DOMContentLoaded', function() {
    const experienceOptions = document.querySelectorAll('.experience-option input[type="radio"]');
    experienceOptions.forEach(option => {
      option.addEventListener('change', function() {
        // Remove checked class from all options
        document.querySelectorAll('.experience-option').forEach(opt => {
          opt.querySelector('div').classList.remove('peer-checked:border-cyan-500', 'peer-checked:bg-cyan-100', 'peer-checked:shadow-md');
        });
        // Add checked class to selected option
        if (this.checked) {
          this.closest('.experience-option').querySelector('div').classList.add('border-cyan-500', 'bg-cyan-100', 'shadow-md');
        }
      });
    });
  });
</script>

<style>
  @keyframes pulse {
    0%, 100% {
      opacity: 1;
    }
    50% {
      opacity: 0.7;
    }
  }
  
  /* Experience Rating Styles */
  .experience-option input[type="radio"]:checked + div {
    border-color: #06b6d4 !important;
    background-color: #cffafe !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
  }
  
  /* FAQ Animation */
  .faq-content {
    transition: all 0.3s ease-in-out;
  }
  
  .faq-item button i {
    transition: transform 0.3s ease;
  }
  
  /* Experience Rating Styles */
  .experience-option input[type="radio"]:checked + div {
    border-color: #06b6d4 !important;
    background-color: #cffafe !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
  }
  
  /* FAQ Animation */
  .faq-content {
    transition: all 0.3s ease-in-out;
  }
  
  .faq-item button i {
    transition: transform 0.3s ease;
  }
</style>

<!-- Load Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <!-- Footer -->
  <footer class="mt-16 bg-gray-900 text-white py-12">
    <div class="container mx-auto px-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
        <div class="md:col-span-2">
          <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
            <i class="fas fa-tint text-cyan-400"></i>
            Tuy PureFlow
          </h3>
          <p class="text-gray-400 text-sm mb-4">Your trusted source for clean, pure water. Delivered fresh to your doorstep.</p>
          <div class="flex gap-4">
            <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
              <i class="fab fa-facebook-f"></i>
            </a>
            <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
              <i class="fab fa-twitter"></i>
            </a>
            <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
              <i class="fab fa-instagram"></i>
            </a>
          </div>
        </div>
        <div>
          <h4 class="font-semibold mb-4">Quick Links</h4>
          <ul class="space-y-2 text-sm text-gray-400">
            <li><a href="landing_page.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>Browse Shops</a></li>
            <li><a href="account.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>My Account</a></li>
            <li><a href="my_purchases.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>My Orders</a></li>
            <li><a href="cart.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>Shopping Cart</a></li>
          </ul>
        </div>
        <div>
          <h4 class="font-semibold mb-4">Contact Us</h4>
          <ul class="space-y-3 text-sm text-gray-400">
            <li class="flex items-center gap-2">
              <i class="fas fa-envelope text-cyan-400"></i>
              <span>support@tuypureflow.com</span>
            </li>
            <li class="flex items-center gap-2">
              <i class="fas fa-phone text-cyan-400"></i>
              <span>+63 XXX XXX XXXX</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fas fa-map-marker-alt text-cyan-400 mt-1"></i>
              <span>Tuy, Batangas, Philippines</span>
            </li>
          </ul>
        </div>
      </div>
      <div class="border-t border-gray-800 pt-8">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
          <p class="text-sm text-gray-400">&copy; <?= date('Y') ?> Tuy PureFlow. All rights reserved.</p>
          <div class="flex gap-6 text-sm text-gray-400">
            <a href="#" class="hover:text-cyan-400 transition-colors">Privacy Policy</a>
            <a href="#" class="hover:text-cyan-400 transition-colors">Terms of Service</a>
            <a href="#" class="hover:text-cyan-400 transition-colors">About Us</a>
          </div>
        </div>
      </div>
    </div>
  </footer>
</body>
</html>
