<?php
// Admin Design Lab page. This page embeds the T-shirt design lab within the admin dashboard.
// It duplicates the functionality of tshirt_designer.php but wraps it in the admin layout.

// Load configuration for API keys and models. This pulls from config.json via config.php.
require_once __DIR__ . '/config.php';
// Copy the same PHP logic from tshirt_designer.php for configuration, upload handling and design generation.

// Define the OpenAI model constant based on configuration for display purposes.
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// Assign API keys from configuration. These will override any environment variables.
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
// Determine models from configuration or fall back to sensible defaults.
$GEMINI_MODEL   = $config['gemini_model'] ?? 'gemini-2.5-flash-image';
// Build Gemini API endpoint with the selected model.
$GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . $GEMINI_MODEL . ":generateContent";
// Define output directory and database file paths.
$OUTPUT_DIR     = __DIR__ . '/generated_tshirts';
$DB_FILE        = __DIR__ . '/tshirt_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Load shared helpers
require_once __DIR__ . '/functions.php';
// Ensure storage is prepared (uses $OUTPUT_DIR and $DB_FILE globals)
ensure_storage();

// Handle design uploads (non-AJAX) if file uploaded from this page (should not happen normally)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST['action'])
    && isset($_FILES['design_upload'])
    && $_FILES['design_upload']['error'] === UPLOAD_ERR_OK
    && $_FILES['design_upload']['size'] > 0) {
    $displayText = trim($_POST['display_text'] ?? '');
    if ($displayText === '') {
        $displayText = 'Custom Design';
    }
    $tmpPath  = $_FILES['design_upload']['tmp_name'];
    $origName = $_FILES['design_upload']['name'];
    $mime     = mime_content_type($tmpPath) ?: 'application/octet-stream';
    $allowed  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'];
    $ext      = isset($allowed[$mime]) ? $allowed[$mime] : 'png';
    try {
        $filename = 'ts_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'ts_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $destPath = $OUTPUT_DIR . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        @copy($tmpPath, $destPath);
    }
    if ($ext !== 'png' && class_exists('Imagick') && is_file($destPath)) {
        try {
            $im = new Imagick($destPath);
            $im->setImageFormat('png');
            $pngPath = preg_replace('~\.[A-Za-z0-9]+$~', '.png', $destPath);
            $im->writeImage($pngPath);
            $im->clear();
            $im->destroy();
            @unlink($destPath);
            $destPath = $pngPath;
            $filename = basename($destPath);
        } catch (Throwable $t) {
        }
    }
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('ts_', true),
        'display_text' => $displayText,
        'graphic'      => pathinfo($origName, PATHINFO_FILENAME),
        'bg_color'     => '',
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Helper to call OpenAI for image generation (copied from tshirt_designer.php)
function generate_tshirt_image_admin($apiKey, $prompt, &$error = '', $background = null)
{
    $url = 'https://api.openai.com/v1/images/generations';
    $payload = [
        'model'   => OPENAI_MODEL,
        'prompt'  => $prompt,
        'size'    => '1024x1536',
        'n'       => 1,
        'quality' => 'high',
        'output_format'  => 'png',
    ];
    if ($background) {
        $payload['background'] = $background;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: ' . 'Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return null;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($status >= 400) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
        $error = 'API error (' . $status . '): ' . $msg;
        return null;
    }
    if (empty($data['data'][0]['b64_json'])) {
        $error = 'No image data returned.';
        return null;
    }
    return base64_decode($data['data'][0]['b64_json']);
}

// Handle AJAX generation requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $displayText = trim($_POST['display_text'] ?? '');
    $graphicText = trim($_POST['graphic_prompt'] ?? '');
    $bgColor     = trim($_POST['bg_color'] ?? '#000000');
    if ($displayText === '' || $graphicText === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the T-shirt text and the graphic description.']);
        exit;
    }
    if (!$OPENAI_API_KEY) {
        echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
        exit;
    }
    $prompt = sprintf(
        'T-shirt graphic design, portrait 2:3 aspect ratio. Flat, print-ready art with a solid %s background. ' .
        'Bold, readable typography that says: "%s". Integrated with an illustration of: %s. ' .
        'Encapsulated composition, no mockup, no human model, no extra text, no wrinkles, no background scene. ' .
        'High contrast, vector style, suitable for direct printing on a shirt.',
        $bgColor,
        $displayText,
        $graphicText
    );
    $error = '';
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    // Determine which image model to use
    $chosenModel = $_POST['image_model'] ?? 'openai';
    // Result variables
    $imgData = null;
    $fileMime = 'image/png';
    if ($chosenModel === 'gemini') {
        // Generate via Gemini (no inline image for design lab)
        $result = send_gemini_image_request($prompt, null, $GEMINI_API_KEY, $GEMINI_ENDPOINT);
        if (!$result[0]) {
            echo json_encode(['success' => false, 'error' => $result[1] ?: 'Error generating image with Gemini.']);
            exit;
        }
        $fileMime = $result[1];
        $imgData  = base64_decode($result[2]);
    } else {
        // Generate via OpenAI
        // When using OpenAI, pass the configured model name as the final argument
        $openResult = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, '1024x1536', OPENAI_MODEL);
        if (!$openResult[0]) {
            echo json_encode(['success' => false, 'error' => $openResult[1] ?: 'Error generating image with OpenAI.']);
            exit;
        }
        $fileMime = $openResult[1];
        $imgData  = base64_decode($openResult[2]);
    }
    // Save image to disk
    try {
        $filename = 'ts_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
    } catch (Exception $e) {
        $filename = 'ts_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.png';
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file. Check folder permissions on generated_tshirts/.']);
        exit;
    }
    // Save record to DB
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('ts_', true),
        'display_text' => $displayText,
        'graphic'      => $graphicText,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => $chosenModel,
        'tool'         => 'tshirt'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'           => $record['id'],
            'display_text' => $record['display_text'],
            'graphic'      => $record['graphic'],
            'bg_color'     => $record['bg_color'],
            'file'         => $record['file'],
            'created_at'   => $record['created_at'],
            'image_url'    => 'generated_tshirts/' . rawurlencode($record['file']),
        ],
    ]);
    exit;
}

