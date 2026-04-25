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
$currentPage = 'inventory';

// Get distributor info from session or database
$distributor_id = $_SESSION['distributor_id'] ?? 1;
$stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
$stmt->execute([$distributor_id]);
$distributor = $stmt->fetch();

$username = $distributor['name'] ?? '';
$stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
$stmtShop->execute([$distributor_id]);
$shopname = $stmtShop->fetchColumn() ?: '';
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

// Handle add item form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
  $container_name = trim($_POST['item_type'] ?? '');
  $price_with_container = floatval($_POST['price_new'] ?? 0);
  $price_refill = floatval($_POST['price_refill'] ?? 0);
  $item_stock = intval($_POST['item_stock'] ?? 0);
  $damaged_container = intval($_POST['damaged_container'] ?? 0);
  $missing_container = intval($_POST['missing_container'] ?? 0);

  // Handle container image upload (store as longblob)
  $container_image_blob = null;
  if (isset($_FILES['container_image']) && $_FILES['container_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['container_image'];
    if ($file['error'] === UPLOAD_ERR_OK) {
      $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if (in_array($ext, $allowed_ext, true) && is_uploaded_file($file['tmp_name'])) {
        $container_image_blob = file_get_contents($file['tmp_name']);
      }
    }
  }

  // Get the shop_id for this distributor (assuming one shop per distributor)
  $shop_stmt = $conn->prepare("SELECT shop_id FROM shop WHERE distributor_id = ? LIMIT 1");
  $shop_stmt->execute([$distributor_id]);
  $shop_id = $shop_stmt->fetchColumn();

  // Check if the container type already exists for this shop
  $check_stmt = $conn->prepare("SELECT container_id FROM container WHERE shop_id = ? AND container_name = ?");
  $check_stmt->execute([$shop_id, $container_name]);
  $existing_id = $check_stmt->fetchColumn();

  if ($existing_id) {
    // Update existing container
    $update_stmt = $conn->prepare("UPDATE container SET price_with_container=?, price_refill=?, stock_quantity=?, damaged_container=?, missing_container=?, container_image=? WHERE container_id=?");
    $update_stmt->execute([
      $price_with_container,
      $price_refill,
      $item_stock,
      $damaged_container,
      $missing_container,
      $container_image_blob,
      $existing_id
    ]);
  } else {
    // Insert new container
    $container_type_id_stmt = $conn->prepare("SELECT container_type_id FROM container_type WHERE type = ? LIMIT 1");
    $container_type_id_stmt->execute([$container_name]);
    $container_type_id = $container_type_id_stmt->fetchColumn();
    $insert_stmt = $conn->prepare("INSERT INTO container (shop_id, container_name, container_type_id, price_with_container, price_refill, stock_quantity, damaged_container, missing_container, container_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert_stmt->execute([
      $shop_id,
      $container_name,
      $container_type_id,
      $price_with_container,
      $price_refill,
      $item_stock,
      $damaged_container,
      $missing_container,
      $container_image_blob
    ]);
  }
  header('Location: inventory.php');
  exit;
}
$edit_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $edit_id = intval($_POST['edit_container_id'] ?? 0);
    $edit_type = trim($_POST['edit_item_type'] ?? '');
    $edit_price_new = floatval($_POST['edit_price_new'] ?? 0);
    $edit_price_refill = floatval($_POST['edit_price_refill'] ?? 0);
    $edit_stock = intval($_POST['edit_item_stock'] ?? 0);
    $edit_damaged = intval($_POST['edit_damaged_container'] ?? 0);
    $edit_missing = intval($_POST['edit_missing_container'] ?? 0);
    if ($edit_id && $edit_type && $edit_price_new >= 0 && $edit_price_refill >= 0 && $edit_stock >= 0 && $edit_damaged >= 0 && $edit_missing >= 0) {
        $update_stmt = $conn->prepare("UPDATE container SET container_name=?, price_with_container=?, price_refill=?, stock_quantity=?, damaged_container=?, missing_container=? WHERE container_id=?");
        $update_stmt->execute([$edit_type, $edit_price_new, $edit_price_refill, $edit_stock, $edit_damaged, $edit_missing, $edit_id]);
        header('Location: inventory.php');
        exit;
    } else {
        $edit_error = 'Invalid input. Please check your values.';
    }
}

