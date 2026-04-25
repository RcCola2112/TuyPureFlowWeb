<?php
session_start();
include '../db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_id_token'])) {
    $id_token = $_POST['google_id_token'];
    $client_id = '881844337775-dvods7hqf78749q67ilernb61obgecs4.apps.googleusercontent.com'; // Google OAuth Client ID

    // Verify token with Google
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
    $resp = json_decode(file_get_contents($url), true);

    if ($resp && isset($resp['email']) && $resp['aud'] === $client_id && $resp['email_verified'] === 'true') {
        $email = $resp['email'];
        $google_sub = $resp['sub'];
        // Check if user exists
        $stmt = $conn->prepare("SELECT * FROM consumer WHERE google_sub = ? OR email = ?");
        $stmt->execute([$google_sub, $email]);
        $consumer = $stmt->fetch();
        if ($consumer) {
            $_SESSION['consumer_id'] = $consumer['consumer_id'];
            $_SESSION['consumer_name'] = $consumer['username'];
            header('Location: landing_page.php');
            exit;
        } else {
            $error = "No account found for this Google account. Please sign up first.";
        }
    } else {
        $error = "Google authentication failed.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['google_id_token'])) {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    // Fetch consumer by username or email
    $stmt = $conn->prepare("SELECT * FROM consumer WHERE username = ? OR email = ?");
    $stmt->execute([$login, $login]);
    $consumer = $stmt->fetch();

    if ($consumer && password_verify($password, $consumer['password'])) {
        $_SESSION['consumer_id'] = $consumer['consumer_id'];
        $_SESSION['consumer_name'] = $consumer['username'];
        header('Location: landing_page.php');
        exit;
      } elseif ($consumer && $password === $consumer['password']) {
        // Allow login if password matches directly (plain text)
        $_SESSION['consumer_id'] = $consumer['consumer_id'];
        $_SESSION['consumer_name'] = $consumer['username'];
        header('Location: landing_page.php');
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
  <title>Consumer Login - Tuy PureFlow</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <form method="POST" class="bg-white p-8 rounded shadow-md w-full max-w-sm">
    <div class="flex justify-center mb-6">
      <a href="../index.html">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-12 w-auto hover:scale-105 transition-transform duration-150" style="margin: 0 auto;">
      </a>
    </div>
    <h1 class="text-2xl font-bold mb-6 text-blue-600 text-center">Consumer Login</h1>
    <?php if ($error): ?>
      <div class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Username or Email</label>
      <input type="text" name="login" placeholder="Username or Email *" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
    </div>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Password</label>
      <div class="relative">
        <input type="password" name="password" id="login_password" required class="w-full border px-3 py-2 rounded pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <span class="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer" onclick="togglePassword('login_password', this)">
          <svg id="icon_login_password" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
        </span>
      </div>
    </div>
    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded font-semibold">Login</button>
    <div class="my-4 text-center text-gray-500">or</div>
    <div id="g_id_onload"
         data-client_id="881844337775-dvods7hqf78749q67ilernb61obgecs4.apps.googleusercontent.com"
         data-context="signin"
         data-ux_mode="popup"
         data-callback="handleGoogleLogin"
         data-auto_prompt="false">
    </div>
    <div class="flex justify-center">
      <div class="g_id_signin"
           data-type="standard"
           data-shape="rectangular"
           data-theme="outline"
           data-text="signin_with"
           data-size="large"
           data-logo_alignment="left">
      </div>
    </div>
    <p class="mt-4 text-center text-sm">Don't have an account? <a href="signup.php" class="text-blue-600 hover:underline">Sign Up</a></p>
  </form>
  <script>
    function handleGoogleLogin(response) {
      var form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';
      var input = document.createElement('input');
      input.name = 'google_id_token';
      input.value = response.credential;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    }

    function togglePassword(fieldId, iconSpan) {
      var input = document.getElementById(fieldId);
      var icon = iconSpan.querySelector('svg');
      if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.978 9.978 0 012.042-3.362m1.528-1.528A9.956 9.956 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.978 9.978 0 01-4.442 5.568M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18" />';
        icon.classList.add('text-blue-600');
      } else {
        input.type = 'password';
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268-2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />';
        icon.classList.remove('text-blue-600');
      }
    }
  </script>
</body>
</html>