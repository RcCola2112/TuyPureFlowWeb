<?php
session_start();
include '../db.php';

$error = '';
$success = '';

// Google Sign-Up handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_id_token'])) {
    $id_token = $_POST['google_id_token'];
    $client_id = '881844337775-dvods7hqf78749q67ilernb61obgecs4.apps.googleusercontent.com'; // Google OAuth Client ID

    // Verify token with Google
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
    $resp = json_decode(file_get_contents($url), true);

    if ($resp && isset($resp['email']) && $resp['aud'] === $client_id && $resp['email_verified'] === 'true') {
        $email = $resp['email'];
        $full_name = $resp['name'] ?? '';
        $google_sub = $resp['sub'];
        $username = explode('@', $email)[0];
        // Check if user exists
        $stmt = $conn->prepare("SELECT * FROM consumer WHERE google_sub = ? OR email = ?");
        $stmt->execute([$google_sub, $email]);
        $user = $stmt->fetch();
        if ($user) {
            $error = "Account already exists. Please login.";
        } else {
            // Create new user (no password for Google accounts)
            $stmt = $conn->prepare("INSERT INTO consumer (full_name, email, contact_number, username, google_sub, created_at) VALUES (?, ?, '', ?, ?, NOW())");
            if ($stmt->execute([$full_name, $email, $username, $google_sub])) {
                $id = $conn->lastInsertId();
                $_SESSION['consumer_id'] = $id;
                $_SESSION['consumer_name'] = $username;
                header('Location: landing_page.php');
                exit;
            } else {
                $error = "Google registration failed.";
            }
        }
    } else {
        $error = "Google authentication failed.";
    }
}

// Regular signup handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['google_id_token'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    // Basic validation
    if (!$full_name || !$email || !$contact_number || !$username || !$password || !$confirm) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        // Check if username or email exists
        $stmt = $conn->prepare("SELECT * FROM consumer WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = "Username or email already exists.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO consumer (full_name, email, contact_number, username, password, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($stmt->execute([$full_name, $email, $contact_number, $username, $hashed_password])) {
                $id = $conn->lastInsertId();
                $_SESSION['consumer_id'] = $id;
                $_SESSION['consumer_name'] = $username;
                header('Location: landing_page.php');
                exit;
            } else {
                $error = "Registration failed.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sign Up - Tuy PureFlow</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <form method="POST" class="bg-white p-8 rounded shadow-md w-full max-w-sm" id="signupForm">
    <div class="flex justify-center mb-6">
      <a href="../index.html">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-12 w-auto hover:scale-105 transition-transform duration-150" style="margin: 0 auto;">
      </a>
    </div>
    <h1 class="text-2xl font-bold mb-6 text-blue-600 text-center">Consumer Sign Up</h1>
    <?php if ($error): ?>
      <div class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
      <div class="mb-4 text-green-600 text-center"><?= $success ?></div>
    <?php endif; ?>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Full Name</label>
      <input type="text" name="full_name" id="full_name" placeholder="Full Name *" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
    </div>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Email</label>
      <input type="email" name="email" id="email" placeholder="Email Address *" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Contact Number</label>
      <input type="text" name="contact_number" id="contact_number" placeholder="Contact Number *" required maxlength="15" class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
    </div>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Username</label>
      <input type="text" name="username" id="username" placeholder="Username *" required class="w-full border px-3 py-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="mb-4">
      <label class="block mb-1 text-sm font-medium">Password</label>
      <div class="relative">
        <input type="password" name="password" id="password" placeholder="Password *" required class="w-full border px-3 py-2 rounded pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <span class="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer" onclick="togglePassword('password', this)">
          <svg id="icon_password" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
        </span>
      </div>
    </div>
    <div class="mb-6">
      <label class="block mb-1 text-sm font-medium">Confirm Password</label>
      <div class="relative">
        <input type="password" name="confirm" id="confirm" placeholder="Confirm Password *" required class="w-full border px-3 py-2 rounded pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <span class="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer" onclick="togglePassword('confirm', this)">
          <svg id="icon_confirm" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
        </span>
      </div>
    </div>
    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded font-semibold">Sign Up</button>
    <div class="my-4 text-center text-gray-500">or</div>
    <div id="g_id_onload"
         data-client_id="881844337775-dvods7hqf78749q67ilernb61obgecs4.apps.googleusercontent.com"
         data-context="signup"
         data-ux_mode="popup"
         data-callback="handleGoogleSignUp"
         data-auto_prompt="false">
    </div>
    <div class="flex justify-center">
      <div class="g_id_signin"
           data-type="standard"
           data-shape="rectangular"
           data-theme="outline"
           data-text="signup_with"
           data-size="large"
           data-logo_alignment="left">
      </div>
    </div>
    <p class="mt-4 text-center text-sm">Already have an account? <a href="login.php" class="text-blue-600 hover:underline">Login</a></p>
  </form>
  <script>
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
    function handleGoogleSignUp(response) {
      // Decode Google token and fill fields if possible
      const token = response.credential;
      fetch('https://oauth2.googleapis.com/tokeninfo?id_token=' + encodeURIComponent(token))
        .then(res => res.json())
        .then(data => {
          if (data && data.email) {
            document.getElementById('full_name').value = data.name || '';
            document.getElementById('email').value = data.email || '';
            document.getElementById('username').value = (data.email || '').split('@')[0];
            // Optionally, you can submit the form automatically:
            // let form = document.createElement('form');
            // form.method = 'POST';
            // form.style.display = 'none';
            // let input = document.createElement('input');
            // input.name = 'google_id_token';
            // input.value = token;
            // form.appendChild(input);
            // document.body.appendChild(form);
            // form.submit();
          }
        });

      // If you want to submit directly, uncomment the block above and comment out below:
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
  </script>
</body>
</html>