// Fetch inventory for this distributor's shop(s)
$stmt = $conn->prepare(
  "SELECT ci.container_id, ci.container_name AS item, ci.stock_quantity AS available, ci.price_with_container, ci.price_refill, ci.damaged_container, ci.missing_container
   FROM container ci
   WHERE ci.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
   ORDER BY ci.container_name"
);
$stmt->execute([$distributor_id]);
$inventory = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Inventory | Tuy PureFlow Distributor</title>
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
    .form-card {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      border-left: 4px solid #3b82f6;
      transition: all 0.3s ease;
    }
    .form-card:hover {
      box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
      transform: translateY(-2px);
    }
    .table-row {
      transition: all 0.2s ease;
    }
    .table-row:hover {
      background: linear-gradient(90deg, #f8fafc 0%, #ffffff 100%);
    }
    input[type="text"],
    input[type="number"],
    select {
      transition: all 0.2s ease;
    }
    input[type="text"]:focus,
    input[type="number"]:focus,
    select:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
      <div class="mb-8">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-boxes text-blue-600"></i>
          Inventory
        </h1>
        <p class="text-gray-600">Manage your product inventory</p>
      </div>

      <!-- Add Item Form -->
      <div class="form-card bg-white shadow-sm border border-gray-200 rounded-xl p-6 mb-6 fade-in">
        <h2 class="text-xl font-semibold mb-6 flex items-center gap-3 text-gray-800">
          <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
            <i class="fas fa-plus-circle text-blue-600"></i>
          </div>
          Add New Item
        </h2>
        <form method="POST" enctype="multipart/form-data" class="flex flex-wrap gap-4 items-end">
          <div>
            <label class="block text-sm font-medium text-gray-700">Container Type</label>
            <select name="item_type" required class="border rounded px-3 py-2">
              <option value="">Select Type</option>
              <option value="Slim Container">Slim Container</option>
              <option value="Round Container">Round Container</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Price (₱) With Container</label>
            <input type="number" name="price_new" step="0.01" min="0" required class="border rounded px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Price (₱) Refill Only</label>
            <input type="number" name="price_refill" step="0.01" min="0" required class="border rounded px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Initial Stock</label>
            <input type="number" name="item_stock" min="0" required class="border rounded px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Damaged</label>
            <input type="number" name="damaged_container" min="0" value="0" required class="border rounded px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Missing</label>
            <input type="number" name="missing_container" min="0" value="0" required class="border rounded px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Container Image</label>
            <input type="file" name="container_image" accept="image/*" capture="environment" class="border rounded px-3 py-2">
          </div>
          <button type="submit" name="add_item" class="bg-blue-600 text-white px-4 py-2 rounded">Add Item</button>
        </form>
      </div>
      <!-- Inventory Table -->
      <div class="bg-white shadow-sm border border-gray-200 rounded-xl p-6 mb-6 overflow-x-auto fade-in">
      <?php if ($edit_error): ?>
        <div class="mb-4 text-red-600 font-semibold"><?= htmlspecialchars($edit_error) ?></div>
      <?php endif; ?>
      <table class="min-w-full text-sm text-left text-gray-700">
        <thead class="bg-gradient-to-r from-blue-500 to-blue-600 text-white">
          <tr>
            <th class="px-4 py-2">Item</th>
            <th class="px-4 py-2">Available</th>
            <th class="px-4 py-2">Price (With Container)</th>
            <th class="px-4 py-2">Price (Refill Only)</th>
            <th class="px-4 py-2">Damaged</th>
            <th class="px-4 py-2">Missing</th>
            <th class="px-4 py-2">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($inventory as $idx => $inv): ?>
          <tr class="table-row border-t">
            <form method="POST" class="contents">
              <input type="hidden" name="edit_container_id" value="<?= htmlspecialchars($inv['container_id'] ?? $idx) ?>">
              <td class="px-4 py-2">
                  <input type="text" name="edit_item_type" value="<?= htmlspecialchars($inv['item']) ?>" class="border rounded px-2 py-1 w-24" required>
              </td>
              <td class="px-4 py-2">
                <input type="number" name="edit_item_stock" value="<?= $inv['available'] ?>" min="0" class="border rounded px-2 py-1 w-16" required>
              </td>
                <td class="px-4 py-2">
                  <input type="number" name="edit_price_new" value="<?= $inv['price_with_container'] ?>" min="0" step="0.01" class="border rounded px-2 py-1 w-20" required>
              </td>
              <td class="px-4 py-2">
                <input type="number" name="edit_price_refill" value="<?= $inv['price_refill'] ?>" min="0" step="0.01" class="border rounded px-2 py-1 w-20" required>
              </td>
              <td class="px-4 py-2">
                <input type="number" name="edit_damaged_container" value="<?= $inv['damaged_container'] ?>" min="0" class="border rounded px-2 py-1 w-16" required>
              </td>
              <td class="px-4 py-2">
                <input type="number" name="edit_missing_container" value="<?= $inv['missing_container'] ?>" min="0" class="border rounded px-2 py-1 w-16" required>
              </td>
              <td class="px-4 py-2 text-blue-600">
                <button type="submit" name="update_item" class="bg-blue-600 text-white px-3 py-1 rounded">Update</button>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Edit Detail Panel removed: replaced by inline edit forms -->
  </div>

  <!-- JavaScript -->
  <script>
    function showDetail(name, available, transit, reserved, damaged, threshold) {
      document.getElementById('detail-panel').classList.remove('hidden');
      document.getElementById('item-name').value = name;
      document.getElementById('available').value = available;
      document.getElementById('in-transit').value = transit;
      document.getElementById('reserved').value = reserved;
      document.getElementById('damaged').value = damaged;
      document.getElementById('threshold').value = threshold;
    }

    function hideDetail() {
      document.getElementById('detail-panel').classList.add('hidden');
    }
  </script>

</body>
</html>
</body>
</html>
