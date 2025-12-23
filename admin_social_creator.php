<?php
/*
 * Admin Social Post Creator
 *
 * This page generates square social media graphics using the Gemini
 * image model. Administrators can specify a headline, optional
 * subheadline, an imagery description, a background colour and an
 * optional reference image. Generated graphics are tagged with the
 * 'social' tool in the JSON database and displayed in a square ratio.
 */

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';
// Define models from configuration if not already defined
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// Assign API keys from configuration
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
// Construct Gemini endpoint
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage paths
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/social_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Load shared helpers and ensure storage is prepared
require_once __DIR__ . '/functions.php';
ensure_storage();

// Determine default selected model; default to OpenAI (GPT) unless provided
$default_model = isset($_POST['image_model']) ? $_POST['image_model'] : 'openai';

// Handle AJAX generation for social posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $title    = trim($_POST['title_text'] ?? '');
    $subtitle = trim($_POST['subtitle_text'] ?? '');
    $graphic  = trim($_POST['graphic_prompt'] ?? '');
    $bgColor  = trim($_POST['bg_color'] ?? '#ffffff');
    // Determine if a transparent background was selected. Only 'transparent' or null will be passed to OpenAI.
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    $chosenModel = $_POST['image_model'] ?? 'openai';
    if ($title === '' || $graphic === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the headline and the imagery description.']);
        exit;
    }
    // Determine aspect ratio for this social graphic
    $ratio = $_POST['aspect_ratio'] ?? 'square';
    switch ($ratio) {
        case 'landscape':
            $ratioDesc = 'landscape 3:2 aspect ratio';
            $sizeParam = '1536x1024';
            break;
        case 'portrait':
            $ratioDesc = 'portrait 2:3 aspect ratio';
            $sizeParam = '1024x1536';
            break;
        default:
            $ratioDesc = 'square 1:1 aspect ratio';
            $sizeParam = '1024x1024';
            break;
    }
    // Build prompt for social post with aspect ratio description
    $prompt = sprintf(
        'Social media graphic, %s. Use a solid %s background. Headline: "%s". Subheadline: "%s". Incorporate imagery: %s. ' .
        'Eye-catching, modern design optimized for social feeds.',
        $ratioDesc,
        $bgColor,
        $title,
        $subtitle,
        $graphic
    );
    // Handle reference image for Gemini only
    $inlineData = null;
    if ($chosenModel === 'gemini' && isset($_FILES['ref_image']) && $_FILES['ref_image']['error'] === UPLOAD_ERR_OK && $_FILES['ref_image']['size'] > 0) {
        $tmp  = $_FILES['ref_image']['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'image/png';
        $bytes = file_get_contents($tmp);
        if ($bytes !== false) {
            $inlineData = [
                'mime_type' => $mime,
                'data'      => base64_encode($bytes)
            ];
        }
    }
    $b64  = null;
    $mime = 'image/png';
    if ($chosenModel === 'gemini') {
        if (!$GEMINI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured. Please set it via the Config page.']);
            exit;
        }
        $result = send_gemini_image_request($prompt, $inlineData, $GEMINI_API_KEY, $ENDPOINT);
        if (!$result[0]) {
            echo json_encode(['success' => false, 'error' => $result[1] ?: 'Error generating image with Gemini.']);
            exit;
        }
        $mime = $result[1];
        $b64  = $result[2];
    } else {
        // Use OpenAI for social graphic; ignore reference image
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
            exit;
        }
        // For OpenAI generation, do not pass the hex colour directly as the background parameter. Use 'transparent' if selected, otherwise null.
        $openResult = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, $sizeParam, OPENAI_MODEL);
        if (!$openResult[0]) {
            echo json_encode(['success' => false, 'error' => $openResult[1] ?: 'Error generating image with OpenAI.']);
            exit;
        }
        $mime = $openResult[1];
        $b64  = $openResult[2];
    }
    // Determine file extension
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    try {
        $filename = 'social_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'social_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, base64_decode($b64)) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('social_', true),
        'display_text' => $title,
        'subtitle'     => $subtitle,
        'graphic'      => $graphic,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => $chosenModel,
        'tool'         => 'social'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'           => $record['id'],
            'display_text' => $record['display_text'],
            'subtitle'     => $record['subtitle'],
            'graphic'      => $record['graphic'],
            'bg_color'     => $record['bg_color'],
            'file'         => $record['file'],
            'created_at'   => $record['created_at'],
            'image_url'    => 'generated_tshirts/' . rawurlencode($record['file']),
        ],
    ]);
    exit;
}

