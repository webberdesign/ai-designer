<?php
/*
 * Admin Configuration Page
 *
 * This page allows administrators to view and update API keys and model
 * settings used throughout the Merch Admin suite. Configuration values
 * are stored in config.json and loaded via config.php. Updating the
 * values here will update the JSON file and take effect on subsequent
 * page loads. The page uses the same admin layout as other pages.
 */

// Load current configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$message = '';
// Handle form submission to update configuration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newConfig = $config;
    $newConfig['openai_api_key']    = trim($_POST['openai_api_key'] ?? '');
    $newConfig['gemini_api_key']    = trim($_POST['gemini_api_key'] ?? '');
    $newConfig['openai_model']      = trim($_POST['openai_model'] ?? '');
    $newConfig['gemini_model']      = trim($_POST['gemini_model'] ?? '');
    $newConfig['openai_chat_model'] = trim($_POST['openai_chat_model'] ?? '');
    // Add vector API key
    $newConfig['vector_api_key']    = trim($_POST['vector_api_key'] ?? '');
    // Persist the updated configuration
    $configPath = __DIR__ . '/config.json';
    if (@file_put_contents($configPath, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        // Reload the config variable for this page
        $config   = $newConfig;
        $message  = 'Configuration updated successfully.';
    } else {
        $message  = 'Failed to write configuration file. Check file permissions.';
    }
}

// Determine current script for nav highlighting
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$createPages    = [
    'admin_create.php',
    'admin_logo_creator.php',
    'admin_poster_creator.php',
    'admin_flyer_creator.php',
    'admin_social_creator.php',
    'admin_product_photo_creator.php'
];
$isCreate       = in_array($currentScript, $createPages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Configuration</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #111827;
            --secondary: #1f2937;
            --accent: #2563eb;
            --text-light: #f3f4f6;
            --text-muted: #9ca3af;
            --bg: #f6f7fb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            margin: 0;
            font-family: Poppins, system-ui, sans-serif;
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
            font-weight: 600;
            font-size: 1.25rem;
        }
        nav.top-nav a {
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.85rem;
            margin-left: 20px;
        }
        nav.top-nav a:hover { text-decoration: underline; }
        .admin-main {
            flex: 1;
            display: flex;
        }
        .admin-sidebar {
            background: var(--secondary);
            color: var(--text-light);
            padding: 24px 0;
            width: 220px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .admin-sidebar a {
            color: var(--text-light);
            padding: 12px 24px;
            text-decoration: none;
            font-size: 0.9rem;
            display: block;
        }
        .admin-sidebar a.active,
        .admin-sidebar a:hover {
            background: var(--primary);
        }
        .admin-content {
            flex: 1;
            padding: 32px;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 16px;
        }
        .config-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            max-width: 600px;
        }
        .config-card h2 {
            margin-bottom: 16px;
            font-size: 1.2rem;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 4px;
            color: var(--secondary);
        }
        .form-group input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .form-group input[type="text"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 1px var(--accent);
            outline: none;
        }
        .btn-primary {
            background: var(--accent);
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: #1d4ed8;
        }
        .message {
            margin-bottom: 16px;
            color: #16a34a;
            font-weight: 500;
        }
        .error-message {
            margin-bottom: 16px;
            color: #dc2626;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <aside class="admin-sidebar">
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <a href="admin_design_lab.php" class="<?php echo ($currentScript === 'admin_design_lab.php' || $currentScript === 'admin_design_lab_gemini.php') ? 'active' : ''; ?>">Design Lab</a>
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_create.php" class="<?php echo $isCreate ? 'active' : ''; ?>">Create</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Configuration</h1>
            <?php if ($message): ?>
                <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <div class="config-card">
                <h2>API Keys and Models</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="openai_api_key">OpenAI API Key</label>
                        <input type="text" id="openai_api_key" name="openai_api_key" value="<?php echo htmlspecialchars($config['openai_api_key'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="openai_model">OpenAI Image Model</label>
                        <input type="text" id="openai_model" name="openai_model" value="<?php echo htmlspecialchars($config['openai_model'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="openai_chat_model">OpenAI Chat Model</label>
                        <input type="text" id="openai_chat_model" name="openai_chat_model" value="<?php echo htmlspecialchars($config['openai_chat_model'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gemini_api_key">Gemini API Key</label>
                        <input type="text" id="gemini_api_key" name="gemini_api_key" value="<?php echo htmlspecialchars($config['gemini_api_key'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gemini_model">Gemini Image Model</label>
                        <input type="text" id="gemini_model" name="gemini_model" value="<?php echo htmlspecialchars($config['gemini_model'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="vector_api_key">Vector API Key</label>
                        <input type="text" id="vector_api_key" name="vector_api_key" value="<?php echo htmlspecialchars($config['vector_api_key'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn-primary">Save Settings</button>
                </form>
            </div>
        </section>
    </div>
</div>
</body>
</html>