// Load existing designs and set default values for pre-fill (from query string)
$designs = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs = array_reverse($designs);
$preview = $designs[0] ?? null;

$default_display_text = isset($_POST['display_text']) ? trim($_POST['display_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_graphic      = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg           = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#000000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Design Lab</title>
    <!--
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
            transition: left 0.3s ease;
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

        /* Mobile navigation: hide sidebar off‑screen and show a menu toggle on small
           screens. When the body has .mobile-open, slide the sidebar into view. */
        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            color: var(--text-light);
            cursor: pointer;
            margin-right: 10px;
        }
        @media (max-width: 768px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: -240px;
                height: 100vh;
                z-index: 1000;
                width: 200px;
            }
            body.mobile-open .admin-sidebar {
                left: 0;
            }
            .admin-main {
                flex-direction: column;
            }
            .menu-toggle {
                display: inline-block;
            }
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

        /* Copy the design lab styles (from tshirt_designer.php) */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body.design-lab {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #050816;
            color: #e5e7eb;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 24px;
        }
        .page-shell {
            width: 100%;
            max-width: 1200px;
            background: radial-gradient(circle at top left, #111827 0, #020617 55%, #020617 100%);
            border-radius: 24px;
            padding: 24px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            gap: 24px;
        }
        @media (max-width: 900px) {
            .page-shell { grid-template-columns: minmax(0, 1fr); }
        }
        .page-header {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .page-title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .page-subtitle {
            font-size: 0.9rem;
            color: #9ca3af;
        }
        .pill {
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9ca3af;
        }
        .card {
            background: rgba(15, 23, 42, 0.9);
            border-radius: 18px;
            padding: 18px 18px 20px;
            border: 1px solid rgba(55, 65, 81, 0.8);
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .card-sub {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-bottom: 16px;
        }
        .form-grid {
            display: grid;
            gap: 12px;
        }
        .field-label {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        .field-label span { color: #f97316; margin-left: 3px; }
        .input-text, .input-color, .input-select {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(75, 85, 99, 0.9);
            background: rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            font-size: 0.9rem;
            outline: none;
        }
        .input-text:focus, .input-color:focus, .input-select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.5);
        }
        .field-inline {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .field-inline .input-color {
            max-width: 90px;
            padding: 0;
            height: 40px;
        }
        .bg-preview-chip {
            flex: 1;
            border-radius: 999px;
            border: 1px dashed rgba(148, 163, 184, 0.8);
            padding: 6px 10px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #9ca3af;
        }
        .bg-preview-swatch {
            width: 20px;
            height: 20px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.8);
            background: #000;
        }
        .btn-row {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn-primary {
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, #06b6d4, #6366f1);
            color: #f9fafb;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .btn-primary span.icon {
            font-size: 1.1rem;
        }
        .hint { font-size: 0.75rem; color: #6b7280; }
        .error-banner {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #fecaca;
            background: rgba(127, 29, 29, 0.6);
            border-radius: 10px;
            padding: 8px 10px;
            border: 1px solid rgba(248, 113, 113, 0.7);
        }
        .preview-shell {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .preview-card {
            /* Remove the heavy card container and border to allow the artwork to breathe
               on mobile and desktop. Using transparent background and no border lets
               the design float in negative space while still grouping meta information. */
            background: none;
            border: none;
            border-radius: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .preview-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .preview-meta strong {
            color: #e5e7eb;
        }
        .preview-frame {
            position: relative;
            border: none;
            border-radius: 0;
            overflow: visible;
            background: transparent;
            aspect-ratio: 2 / 3;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .preview-frame img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
            border: none;
            border-radius: 0;
        }
        .preview-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(15, 23, 42, 0.9);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.7rem;
            border: 1px solid rgba(55, 65, 81, 0.9);
        }
        .meta-line {
            font-size: 0.8rem;
            color: #9ca3af;
            line-height: 1.4;
        }
        .meta-line span.key {
            color: #e5e7eb;
            font-weight: 500;
        }
        .gallery-card {
            background: rgba(15, 23, 42, 0.9);
            border-radius: 18px;
            padding: 16px;
            border: 1px solid rgba(55, 65, 81, 0.8);
        }

        /* Skeleton loader for the preview when generating images */
        .preview-frame.loading {
            background: #020617;
        }
        .preview-frame.loading img {
            opacity: 0.1;
            filter: grayscale(1);
        }
        .preview-frame.loading::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(
                120deg,
                rgba(30, 64, 175, 0.1) 0%,
                rgba(148, 163, 184, 0.35) 40%,
                rgba(30, 64, 175, 0.1) 80%
            );
            animation: shimmer 1.4s infinite;
        }
        .preview-frame.loading::after {
            content: "Generating design…";
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.8rem;
            color: #e5e7eb;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.7);
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .gallery-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .gallery-title {
            font-size: 0.9rem;
            font-weight: 600;
        }
        .gallery-count {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }
        .gallery-item {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(55, 65, 81, 0.8);
            background: #020617;
        }
        .gallery-item img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            aspect-ratio: 2 / 3;
        }
        .gallery-item-caption {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 6px 7px;
            font-size: 0.7rem;
            background: linear-gradient(to top, rgba(15, 23, 42, 0.95), transparent);
            color: #e5e7eb;
        }
        .gallery-item-caption span {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Edit button for preview */
        .btn-edit {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 12px;
            border-radius: 6px;
            background: var(--accent);
            color: #ffffff;
            text-decoration: none;
            font-size: 0.8rem;
        }
        .btn-edit:hover {
            background: var(--accent-light);
        }
        .caption-text {
            font-weight: 500;
        }
        .caption-sub {
            font-size: 0.65rem;
            color: #9ca3af;
        }
    </style>
    -->
    <!-- Use shared admin styles and design lab styles for light theme -->
    <link rel="stylesheet" href="admin-styles.css">
    <link rel="stylesheet" href="design_lab.css">
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <!-- Mobile menu toggle -->
        <span class="menu-toggle" id="mobileMenuToggle">&#9776;</span>
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <?php
            // Determine the current page for navigation highlighting
            $currentScript = basename($_SERVER['SCRIPT_NAME']);
        ?>
        <aside class="admin-sidebar">
            <a href="admin_create.php" class="<?php echo $currentScript === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Design Lab</h1>
            <!-- Embed the design lab UI -->
            <div class="page-shell">
                <!-- SECTION: Header -->
                <header class="page-header">
                    <div>
                        <div class="page-title">T-Shirt Design Lab</div>
                        <div class="page-subtitle">Generate print-ready shirt graphics with gpt-image-1</div>
                    </div>
                    <div class="pill">OPENAI_MODEL: <?php echo htmlspecialchars(OPENAI_MODEL); ?></div>
                </header>
                <!-- Left Form -->
                <section class="card">
                    <div class="card-title">Design Prompt</div>
                    <div class="card-sub">Describe the text and the graphic. The model will lay them out together as a shirt design.</div>
                    <form id="design-form" method="post" class="form-grid">
                        <div>
                            <label class="field-label">Text on the shirt <span>*</span></label>
                            <input type="text" name="display_text" class="input-text" placeholder="e.g. Five Cheers to the Goblet of Good Fortune!" required value="<?php echo htmlspecialchars($default_display_text); ?>">
                        </div>
                        <div>
                            <label class="field-label">Graphic / Illustration description <span>*</span></label>
                            <textarea name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. a wizard holding up a glowing goblet, surrounded by sparkles" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
                        </div>
                        <div>
                            <label class="field-label">Background color</label>
                            <div class="field-inline">
                                <input type="color" name="bg_color" id="bg-color-input" class="input-color" value="<?php echo htmlspecialchars($default_bg); ?>">
                                <div class="bg-preview-chip">
                                    <span id="bg-color-label">Solid background used in the prompt</span>
                                    <span class="bg-preview-swatch" id="bg-color-swatch"></span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="field-label">Image model</label>
                            <select name="image_model" class="input-select">
                                <option value="openai">OpenAI (GPT)</option>
                                <option value="gemini">Gemini 2.5</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Transparent background?</label>
                            <input type="checkbox" name="transparent_bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>> Use transparent instead of solid colour
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="submit-btn"><span class="icon">⚡</span><span>Generate T-shirt Design</span></button>
                            <span class="hint">Generation may take a few seconds. Designs are stored in a JSON DB.</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <!-- Right Preview + Gallery -->
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest design</span><br>
                                <strong id="latest-title"><?php echo $preview ? htmlspecialchars($preview['display_text']) : 'No designs yet'; ?></strong>
                            </div>
                            <div style="font-size:0.75rem;color:#6b7280;">Aspect ratio: 2:3<br>Size: 1024×1536</div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color'] ?: '#000000'); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest T-shirt design">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first design will appear here after you hit “Generate”.</span>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-text">
                            <?php if ($preview): ?><span class="key">Text:</span> <?php echo htmlspecialchars($preview['display_text']); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-graphic">
                            <?php if ($preview): ?><span class="key">Graphic:</span> <?php echo htmlspecialchars($preview['graphic']); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-created">
                            <?php if ($preview): ?><span class="key">Created:</span> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($preview['created_at']))); ?><?php endif; ?>
                        </div>
                        <?php if ($preview): ?>
                            <a href="admin_edit_design.php?id=<?php echo urlencode($preview['id']); ?>" class="btn-edit" target="_blank">Edit</a>
                        <?php endif; ?>
                    </div>
                    <div class="gallery-card">
                        <div class="gallery-title-row">
                            <div class="gallery-title">Recent designs</div>
                            <div class="gallery-count" id="gallery-count"><?php echo count($designs); ?> stored in JSON</div>
                        </div>
                        <div class="gallery-grid" id="gallery-grid">
                            <?php if (!empty($designs)): ?>
                                <?php foreach (array_slice($designs, 0, 12) as $item): ?>
                                    <div class="gallery-item">
                                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['file']); ?>" alt="<?php echo htmlspecialchars($item['display_text']); ?>">
                                        <div class="gallery-item-caption">
                                            <span class="caption-text"><?php echo htmlspecialchars($item['display_text']); ?></span>
                                            <span class="caption-sub"><?php echo htmlspecialchars($item['graphic']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:0.8rem;color:#6b7280;">Designs you generate will show up here as a mini gallery.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>
<!-- JavaScript from design lab (adapted for admin) -->
<script>
(function () {
    const bgInput   = document.getElementById('bg-color-input');
    const bgSwatch  = document.getElementById('bg-color-swatch');
    const form      = document.getElementById('design-form');
    const submitBtn = document.getElementById('submit-btn');
    const errorBox  = document.getElementById('error-banner');
    const previewFrame   = document.getElementById('preview-frame');
    const previewImg     = document.getElementById('preview-image');
    const previewBadge   = document.getElementById('preview-badge');
    const previewTitle   = document.getElementById('latest-title');
    const previewPlaceholder = document.getElementById('preview-placeholder');
    const metaText    = document.getElementById('meta-text');
    const metaGraphic = document.getElementById('meta-graphic');
    const metaCreated = document.getElementById('meta-created');
    const galleryGrid  = document.getElementById('gallery-grid');
    const galleryCount = document.getElementById('gallery-count');
    function updateSwatch() {
        if (!bgInput || !bgSwatch) return;
        bgSwatch.style.background = bgInput.value || '#000000';
    }
    if (bgInput) {
        updateSwatch();
        bgInput.addEventListener('input', updateSwatch);
    }
    function setError(msg) {
        if (!errorBox) return;
        if (!msg) {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
        } else {
            errorBox.style.display = 'block';
            errorBox.textContent = msg;
        }
    }
    function startLoading() {
        if (previewFrame) previewFrame.classList.add('loading');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.querySelector('.icon').textContent = '🎨';
            submitBtn.querySelector('span:last-child').textContent = 'Generating...';
        }
        setError('');
    }
    function stopLoading() {
        if (previewFrame) previewFrame.classList.remove('loading');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.querySelector('.icon').textContent = '⚡';
            submitBtn.querySelector('span:last-child').textContent = 'Generate T-shirt Design';
        }
    }
    function updatePreview(design) {
        if (!design) return;
        if (previewTitle) previewTitle.textContent = design.display_text || 'Latest design';
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        if (previewBadge) previewBadge.textContent = 'PNG • ' + (design.bg_color || '#000000');
        if (metaText) metaText.innerHTML = '<span class="key">Text:</span> ' + (design.display_text || '');
        if (metaGraphic) metaGraphic.innerHTML = '<span class="key">Graphic:</span> ' + (design.graphic || '');
        if (metaCreated) metaCreated.innerHTML = '<span class="key">Created:</span> ' + (design.created_at || '');
    }
    function prependToGallery(design) {
        if (!galleryGrid || !design) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'gallery-item';
        wrapper.innerHTML = '<img src="' + design.image_url + '" alt="' + design.display_text.replace(/"/g, '&quot;') + '"><div class="gallery-item-caption"><span class="caption-text">' + design.display_text + '</span><span class="caption-sub">' + design.graphic + '</span></div>';
        if (galleryGrid.firstChild) {
            galleryGrid.insertBefore(wrapper, galleryGrid.firstChild);
        } else {
            galleryGrid.appendChild(wrapper);
        }
        if (galleryCount) {
            const current = parseInt(galleryCount.textContent, 10) || 0;
            galleryCount.textContent = (current + 1) + ' stored in JSON';
        }
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(form);
            formData.append('action', 'generate_design');
            const text   = (formData.get('display_text') || '').toString().trim();
            const graphic= (formData.get('graphic_prompt') || '').toString().trim();
            if (!text || !graphic) {
                setError('Please enter both the T-shirt text and the graphic description.');
                return;
            }
            startLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        setError(data.error || 'Error generating design.');
                        return;
                    }
                    setError('');
                    updatePreview(data.design);
                    prependToGallery(data.design);
                })
                .catch(() => {
                    setError('Network error while generating design.');
                })
                .finally(() => {
                    stopLoading();
                });
        });
    }
})();
</script>
<!-- Mobile menu toggle script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('mobileMenuToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            document.body.classList.toggle('mobile-open');
        });
    }
});
</script>
</body>
</html>