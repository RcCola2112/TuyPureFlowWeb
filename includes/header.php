<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tuy PureFlow Landing Page</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="bg-white text-gray-800">

<header class="shadow-md">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex justify-between items-center py-4">
      <div class="flex items-center space-x-2">
        <img src="/images/your-logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
        <span class="font-bold text-xl text-blue-600">Tuy PureFlow</span>
      </div>
      <nav class="hidden md:flex space-x-8 items-center text-gray-700">
        <a href="index.php" class="hover:text-blue-600 font-medium">Home</a>
        <div class="relative group">
          <button class="flex items-center gap-1 hover:text-blue-600 font-medium focus:outline-none">
            Services <span class="transform transition-transform group-hover:rotate-180">▼</span>
          </button>
          <div class="absolute left-0 mt-2 w-44 bg-white shadow-lg rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
            <a href="Consumer/shop_page.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Order Water</a>
            <a href="Consumer/orders.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Track Delivery</a>
            <a href="distributor/signup.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Distributor Portal</a>
          </div>
        </div>
        <a href="about_us.php" class="hover:text-blue-600 font-medium">About Us</a>
        <a href="contact_us.php" class="hover:text-blue-600 font-medium">Contact Us</a>
        <a href="Consumer/login.php" class="ml-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-medium">Sign In</a>
      </nav>
      <div class="md:hidden">
        <button class="text-gray-700 focus:outline-none">☰</button>
      </div>
    </div>
  </div>
</header>
