<?php
// Admin Edit Design Page
// This page embeds the existing edit_design.php interface within the admin dashboard.
// It provides the same editing functionality for a specific design while maintaining the admin header and sidebar.

// Ensure a design ID is provided
$designId = $_GET['id'] ?? '';
if ($designId === '') {
    die('Missing design ID.');
}

// Load design record to display its name in the title bar and validate existence
$designsFile = __DIR__ . '/tshirt_designs.json';
$designsList = json_decode(@file_get_contents($designsFile), true) ?: [];
$designRecord = null;
foreach ($designsList as $d) {
    if ($d['id'] === $designId) {
        $designRecord = $d;
        break;
    }
}
if (!$designRecord) {
    die('Design not found.');
}

// Determine active page for sidebar highlighting. We treat this page as part of the Designs section.
$activePage = 'edit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Edit Design</title>
    <style>
        /* Admin base styles (copied from admin.php) */
        :root {
            --primary: #111827;
            --secondary: #1f2937;
            --accent: #2563eb;
            --accent-light: #3b82f6;
            --text-light: #f3f4f6;
            --text-muted: #9ca3af;
            --bg: #f6f7fb;
            --bg-dark: #0f172a;
            --warning: #ef4444;
            --success: #10b981;
        }
        body {
            margin: 0;
            font-family: system-ui, sans-serif;
            background: var(--bg);
            color: #333;
        }
        .admin-container {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        header.admin-header {
            background: var(--primary);
            color: var(--text-light);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .brand {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        header .top-nav a {
            color: var(--text-light);
            text-decoration: none;
            margin-left: 20px;
            font-size: 0.9rem;
        }
        .admin-main {
            flex: 1;
            display: flex;
            min-height: 0;
        }
        .admin-sidebar {
            width: 220px;
            background: var(--secondary);
            color: var(--text-light);
            padding-top: 24px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .admin-sidebar a {
            display: block;
            padding: 12px 24px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.95rem;
        }
        .admin-sidebar a.active,
        .admin-sidebar a:hover {
            background: var(--accent);
        }
        .admin-content {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
        }
        h1 {
            font-size: 1.6rem;
            margin-top: 0;
            margin-bottom: 20px;
        }
        /* Style for the embedded editor iframe */
        .editor-iframe {
            width: 100%;
            height: 85vh;
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <!-- Link to the public storefront -->
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <aside class="admin-sidebar">
            <!-- Highlight the Designs menu for edit page -->
            <a href="admin.php?section=designs" class="<?php echo 'active'; ?>">Designs</a>
            <!-- Unified Design Lab navigation -->
            <a href="admin_design_lab.php" class="">Design Lab</a>
            <a href="admin_idea_generator.php" class="">Idea Generator</a>
            <a href="admin_create.php" class="">Create</a>
            <a href="admin_media_library.php" class="">Library</a>
            <a href="admin.php?section=products" class="">Products</a>
            <a href="admin.php?section=orders" class="">Orders</a>
            <a href="admin_config.php" class="">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Edit Design: <?php echo htmlspecialchars($designRecord['display_text']); ?></h1>
            <!-- Embed the existing editing interface in an iframe -->
            <iframe class="editor-iframe" src="edit_design.php?id=<?php echo urlencode($designId); ?>"></iframe>
        </section>
    </div>
</div>
</body>
</html>