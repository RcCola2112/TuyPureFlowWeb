<?php
session_start();
include '../db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    // Fetch admin by username or email
    $stmt = $conn->prepare("SELECT * FROM admin WHERE username = ? OR email = ?");
    $stmt->execute([$login, $login]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['admin_id'];
        $_SESSION['admin_name'] = $admin['username'];
        header('Location: dashboard.php');
        exit;
    } else {
        $error = "Invalid username/email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Login - Tuy PureFlow</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-white via-[#d9f6ff] to-[#b0ecfa]">
  <form method="POST" class="bg-[#5cccf6] p-8 rounded-2xl shadow-lg w-full max-w-sm">
    <!-- Logo Section -->
    <div class="flex justify-center mb-4">
      <div class="bg-gradient-to-br from-white to-[#d4f3ff] rounded-full p-3 shadow-md flex items-center justify-center">
        <img src="../images/logo.png" alt="Admin Logo" class="w-20 h-20 object-contain">
      </div>
    </div>

    <h1 class="text-2xl font-bold mb-6 text-white text-center">Admin Login</h1>

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

    <!-- Removed sign up link for admin login page -->
  </form>

</body>
</html>
