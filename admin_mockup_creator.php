<?php
/*
 * Admin Product Mockup Creator
 *
 * This page allows administrators to generate product mockups using either OpenAI's
 * image model or Google's Gemini image model. Users specify the product name and
 * optionally a style or mood to guide the mockup. A background colour may be
 * selected and transparency is available when using OpenAI. An optional reference
 * image can be uploaded when using Gemini to influence the design. Mockups are
 * saved in their own JSON database and displayed in a gallery.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/mockup_designs.json';

if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

ensure_storage();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $product = trim($_POST['product_name'] ?? '');
    $style   = trim($_POST['style_text'] ?? '');
    $bgColor = trim($_POST['bg_color'] ?? '#ffffff');
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    if ($product === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter the product name.']);
        exit;
    }
    $chosenModel = $_POST['image_model'] ?? 'gemini';
    $styleDesc = $style !== '' ? " Style: $style." : '';
    // Determine aspect ratio for mockup (square, landscape or portrait)
    $ratio = $_POST['aspect_ratio'] ?? 'square';
    switch ($ratio) {
        case 'landscape':
            $ratioDesc = 'landscape 3:2';
            $size = '1536x1024';
            break;
        case 'portrait':
            $ratioDesc = 'portrait 2:3';
            $size = '1024x1536';
            break;
        default:
            $ratioDesc = 'square 1:1';
            $size = '1024x1024';
            break;
    }
    $prompt = sprintf(
        'Product mockup, %s aspect ratio. Use a solid %s background. Product: "%s".%s Realistic rendering, professional lighting, high quality.',
        $ratioDesc,
        $bgColor,
        $product,
        $styleDesc
    );
    $inlineData = null;
    if (isset($_FILES['ref_image']) && $_FILES['ref_image']['error'] === UPLOAD_ERR_OK && $_FILES['ref_image']['size'] > 0) {
        $tmp  = $_FILES['ref_image']['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'image/png';
        $bytes = file_get_contents($tmp);
        if ($bytes !== false) {
            $inlineData = ['mime_type' => $mime, 'data' => base64_encode($bytes)];
        }
    }
    $fileMime = 'image/png';
    $imgData  = null;
    if ($chosenModel === 'openai') {
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
            exit;
        }
        // Use dynamic size based on selected aspect ratio
        $openRes = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, $size, OPENAI_MODEL);
        if (!$openRes[0]) {
            echo json_encode(['success' => false, 'error' => $openRes[1] ?: 'Error generating image with OpenAI.']);
            exit;
        }
        $fileMime = $openRes[1];
        $imgData  = base64_decode($openRes[2]);
    } else {
        if (!$GEMINI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured. Please set it via the Config page.']);
            exit;
        }
        $gemRes = send_gemini_image_request($prompt, $inlineData, $GEMINI_API_KEY, $ENDPOINT);
        if (!$gemRes[0]) {
            echo json_encode(['success' => false, 'error' => $gemRes[1] ?: 'Error generating image with Gemini.']);
            exit;
        }
        $fileMime = $gemRes[1];
        $imgData  = base64_decode($gemRes[2]);
    }
    $ext = match ($fileMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    try {
        $filename = 'mockup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'mockup_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'         => uniqid('mockup_', true),
        'product'    => $product,
        'style'      => $style,
        'bg_color'   => $bgColor,
        'file'       => $filename,
        'created_at' => date('c'),
        'published'  => false,
        'source'     => $chosenModel,
        'tool'       => 'mockup'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'         => $record['id'],
            'product'    => $record['product'],
            'style'      => $record['style'],
            'bg_color'   => $record['bg_color'],
            'file'       => $record['file'],
            'created_at' => $record['created_at'],
            'image_url'  => 'generated_tshirts/' . rawurlencode($record['file'])
        ],
    ]);
    exit;
}

$designsAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs    = array_reverse($designsAll);
$preview    = $designs[0] ?? null;

$default_product = isset($_POST['product_name']) ? trim($_POST['product_name']) : (isset($_GET['product']) ? trim($_GET['product']) : '');
$default_style   = isset($_POST['style_text']) ? trim($_POST['style_text']) : (isset($_GET['style']) ? trim($_GET['style']) : '');
$default_bg      = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#ffffff');
$default_model   = isset($_POST['image_model']) ? $_POST['image_model'] : 'gemini';

// Determine default aspect ratio for sticky form and preview metadata
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
    <title>Admin — Product Mockup Creator</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #111827; --secondary: #1f2937; --accent: #2563eb; --text-light: #f3f4f6; --text-muted: #9ca3af; --bg: #f6f7fb; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { margin: 0; font-family: Poppins, system-ui, sans-serif; background: var(--bg); color: #333; }
        .admin-container { display: flex; min-height: 100vh; flex-direction: column; }
        header.admin-header { background: var(--primary); color: var(--text-light); padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        header .brand { font-size: 1.4rem; font-weight: 700; letter-spacing: 0.03em; }
        header .top-nav a { color: var(--text-light); text-decoration: none; margin-left: 20px; font-size: 0.9rem; }
        .admin-main { flex: 1; display: flex; min-height: 0; }
        .admin-sidebar { width: 220px; background: var(--secondary); color: var(--text-light); padding-top: 24px; flex-shrink: 0; display: flex; flex-direction: column; }
        .admin-sidebar a { display: block; padding: 12px 24px; color: var(--text-light); text-decoration: none; font-size: 0.95rem; }
        .admin-sidebar a.active, .admin-sidebar a:hover { background: var(--accent); }
        .admin-content { flex: 1; padding: 24px; overflow-y: auto; }
        h1 { font-size: 1.6rem; margin-top: 0; margin-bottom: 20px; }
        .page-shell { width: 100%; max-width: 1200px; background: radial-gradient(circle at top left, #111827 0, #020617 55%, #020617 100%); border-radius: 24px; padding: 24px; border: 1px solid rgba(148,163,184,0.35); box-shadow: 0 30px 60px rgba(0,0,0,0.7); display: grid; grid-template-columns: minmax(0,1.1fr) minmax(0,0.9fr); gap: 24px; }
        @media (max-width: 900px) { .page-shell { grid-template-columns: minmax(0,1fr); } }
        .card { background: rgba(15,23,42,0.9); border-radius: 18px; padding: 18px 18px 20px; border: 1px solid rgba(55,65,81,0.8); }
        .card-title { font-size: 1rem; font-weight: 600; margin-bottom: 8px; color: #e5e7eb; }
        .card-sub { font-size: 0.8rem; color: #9ca3af; margin-bottom: 16px; }
        .form-grid { display: grid; gap: 12px; }
        .field-label { font-size: 0.8rem; color: #9ca3af; margin-bottom: 4px; }
        .field-label span.required { color: #f97316; margin-left: 3px; }
        .input-text, .input-color, .input-select { width: 100%; padding: 10px 11px; border-radius: 10px; border: 1px solid rgba(75,85,99,0.9); background: rgba(15,23,42,0.9); color: #e5e7eb; font-size: 0.9rem; outline: none; }
        .input-text:focus, .input-color:focus, .input-select:focus { border-color: #38bdf8; box-shadow: 0 0 0 1px rgba(56,189,248,0.5); }
        .field-inline { display: flex; gap: 12px; align-items: center; }
        .field-inline .input-color { max-width: 90px; padding: 0; height: 40px; }
        .bg-preview-chip { flex: 1; border-radius: 999px; border: 1px dashed rgba(148,163,184,0.8); padding: 6px 10px; font-size: 0.8rem; display: flex; align-items: center; justify-content: space-between; color: #9ca3af; }
        .bg-preview-swatch { width: 20px; height: 20px; border-radius: 999px; border: 1px solid rgba(15,23,42,0.8); background: #000; }
        .btn-row { margin-top: 10px; display: flex; gap: 10px; align-items: center; }
        .btn-primary { border: none; border-radius: 999px; padding: 10px 18px; font-size: 0.85rem; font-weight: 600; cursor: pointer; background: linear-gradient(135deg, #06b6d4, #6366f1); color: #f9fafb; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary:disabled { opacity: 0.6; cursor: wait; }
        .error-banner { margin-top: 10px; font-size: 0.8rem; color: #fecaca; background: rgba(127,29,29,0.6); border-radius: 10px; padding: 8px 10px; border: 1px solid rgba(248,113,113,0.7); }
        .preview-shell { display: flex; flex-direction: column; gap: 12px; }
        .preview-card { background: radial-gradient(circle at top, #111827 0, #020617 60%); border-radius: 18px; padding: 16px; border: 1px solid rgba(55,65,81,0.9); display: flex; flex-direction: column; gap: 10px; }
        .preview-meta { display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; color: #9ca3af; }
        .preview-meta strong { color: #e5e7eb; }
        .preview-frame { position: relative; border-radius: 16px; border: 1px solid rgba(55,65,81,0.8); overflow: hidden; background: #000; aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center; }
        .preview-frame img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .preview-badge { position: absolute; top: 10px; left: 10px; background: rgba(15,23,42,0.9); border-radius: 999px; padding: 4px 10px; font-size: 0.7rem; border: 1px solid rgba(55,65,81,0.9); }
        .meta-line { font-size: 0.8rem; color: #9ca3af; line-height: 1.4; }
        .meta-line span.key { color: #e5e7eb; font-weight: 500; }
        .gallery-card { background: rgba(15,23,42,0.9); border-radius: 18px; padding: 16px; border: 1px solid rgba(55,65,81,0.8); }
        .gallery-title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .gallery-title { font-size: 0.9rem; font-weight: 600; }
        .gallery-count { font-size: 0.75rem; color: #6b7280; }
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
        .gallery-item { position: relative; border-radius: 12px; overflow: hidden; border: 1px solid rgba(55,65,81,0.8); background: #020617; }
        .gallery-item img { width: 100%; height: 100%; display: block; object-fit: cover; aspect-ratio: 1 / 1; }
        .gallery-item-caption { position: absolute; bottom: 0; left: 0; right: 0; padding: 6px 7px; font-size: 0.7rem; background: linear-gradient(to top, rgba(15,23,42,0.95), transparent); color: #e5e7eb; }
        .gallery-item-caption span { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .caption-text { font-weight: 500; }
        .caption-sub { font-size: 0.65rem; color: #9ca3af; }
        .preview-frame.loading { background: #020617; }
        .preview-frame.loading img { opacity: 0.1; filter: grayscale(1); }
        .preview-frame.loading::before { content: ""; position: absolute; inset: 0; background: linear-gradient(120deg, rgba(30,64,175,0.1) 0%, rgba(148,163,184,0.35) 40%, rgba(30,64,175,0.1) 80%); animation: shimmer 1.4s infinite; }
        .preview-frame.loading::after { content: "Generating design…"; position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); font-size: 0.8rem; color: #e5e7eb; text-shadow: 0 2px 4px rgba(0,0,0,0.7); }
        @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
        .upload-wrapper { border: 1.5px dashed #3b4f78; border-radius: 14px; padding: 26px; text-align: center; background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); cursor: pointer; position: relative; }
        .upload-wrapper input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .upload-wrapper.dragover { border-color: #5d6070; }
        .upload-wrapper strong { font-weight: 700; display: block; margin-bottom: 6px; }
        .upload-wrapper .help { font-size: 12px; color: var(--text-muted); }
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
        <?php $currentScript = basename($_SERVER['SCRIPT_NAME']); ?>
        <aside class="admin-sidebar">
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <!-- Unified Design Lab navigation -->
            <a href="admin_design_lab.php" class="<?php echo ($currentScript === 'admin_design_lab.php' || $currentScript === 'admin_design_lab_gemini.php') ? 'active' : ''; ?>">Design Lab</a>
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_create.php" class="<?php echo $currentScript === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Product Mockups</h1>
            <div class="page-shell">
                <section class="card">
                    <div class="card-title">Design Prompt</div>
                    <div class="card-sub">Describe your product and an optional style or mood. The AI will generate a realistic product mockup.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="generate_design">
                        <div>
                            <label class="field-label">Product Name <span class="required">*</span></label>
                            <input type="text" name="product_name" class="input-text" placeholder="e.g. Organic shampoo" required value="<?php echo htmlspecialchars($default_product); ?>">
                        </div>
                        <div>
                            <label class="field-label">Style / Mood</label>
                            <input type="text" name="style_text" class="input-text" placeholder="e.g. minimalistic, vintage, luxurious" value="<?php echo htmlspecialchars($default_style); ?>">
                        </div>
                        <div>
                            <label class="field-label">Background Colour</label>
                            <div class="field-inline">
                                <input type="color" name="bg_color" id="bg-color-input" class="input-color" value="<?php echo htmlspecialchars($default_bg); ?>">
                                <div class="bg-preview-chip">
                                    <span id="bg-color-label">Solid background used in the prompt</span>
                                    <span class="bg-preview-swatch" id="bg-color-swatch"></span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="field-label">
                                <input type="checkbox" name="transparent_bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>> Transparent background (OpenAI only)
                            </label>
                        </div>
                        <div>
                            <label class="field-label">Aspect ratio</label>
                            <select name="aspect_ratio" class="input-select">
                                <option value="square" <?php echo ($default_ratio === 'square') ? 'selected' : ''; ?>>Square (1:1)</option>
                                <option value="landscape" <?php echo ($default_ratio === 'landscape') ? 'selected' : ''; ?>>Landscape (3:2)</option>
                                <option value="portrait" <?php echo ($default_ratio === 'portrait') ? 'selected' : ''; ?>>Portrait (2:3)</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Image Model</label>
                            <select name="image_model" class="input-select">
                                <option value="openai" <?php echo $default_model === 'openai' ? 'selected' : ''; ?>>OpenAI (GPT)</option>
                                <option value="gemini" <?php echo $default_model === 'gemini' ? 'selected' : ''; ?>>Gemini 2.5</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Reference Image (optional)</label>
                            <div class="upload-wrapper" id="upload-wrapper">
                                <strong>Drag & drop to upload</strong>
                                <span class="help">Tap to choose from files</span>
                                <input type="file" name="ref_image" id="file-input" accept="image/*">
                            </div>
                            <div id="upload-msg" class="help" style="margin-top: 8px;"></div>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="submit-btn">
                                <span class="icon">📦</span>
                                <span>Generate Mockup</span>
                            </button>
                            <span class="help">AJAX generation with preview + gallery.</span>
                        </div>
                        <div class="error-banner" id="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest mockup</span><br>
                                <strong id="latest-title">
                                    <?php echo $preview ? htmlspecialchars($preview['product']) : 'No designs yet'; ?>
                                </strong>
                            </div>
                            <div style="font-size:0.75rem;color:#6b7280;">Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>Size: <?php echo htmlspecialchars($sizeDisplay); ?></div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color']); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest mockup design">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first design will appear here after you hit “Generate”.</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($preview): ?>
                            <div class="meta-line" id="meta-product"><span class="key">Product:</span> <?php echo htmlspecialchars($preview['product']); ?></div>
                            <?php if ($preview['style']): ?>
                                <div class="meta-line" id="meta-style"><span class="key">Style:</span> <?php echo htmlspecialchars($preview['style']); ?></div>
                            <?php endif; ?>
                            <div class="meta-line" id="meta-created"><span class="key">Created:</span> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($preview['created_at']))); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="gallery-card">
                        <div class="gallery-title-row">
                            <div class="gallery-title">Recent designs</div>
                            <div class="gallery-count" id="gallery-count"><?php echo count($designs); ?> saved</div>
                        </div>
                        <div class="gallery-grid" id="gallery-grid">
                            <?php if (!empty($designs)): ?>
                                <?php foreach (array_slice($designs, 0, 12) as $item): ?>
                                    <div class="gallery-item">
                                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['file']); ?>" alt="<?php echo htmlspecialchars($item['product']); ?>">
                                        <div class="gallery-item-caption">
                                            <span class="caption-text"><?php echo htmlspecialchars($item['product']); ?></span>
                                            <?php if ($item['style']): ?><span class="caption-sub"><?php echo htmlspecialchars($item['style']); ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:0.8rem;color:#6b7280;">Your designs will appear here after generation.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>
<script>
// JS helpers for mockup creator
(function(){
    const bgInput   = document.getElementById('bg-color-input');
    const bgSwatch  = document.getElementById('bg-color-swatch');
    const form      = document.getElementById('design-form');
    const submitBtn = document.getElementById('submit-btn');
    const errorBox  = document.getElementById('error-banner');
    const previewFrame = document.getElementById('preview-frame');
    const previewImg   = document.getElementById('preview-image');
    const previewBadge = document.getElementById('preview-badge');
    const previewTitle = document.getElementById('latest-title');
    const previewPlaceholder = document.getElementById('preview-placeholder');
    const metaProduct  = document.getElementById('meta-product');
    const metaStyle    = document.getElementById('meta-style');
    const metaCreated  = document.getElementById('meta-created');
    const galleryGrid  = document.getElementById('gallery-grid');
    const galleryCount = document.getElementById('gallery-count');
    const uploadWrapper= document.getElementById('upload-wrapper');
    const fileInput    = document.getElementById('file-input');
    const uploadMsg    = document.getElementById('upload-msg');
    function updateSwatch(){ if(bgInput && bgSwatch){ bgSwatch.style.background = bgInput.value || '#000'; }}
    if(bgInput){ updateSwatch(); bgInput.addEventListener('input', updateSwatch); }
    function setError(msg){ if(!errorBox) return; if(!msg){ errorBox.style.display='none'; errorBox.textContent=''; } else { errorBox.style.display='block'; errorBox.textContent=msg; } }
    function startLoading(){ if(previewFrame) previewFrame.classList.add('loading'); if(submitBtn){ submitBtn.disabled = true; submitBtn.querySelector('.icon').textContent = '📦'; submitBtn.querySelector('span:last-child').textContent = 'Generating...'; } setError(''); }
    function stopLoading(){ if(previewFrame) previewFrame.classList.remove('loading'); if(submitBtn){ submitBtn.disabled = false; submitBtn.querySelector('.icon').textContent = '📦'; submitBtn.querySelector('span:last-child').textContent = 'Generate Mockup'; } }
    function updatePreview(design){ if(!design) return; if(previewTitle){ previewTitle.textContent = design.product || 'Latest mockup'; } if(previewImg){ previewImg.src = design.image_url; previewImg.style.display='block'; } if(previewPlaceholder){ previewPlaceholder.style.display='none'; } if(previewBadge){ previewBadge.textContent = 'PNG • ' + (design.bg_color || '#ffffff'); }
        if(metaProduct) metaProduct.innerHTML = '<span class="key">Product:</span> ' + (design.product || '');
        if(metaStyle){ if(design.style){ metaStyle.style.display='block'; metaStyle.innerHTML = '<span class="key">Style:</span> ' + design.style; } else { metaStyle.style.display='none'; } }
        if(metaCreated) metaCreated.innerHTML = '<span class="key">Created:</span> ' + design.created_at;
    }
    function prependToGallery(design){ if(!galleryGrid || !design) return; const wrapper = document.createElement('div'); wrapper.className = 'gallery-item'; wrapper.innerHTML = '<img src="'+design.image_url+'" alt="'+(design.product||'')+'"><div class="gallery-item-caption"><span class="caption-text">'+(design.product||'')+'</span>'+(design.style?'<span class="caption-sub">'+design.style+'</span>':'')+'</div>';
        if(galleryGrid.firstChild){ galleryGrid.insertBefore(wrapper, galleryGrid.firstChild); } else { galleryGrid.appendChild(wrapper); }
        if(galleryCount){ const current = parseInt(galleryCount.textContent) || 0; galleryCount.textContent = (current + 1) + ' saved'; }
    }
    if(uploadWrapper && fileInput){ ['dragenter','dragover'].forEach(ev => uploadWrapper.addEventListener(ev, e => { e.preventDefault(); uploadWrapper.classList.add('dragover'); })); ['dragleave','drop'].forEach(ev => uploadWrapper.addEventListener(ev, e => { e.preventDefault(); uploadWrapper.classList.remove('dragover'); })); uploadWrapper.addEventListener('drop', e => { if(e.dataTransfer.files && e.dataTransfer.files[0]){ fileInput.files = e.dataTransfer.files; uploadMsg.textContent = 'Selected '+e.dataTransfer.files[0].name; } }); uploadWrapper.addEventListener('click', () => fileInput.click()); fileInput.addEventListener('change', e => { if(e.target.files[0]){ uploadMsg.textContent = 'Selected '+e.target.files[0].name; } }); }
    if(form){ form.addEventListener('submit', function(e){ e.preventDefault(); const formData = new FormData(form); formData.append('action','generate_design'); const product = formData.get('product_name')?.toString().trim(); if(!product){ setError('Please enter the product name.'); return; } startLoading(); fetch(window.location.href, { method:'POST', body: formData }).then(res => res.json()).then(data => { if(!data.success){ setError(data.error || 'Error generating design.'); return; } setError(''); updatePreview(data.design); prependToGallery(data.design); }).catch(() => { setError('Network error while generating design.'); }).finally(() => { stopLoading(); }); }); }
})();
</script>
</body>
</html>