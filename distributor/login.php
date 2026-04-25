<?php
session_start();
include '../db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM distributor WHERE email = ?");
    $stmt->execute([$login]);
    $distributor = $stmt->fetch();

    if ($distributor && password_verify($password, $distributor['password'])) {
        if ($distributor['status'] === 'Pending') {
            $error = "Your account is waiting to be approved by the admin.";
        } else {
            $_SESSION['distributor_id'] = $distributor['distributor_id'];
            $_SESSION['distributor_name'] = $distributor['username'];

            $shopStmt = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ?");
            $shopStmt->execute([$distributor['distributor_id']]);
            $shop = $shopStmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION['shop_name'] = $shop ? $shop['name'] : '';

            header("Location: dashboard.php");
            exit;
        }
    } else {
        $error = "Invalid username/email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Distributor Login - Tuy PureFlow</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-white via-[#d9f6ff] to-[#b0ecfa]">

  <form method="POST" class="bg-[#5cccf6] p-8 rounded-2xl shadow-lg w-full max-w-sm">
    
    <!-- Logo Section -->
    <div class="flex justify-center mb-4">
      <div class="bg-gradient-to-br from-white to-[#d4f3ff] rounded-full p-3 shadow-md flex items-center justify-center">
        <img src="../images/logo.png" alt="Distributor Logo" class="w-20 h-20 object-contain">
      </div>
    </div>

    <h1 class="text-2xl font-bold mb-6 text-white text-center">Distributor Login</h1>

    <?php if ($error): ?>
      <div class="mb-4 text-red-700 text-center bg-red-100 p-2 rounded"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium text-white">Username or Email</label>
      <input 
        type="text" 
        name="login" 
        placeholder="Username or Email *" 
        required 
        class="w-full border border-gray-300 px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-[#004c8c]" 
        value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
    </div>

    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium text-white">Password</label>
      <input 
        type="password" 
        name="password" 
        required 
        class="w-full border border-gray-300 px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-[#004c8c]">
    </div>

    <button 
      type="submit" 
      class="w-full bg-[#004c8c] hover:bg-[#003b6b] text-white py-2 rounded font-semibold transition">
      Login
    </button>

    <p class="mt-4 text-center text-sm text-white">
      Don't have an account? 
      <a href="signup.php" class="text-[#004c8c] hover:underline font-semibold">Sign Up</a>
    </p>
  </form>

</body>
</html>
