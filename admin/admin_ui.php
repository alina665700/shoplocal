<?php
// admin/admin_ui.php
// Shared admin topbar/footer/page CSS only.
// Sidebar CSS lives inside admin/sidebar.php to stop old sidebar styles from rendering.

if (!function_exists('admin_ui_h')) {
    function admin_ui_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_ui_output_assets')) {
    function admin_ui_output_assets() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<link rel="stylesheet" href="../assets/admin/css/admin_ui.css?v=20260517b">
<script src="../assets/admin/js/admin_ui.js?v=20260517b"></script>
        <?php
    }
}
?>
