<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';

// Redirect to dashboard only if already logged in as admin
if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic validation
    if (!$username || !$email || !$password) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admin (username, email, password) VALUES (?, ?, ?)");
            if ($stmt->execute([$username, $email, $hashed])) {
                $success = "Admin account created! You can now <a href='login.php' class='text-blue-600 underline'>login</a>.";
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Signup - Tuy PureFlow</title>
  <link rel="stylesheet" href="../Consumer/assets/style.css">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
  <div class="flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded shadow-md w-full max-w-md">
      <div class="flex flex-col items-center mb-4">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-16 w-auto mb-2">
      </div>
      <h1 class="text-2xl font-bold mb-6 text-blue-600">Admin Signup</h1>
      <?php if ($error): ?>
        <div class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></div>
      <?php elseif ($success): ?>
        <div class="mb-4 text-green-600 text-center"><?= $success ?></div>
      <?php endif; ?>
      <form method="POST">
        <div class="mb-4">
          <label class="block mb-1 font-medium">Username</label>
          <input type="text" name="username" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div class="mb-4">
          <label class="block mb-1 font-medium">Email</label>
          <input type="email" name="email" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div class="mb-6">
          <label class="block mb-1 font-medium">Password</label>
          <input type="password" name="password" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded font-semibold hover:bg-blue-700 transition">Sign Up</button>
      </form>
      <p class="mt-4 text-sm text-gray-600">Already have an account? <a href="login.php" class="text-blue-600 hover:underline">Login here</a>.</p>
    </div>
  </div>
</body>
</html>