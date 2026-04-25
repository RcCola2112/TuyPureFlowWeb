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
$currentPage = 'certificates';

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

// Get status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'All';

// Fetch certificates (with types)
$whereClause = "WHERE dc.distributor_id = ?";
$params = [$distributor_id];

if ($status_filter !== 'All') {
    $whereClause .= " AND dc.status = ?";
    $params[] = $status_filter;
}

$cert_stmt = $conn->prepare("
  SELECT dc.certificate_id, dc.certificate_type_id, ct.certificate_name, dc.uploaded_at, dc.status, dc.remarks
  FROM distributor_certificates dc
  LEFT JOIN certificate_type ct ON dc.certificate_type_id = ct.certificate_type_id
  $whereClause
  ORDER BY dc.uploaded_at DESC
");
$cert_stmt->execute($params);
$certificates = $cert_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get certificate counts by status (always get all counts regardless of filter)
$count_stmt = $conn->prepare("
  SELECT 
    dc.status,
    COUNT(*) as count
  FROM distributor_certificates dc
  WHERE dc.distributor_id = ?
  GROUP BY dc.status
");
$count_stmt->execute([$distributor_id]);
$status_counts = $count_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$total_stmt = $conn->prepare("SELECT COUNT(*) as total FROM distributor_certificates WHERE distributor_id = ?");
$total_stmt->execute([$distributor_id]);
$total_count = $total_stmt->fetchColumn();

$counts = [
    'All' => intval($total_count),
    'Pending' => 0,
    'Approved' => 0,
    'Rejected' => 0
];

foreach ($status_counts as $row) {
    $status = $row['status'];
    if (isset($counts[$status])) {
        $counts[$status] = intval($row['count']);
    }
}

// Function to format date
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y h:i A', strtotime($date));
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-100 text-green-700 border-green-200';
        case 'Rejected':
            return 'bg-red-100 text-red-700 border-red-200';
        case 'Pending':
        default:
            return 'bg-yellow-100 text-yellow-700 border-yellow-200';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Certificates | Tuy PureFlow Distributor</title>
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
    .cert-card {
      transition: all 0.2s ease;
    }
    .cert-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
          <i class="fas fa-certificate text-blue-600"></i>
          My Certificates
        </h1>
        <p class="text-gray-600">View and manage your uploaded certificates</p>
      </div>

      <!-- Status Filter Cards -->
      <div class="grid grid-cols-4 gap-4 mb-6 fade-in">
        <a href="?status=All" class="bg-white rounded-lg p-4 shadow-sm border-2 <?= $status_filter === 'All' ? 'border-blue-500' : 'border-gray-200' ?> hover:border-blue-300 transition-colors">
          <div class="text-3xl font-bold text-gray-700 mb-1"><?= $counts['All'] ?></div>
          <div class="text-sm font-semibold text-gray-600">All Certificates</div>
        </a>
        <a href="?status=Pending" class="bg-white rounded-lg p-4 shadow-sm border-2 <?= $status_filter === 'Pending' ? 'border-yellow-500' : 'border-gray-200' ?> hover:border-yellow-300 transition-colors">
          <div class="text-3xl font-bold text-yellow-600 mb-1"><?= $counts['Pending'] ?></div>
          <div class="text-sm font-semibold text-gray-600">Pending</div>
        </a>
        <a href="?status=Approved" class="bg-white rounded-lg p-4 shadow-sm border-2 <?= $status_filter === 'Approved' ? 'border-green-500' : 'border-gray-200' ?> hover:border-green-300 transition-colors">
          <div class="text-3xl font-bold text-green-600 mb-1"><?= $counts['Approved'] ?></div>
          <div class="text-sm font-semibold text-gray-600">Approved</div>
        </a>
        <a href="?status=Rejected" class="bg-white rounded-lg p-4 shadow-sm border-2 <?= $status_filter === 'Rejected' ? 'border-red-500' : 'border-gray-200' ?> hover:border-red-300 transition-colors">
          <div class="text-3xl font-bold text-red-600 mb-1"><?= $counts['Rejected'] ?></div>
          <div class="text-sm font-semibold text-gray-600">Rejected</div>
        </a>
      </div>

      <!-- Certificates Grid -->
      <?php if (count($certificates) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 fade-in">
          <?php foreach ($certificates as $cert): ?>
            <div class="cert-card bg-white rounded-xl p-6 shadow-sm border border-gray-200">
              <!-- Certificate Header -->
              <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                  <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-certificate text-blue-600 text-xl"></i>
                  </div>
                  <div>
                    <h3 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($cert['certificate_name'] ?? 'Unknown Certificate') ?></h3>
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold border <?= getStatusBadgeClass($cert['status'] ?? 'Pending') ?> mt-1">
                      <?= htmlspecialchars($cert['status'] ?? 'Pending') ?>
                    </span>
                  </div>
                </div>
              </div>

              <!-- Certificate Details -->
              <div class="space-y-3 mb-4">
                <div class="flex items-center gap-2 text-gray-600">
                  <i class="far fa-calendar text-gray-400"></i>
                  <span class="text-sm"><?= formatDate($cert['uploaded_at']) ?></span>
                </div>
                <?php if (!empty($cert['remarks'])): ?>
                  <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                    <div class="text-xs font-semibold text-gray-500 mb-1">Remarks:</div>
                    <div class="text-sm text-gray-700"><?= htmlspecialchars($cert['remarks']) ?></div>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Certificate Actions -->
              <div class="flex gap-2 pt-4 border-t border-gray-200">
                <a href="download_certificate.php?certificate_id=<?= $cert['certificate_id'] ?>" 
                   target="_blank" 
                   class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold text-center text-sm">
                  <i class="fas fa-eye mr-2"></i>View
                </a>
                <a href="download_certificate.php?certificate_id=<?= $cert['certificate_id'] ?>" 
                   download
                   class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-semibold text-sm">
                  <i class="fas fa-download"></i>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <!-- Empty State -->
        <div class="bg-white rounded-xl p-12 text-center shadow-sm border border-gray-200 fade-in">
          <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
            <i class="fas fa-certificate text-4xl text-gray-400"></i>
          </div>
          <p class="text-lg font-semibold text-gray-700 mb-2">No certificates found</p>
          <p class="text-sm text-gray-500 mb-6">
            <?php if ($status_filter !== 'All'): ?>
              No certificates with status "<?= htmlspecialchars($status_filter) ?>".
            <?php else: ?>
              You haven't uploaded any certificates yet.
            <?php endif; ?>
          </p>
          <a href="settings.php" class="inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
            <i class="fas fa-upload mr-2"></i>Upload Certificate
          </a>
        </div>
      <?php endif; ?>

      <!-- Info Box -->
      <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4 fade-in">
        <div class="flex items-start gap-3">
          <i class="fas fa-info-circle text-blue-600 mt-1"></i>
          <div class="flex-1">
            <h4 class="font-semibold text-blue-900 mb-1">Certificate Information</h4>
            <p class="text-sm text-blue-800">
              Your certificates are reviewed by administrators. Once approved, they will be visible to customers. 
              You can upload new certificates or view existing ones from the <a href="settings.php" class="underline font-semibold">Settings</a> page.
            </p>
          </div>
        </div>
      </div>

    </main>
  </div>
</body>
</html>

