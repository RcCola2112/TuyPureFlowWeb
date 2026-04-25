<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';
// Dummy config values
$notification = 'Enabled';
$maintenance = false;
$fraudThreshold = 80;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>System Settings</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .settings-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 4px 24px rgba(59,130,246,0.08);
      transition: box-shadow 0.2s;
    }
    .settings-card:hover {
      box-shadow: 0 8px 32px rgba(59,130,246,0.12);
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
      box-shadow: 0 8px 20px rgba(59,130,246,0.3);
    }
    .fade-in {
      animation: fadeIn 0.4s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body class="flex bg-gray-100 min-h-screen">
  <?php include 'sidebar.php'; ?>
  <div class="ml-64 flex flex-col flex-1">
    <?php include 'header.php'; ?>
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8 fade-in">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-cog text-blue-600"></i>
          System Settings
        </h1>
        <p class="text-gray-600">Manage admin accounts and your profile</p>
      </div>
        <!-- Notification, Maintenance, and Fraud Threshold section removed as requested -->

        <!-- Fetch current admin info for update form -->
        <?php
        $admin_id = $_SESSION['admin_id'] ?? null;
        $current_username = '';
        $current_email = '';
        $current_profile_picture = '';
        if ($admin_id) {
          $stmt = $conn->prepare("SELECT username, email, profile_picture FROM admin WHERE admin_id = ? LIMIT 1");
          $stmt->execute([$admin_id]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $current_username = $row['username'];
            $current_email = $row['email'];
            $current_profile_picture = $row['profile_picture'] ?? '';
          }
        }
        ?>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 fade-in">
        <!-- Update My Account Card -->
        <div class="settings-card p-6">
          <div class="flex items-center gap-4 mb-6">
            <div class="section-icon">
              <i class="fas fa-user"></i>
            </div>
            <div>
              <h2 class="text-2xl font-bold text-gray-800">Update My Account</h2>
              <p class="text-sm text-gray-500">Update your personal details</p>
            </div>
          </div>
          <form method="post" action="" enctype="multipart/form-data" class="space-y-6">
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">Profile Picture</label>
              <?php if ($current_profile_picture): ?>
                <img src="<?= htmlspecialchars($current_profile_picture) ?>" alt="Profile Picture" class="h-20 w-20 rounded-full mb-2 object-cover border">
              <?php else: ?>
                <div class="h-20 w-20 rounded-full mb-2 bg-gray-200 flex items-center justify-center text-gray-400">No Image</div>
              <?php endif; ?>
              <input type="file" name="update_profile_picture" accept="image/*" class="block mt-2">
            </div>
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">Username</label>
              <input type="text" name="update_username" class="border rounded px-4 py-2 w-full" value="<?= htmlspecialchars($current_username) ?>" required>
            </div>
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">Email</label>
              <input type="email" name="update_email" class="border rounded px-4 py-2 w-full" value="<?= htmlspecialchars($current_email) ?>" required>
            </div>
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">New Password</label>
              <input type="password" name="update_password" class="border rounded px-4 py-2 w-full" placeholder="Leave blank to keep current password">
            </div>
            <div>
              <button type="submit" name="update_admin" class="btn-primary text-white px-6 py-2 rounded">Update Account</button>
            </div>
          </form>
          <?php
          // Handle update own admin info
          if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
            $username = $_POST['update_username'];
            $email = $_POST['update_email'];
            $admin_id = $_SESSION['admin_id'] ?? null;
            $profile_picture_path = $current_profile_picture;
            // Handle profile picture upload
            if (isset($_FILES['update_profile_picture']) && $_FILES['update_profile_picture']['error'] === UPLOAD_ERR_OK) {
              $upload_dir = '../images/admin_profiles/';
              if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
              $ext = pathinfo($_FILES['update_profile_picture']['name'], PATHINFO_EXTENSION);
              $filename = 'admin_' . $admin_id . '_' . time() . '.' . $ext;
              $target = $upload_dir . $filename;
              if (move_uploaded_file($_FILES['update_profile_picture']['tmp_name'], $target)) {
                $profile_picture_path = $target;
              }
            }
            if ($admin_id) {
              if (!empty($_POST['update_password'])) {
                $password = password_hash($_POST['update_password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admin SET username=?, email=?, password=?, profile_picture=? WHERE admin_id=?");
                $success = $stmt->execute([$username, $email, $password, $profile_picture_path, $admin_id]);
              } else {
                $stmt = $conn->prepare("UPDATE admin SET username=?, email=?, profile_picture=? WHERE admin_id=?");
                $success = $stmt->execute([$username, $email, $profile_picture_path, $admin_id]);
              }
              if ($success) {
                $_SESSION['admin_name'] = $username;
                $_SESSION['admin_email'] = $email;
                if ($profile_picture_path) $_SESSION['admin_profile_picture'] = $profile_picture_path;
                echo '<div class="mt-4 text-green-600">Account updated successfully!</div>';
              } else {
                echo '<div class="mt-4 text-red-600">Error updating account.</div>';
              }
            }
          }
          ?>
        </div>
        <!-- Add Admin Account Card -->
        <div class="settings-card p-6">
          <div class="flex items-center gap-4 mb-6">
            <div class="section-icon">
              <i class="fas fa-users"></i>
            </div>
            <div>
              <h2 class="text-2xl font-bold text-gray-800">Add Admin Account</h2>
              <p class="text-sm text-gray-500">Create a new admin user</p>
            </div>
          </div>
          <form method="post" action="" class="space-y-6">
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">Full Name</label>
              <input type="text" name="admin_name" class="border rounded px-4 py-2 w-full" required>
            </div>
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">Email</label>
              <input type="email" name="admin_email" class="border rounded px-4 py-2 w-full" required>
            </div>
            <div class="input-group">
              <label class="block text-gray-700 font-semibold mb-2">Password</label>
              <input type="password" name="admin_password" class="border rounded px-4 py-2 w-full" required>
            </div>
            <div>
              <button type="submit" class="btn-primary text-white px-6 py-2 rounded">Add Admin</button>
            </div>
          </form>
          <?php
          // Handle add admin form submission
          if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_name'], $_POST['admin_email'], $_POST['admin_password'])) {
            $name = $_POST['admin_name'];
            $email = $_POST['admin_email'];
            $password = password_hash($_POST['admin_password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admin (name, email, password) VALUES (?, ?, ?)");
            if ($stmt->execute([$name, $email, $password])) {
              echo '<div class="mt-4 text-green-600">Admin added successfully!</div>';
            } else {
              echo '<div class="mt-4 text-red-600">Error adding admin.</div>';
            }
          }
          ?>
        </div>
      </div>
      <!-- List Admin Accounts Card -->
      <div class="settings-card p-6 mt-8 fade-in">
        <div class="flex items-center gap-4 mb-6">
          <div class="section-icon">
            <i class="fas fa-list"></i>
          </div>
          <div>
            <h2 class="text-2xl font-bold text-gray-800">Admin Accounts</h2>
            <p class="text-sm text-gray-500">List of all admin users</p>
          </div>
        </div>
        <table class="min-w-full bg-white rounded-lg shadow mb-4">
          <thead>
            <tr class="bg-gray-100 text-gray-700">
              <th class="py-2 px-4 text-left">Name</th>
              <th class="py-2 px-4 text-left">Email</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $stmt = $conn->query("SELECT username, email FROM admin");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $admin) {
              echo '<tr class="border-b">';
              echo '<td class="py-2 px-4">' . htmlspecialchars($admin['username']) . '</td>';
              echo '<td class="py-2 px-4">' . htmlspecialchars($admin['email']) . '</td>';
              echo '</tr>';
            }
            ?>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</body>
</html>