// Load social posts
$designsAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designsFiltered = array_filter($designsAll, function ($item) {
    return ($item['tool'] ?? '') === 'social';
});
$designs = array_reverse($designsFiltered);
$preview = $designs[0] ?? null;
// Compute default form values
$default_title    = isset($_POST['title_text']) ? trim($_POST['title_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_subtitle = isset($_POST['subtitle_text']) ? trim($_POST['subtitle_text']) : (isset($_GET['subtitle']) ? trim($_GET['subtitle']) : '');
$default_graphic  = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg       = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#ffffff');

// Determine default aspect ratio. Social graphics are square by default, but the user can select another aspect ratio.
$default_ratio = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : 'square';
switch ($default_ratio) {
    case 'landscape':
        $ratioDisplay = '3:2';
        $sizeDisplay  = '1536×1024';
        break;
    case 'portrait':
        $ratioDisplay = '2:3';
        $sizeDisplay  = '1024×1536';
        break;
    default:
        // square
        $ratioDisplay = '1:1';
        $sizeDisplay  = '1024×1024';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Social Graphic Creator</title>
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
        .page-shell {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            gap: 24px;
        }
        @media (max-width: 900px) {
            .page-shell {
                grid-template-columns: minmax(0, 1fr);
            }
        }
        .card {
            background: #ffffff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .card-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        .form-grid {
            display: grid;
            gap: 12px;
        }
        .field-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .field-label span.required {
            color: #f97316;
            margin-left: 3px;
        }
        .input-text, .input-color, .input-select {
            width: 100%;
            padding: 10px 11px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #111827;
            font-size: 0.9rem;
            outline: none;
        }
        .input-text:focus, .input-color:focus, .input-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 1px var(--accent);
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
            border: 1px dashed #d1d5db;
            padding: 6px 10px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--text-muted);
        }
        .bg-preview-swatch {
            width: 20px;
            height: 20px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
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
            color: #ffffff;
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
        .hint {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .error-banner {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #b91c1c;
            background: #fee2e2;
            border-radius: 8px;
            padding: 8px 10px;
            border: 1px solid #fecaca;
        }
        .preview-shell {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .preview-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 16px;
            border: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .preview-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .preview-meta strong {
            color: #111827;
        }
        .preview-frame {
            position: relative;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            background: #f9fafb;
            aspect-ratio: 1 / 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .preview-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        .preview-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: #ffffff;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.7rem;
            border: 1px solid #e5e7eb;
        }
        .meta-line {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.4;
        }
        .meta-line span.key {
            color: #111827;
            font-weight: 500;
        }
        .preview-frame.loading {
            background: #f3f4f6;
        }
        .preview-frame.loading img {
            opacity: 0.05;
            filter: grayscale(1);
        }
        .preview-frame.loading::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(209,213,219,0.3) 0%, rgba(229,231,235,0.6) 50%, rgba(209,213,219,0.3) 100%);
            animation: shimmer 1.5s infinite;
        }
        .preview-frame.loading::after {
            content: "Generating design…";
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.8rem;
            color: #6b7280;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .gallery-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 16px;
            border: 1px solid #e5e7eb;
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
            color: var(--text-muted);
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
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .gallery-item img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            aspect-ratio: 1 / 1;
        }
        .gallery-item-caption {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 6px 7px;
            font-size: 0.7rem;
            background: linear-gradient(to top, rgba(0,0,0,0.6), transparent);
            color: #ffffff;
        }
        .gallery-item-caption span {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .caption-text {
            font-weight: 500;
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
        .caption-sub {
            font-size: 0.65rem;
            color: #d1d5db;
        }
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
        }
        .upload-area.dragover {
            border-color: var(--accent);
            background: #f3f4f6;
        }
        .upload-area span {
            pointer-events: none;
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
        <?php
            $currentScript = basename($_SERVER['SCRIPT_NAME']);
            $createPages = [
                'admin_create.php',
                'admin_logo_creator.php',
                'admin_poster_creator.php',
                'admin_flyer_creator.php',
                'admin_social_creator.php',
                'admin_product_photo_creator.php'
            ];
            $isCreate = in_array($currentScript, $createPages);
        ?>
        <aside class="admin-sidebar">
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <!-- Unified Design Lab navigation -->
            <a href="admin_design_lab.php" class="<?php echo ($currentScript === 'admin_design_lab.php' || $currentScript === 'admin_design_lab_gemini.php') ? 'active' : ''; ?>">Design Lab</a>
            <!-- Removed separate Gemini Lab link -->
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_create.php" class="<?php echo $isCreate ? 'active' : ''; ?>">Create</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Social Graphic Creator</h1>
            <div class="page-shell">
                <section class="card">
                    <div class="card-title">Social Post Prompt</div>
                    <div class="card-sub">Enter a headline and an optional subheadline along with an imagery description. You can also upload a reference image to inspire the design.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="generate_design">
                        <div>
                            <label class="field-label" for="title-text">Headline <span class="required">*</span></label>
                            <input type="text" id="title-text" name="title_text" class="input-text" placeholder="e.g. New Arrivals" required value="<?php echo htmlspecialchars($default_title); ?>">
                        </div>
                        <div>
                            <label class="field-label" for="subtitle-text">Subheadline</label>
                            <input type="text" id="subtitle-text" name="subtitle_text" class="input-text" placeholder="e.g. Shop the latest styles" value="<?php echo htmlspecialchars($default_subtitle); ?>">
                        </div>
                        <div>
                            <label class="field-label" for="graphic-prompt">Imagery Description <span class="required">*</span></label>
                            <textarea id="graphic-prompt" name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. fashion models, soft shadows" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
                        </div>
                        <div>
                            <label class="field-label">Background colour</label>
                            <div class="field-inline">
                                <input type="color" name="bg_color" id="bg-color-input" class="input-color" value="<?php echo htmlspecialchars($default_bg); ?>">
                                <div class="bg-preview-chip">
                                    <span id="bg-color-label">Solid background used in the prompt</span>
                                    <span class="bg-preview-swatch" id="bg-color-swatch"></span>
                                </div>
                            </div>
                        </div>
            <div>
                <label class="field-label">Aspect ratio</label>
                <select name="aspect_ratio" class="input-select">
                    <option value="square" <?php echo ($default_ratio === 'square') ? 'selected' : ''; ?>>Square (1:1)</option>
                    <option value="landscape" <?php echo ($default_ratio === 'landscape') ? 'selected' : ''; ?>>Landscape (3:2)</option>
                    <option value="portrait" <?php echo ($default_ratio === 'portrait') ? 'selected' : ''; ?>>Portrait (2:3)</option>
                </select>
            </div>
                        <!-- Transparent background option -->
                        <div>
                            <label class="field-label" for="transparent-bg">
                                <input type="checkbox" name="transparent_bg" id="transparent-bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>> Use transparent instead of solid colour
                            </label>
                        </div>
                        <div>
                            <label class="field-label">Image model</label>
                            <select name="image_model" class="input-select">
                                <option value="openai" <?php echo ($default_model === 'openai') ? 'selected' : ''; ?>>OpenAI (GPT)</option>
                                <option value="gemini" <?php echo ($default_model === 'gemini') ? 'selected' : ''; ?>>Gemini 2.5</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="ref-image">Reference Image (optional)</label>
                            <div class="upload-area" id="upload-area">
                                <span id="upload-placeholder">Drag &amp; drop image here or click to select</span>
                                <input type="file" name="ref_image" id="ref-image" accept="image/*" hidden>
                            </div>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="submit-btn">
                                <span class="icon">⚡</span>
                                <span>Generate Social Graphic</span>
                            </button>
                            <span class="hint">Generation may take a few seconds.</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest social graphic</span><br>
                                <strong id="latest-title"><?php echo $preview ? htmlspecialchars($preview['display_text']) : 'No social graphics yet'; ?></strong>
                            </div>
                            <div style="font-size:0.75rem;color:#6b7280;">Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>Size: <?php echo htmlspecialchars($sizeDisplay); ?></div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color'] ?: '#ffffff'); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest social graphic">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first social graphic will appear here after you hit “Generate”.</span>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-title">
                            <?php if ($preview): ?><span class="key">Headline:</span> <?php echo htmlspecialchars($preview['display_text']); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-subtitle">
                            <?php if ($preview): ?><span class="key">Subheadline:</span> <?php echo htmlspecialchars($preview['subtitle'] ?? ''); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-graphic">
                            <?php if ($preview): ?><span class="key">Imagery:</span> <?php echo htmlspecialchars($preview['graphic']); ?><?php endif; ?>
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
                            <div class="gallery-title">Recent social graphics</div>
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
                                <div style="font-size:0.8rem;color:#6b7280;">Social graphics you generate will show up here as a mini gallery.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>
<script>
(function() {
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
    const metaTitle   = document.getElementById('meta-title');
    const metaSubtitle= document.getElementById('meta-subtitle');
    const metaGraphic = document.getElementById('meta-graphic');
    const metaCreated = document.getElementById('meta-created');
    const galleryGrid  = document.getElementById('gallery-grid');
    const galleryCount = document.getElementById('gallery-count');
    const dropArea     = document.getElementById('upload-area');
    const fileInput    = document.getElementById('ref-image');
    const placeholder  = document.getElementById('upload-placeholder');
    function updateSwatch() {
        if (!bgInput || !bgSwatch) return;
        bgSwatch.style.background = bgInput.value || '#ffffff';
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
            submitBtn.querySelector('span:last-child').textContent = 'Generating…';
        }
        setError('');
    }
    function stopLoading() {
        if (previewFrame) previewFrame.classList.remove('loading');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.querySelector('.icon').textContent = '⚡';
            submitBtn.querySelector('span:last-child').textContent = 'Generate Social Graphic';
        }
    }
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function updatePreview(design) {
        if (!design) return;
        if (previewTitle) previewTitle.textContent = design.display_text || 'Latest social graphic';
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        if (previewBadge) previewBadge.textContent = 'PNG • ' + (design.bg_color || '#ffffff');
        if (metaTitle) metaTitle.innerHTML    = '<span class="key">Headline:</span> ' + escapeHtml(design.display_text || '');
        if (metaSubtitle) metaSubtitle.innerHTML = '<span class="key">Subheadline:</span> ' + escapeHtml(design.subtitle || '');
        if (metaGraphic) metaGraphic.innerHTML = '<span class="key">Imagery:</span> ' + escapeHtml(design.graphic || '');
        if (metaCreated) metaCreated.innerHTML = '<span class="key">Created:</span> ' + (design.created_at || '');
    }
    function prependToGallery(design) {
        if (!galleryGrid || !design) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'gallery-item';
        wrapper.innerHTML = `
            <img src="${design.image_url}" alt="${escapeHtml(design.display_text)}">
            <div class="gallery-item-caption">
                <span class="caption-text">${escapeHtml(design.display_text)}</span>
                <span class="caption-sub">${escapeHtml(design.graphic)}</span>
            </div>
        `;
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
    // Drag and drop for reference image
    if (dropArea && fileInput) {
        dropArea.addEventListener('click', function() {
            fileInput.click();
        });
        dropArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropArea.classList.add('dragover');
        });
        dropArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            dropArea.classList.remove('dragover');
        });
        dropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            dropArea.classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                placeholder.textContent = e.dataTransfer.files[0].name;
            }
        });
        fileInput.addEventListener('change', function() {
            if (fileInput.files && fileInput.files.length > 0) {
                placeholder.textContent = fileInput.files[0].name;
            } else {
                placeholder.textContent = 'Drag & drop image here or click to select';
            }
        });
    }
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(form);
            fd.append('action', 'generate_design');
            const title = (fd.get('title_text') || '').toString().trim();
            const graphic = (fd.get('graphic_prompt') || '').toString().trim();
            if (!title || !graphic) {
                setError('Please enter both the headline and the imagery description.');
                return;
            }
            startLoading();
            fetch(window.location.href, {
                method: 'POST',
                body: fd
            })
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
</body>
</html>