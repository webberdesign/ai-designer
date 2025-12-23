<?php
/**
 * Shared admin header and navigation for WebberSites AI Studio.
 * Usage:
 *   $pageTitle     = 'Page title';
 *   $activeSection = 'create' | 'idea' | 'library' | 'designs' | 'products' | 'orders' | 'config';
 *   $extraCss      = ['admin-creators.css']; // optional additional stylesheets
 *   require __DIR__ . '/admin_header.php';
 */

$pageTitle     = $pageTitle ?? 'Admin';
$activeSection = $activeSection ?? null;
$extraCss      = $extraCss ?? [];
$bodyClass     = $bodyClass ?? '';
$currentScript = basename($_SERVER['SCRIPT_NAME']);

if (!function_exists('admin_nav_active')) {
    function admin_nav_active(string $item, string $currentScript, ?string $activeSection): string
    {
        if ($activeSection === $item) {
            return 'active';
        }
        return match ($item) {
            'create'   => $currentScript === 'admin_create.php' ? 'active' : '',
            'idea'     => $currentScript === 'admin_idea_generator.php' ? 'active' : '',
            'library'  => $currentScript === 'admin_media_library.php' ? 'active' : '',
            'designs'  => ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : '',
            'products' => ($currentScript === 'admin.php' && (isset($_GET['section']) && $_GET['section'] === 'products')) ? 'active' : '',
            'orders'   => ($currentScript === 'admin.php' && (isset($_GET['section']) && $_GET['section'] === 'orders')) ? 'active' : '',
            'config'   => $currentScript === 'admin_config.php' ? 'active' : '',
            default    => '',
        };
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-styles.css">
    <?php foreach ($extraCss as $cssFile): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile); ?>">
    <?php endforeach; ?>
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
<div class="admin-container">
    <header class="admin-header">
        <span class="menu-toggle" id="mobileMenuToggle">&#9776;</span>
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <aside class="admin-sidebar">
            <a href="admin_create.php" class="<?php echo admin_nav_active('create', $currentScript, $activeSection); ?>">Create</a>
            <a href="admin_idea_generator.php" class="<?php echo admin_nav_active('idea', $currentScript, $activeSection); ?>">Idea Generator</a>
            <a href="admin_media_library.php" class="<?php echo admin_nav_active('library', $currentScript, $activeSection); ?>">Library</a>
            <a href="admin.php?section=designs" class="<?php echo admin_nav_active('designs', $currentScript, $activeSection); ?>">Designs</a>
            <a href="admin.php?section=products" class="<?php echo admin_nav_active('products', $currentScript, $activeSection); ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo admin_nav_active('orders', $currentScript, $activeSection); ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo admin_nav_active('config', $currentScript, $activeSection); ?>">Config</a>
        </aside>
        <section class="admin-content">
