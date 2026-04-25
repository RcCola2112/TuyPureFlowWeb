<?php
session_start();
// Destroy all distributor session data
session_unset();
session_destroy();
// Redirect to distributor login page
echo '<script>window.location.replace("../index.html");</script>';
exit;
exit;
?>
