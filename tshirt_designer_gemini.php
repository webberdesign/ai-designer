<?php
/*
 * PAGE NAME: tshirt_designer_gemini.php
 *
 * A sibling to tshirt_designer.php that uses Google's Gemini image model
 * to generate T‑shirt artwork. This page mirrors the look and feel of the
 * GPT‑powered design lab but calls the Gemini API instead. Designs are
 * stored in the same JSON database as other designs and can be browsed
 * via the built‑in gallery. Like the GPT version, this version supports
 * background colour selection but does not implement transparent
 * backgrounds because Gemini does not support a background parameter.
 */

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';
// Define the Gemini image model constant from configuration if not already defined
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
// Assign the Gemini API key from configuration (leave empty if not set)
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
// Build the Gemini endpoint URL using the configured model
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Directories and database
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/tshirt_designs.json';

// Ensure output and database exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// -----------------------------------------------------------------------------
// Helpers

/**
 * Call the Gemini API to generate an image from a prompt. Returns a tuple
 * [success, error, response_json].
 */
function call_api_gemini(string $key, string $endpoint, string $prompt): array {
    $parts = [];
    if ($prompt !== '') {
        $parts[] = ['text' => $prompt];
    }
    $body = ['contents' => [ ['parts' => $parts] ]];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint . '?key=' . urlencode($key),
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 180,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) return [false, "cURL error: $err", null];
    if ($code !== 200)   return [false, "HTTP $code\n$res", null];
    return [true, null, $res];
}

/**
 * Parse the Gemini API response JSON and extract base64 image data and MIME.
 * Returns [b64_data, mime, usageMetadata].
 */
function parse_image_gemini(string $json): array {
    $j = json_decode($json, true);
    $parts = $j['candidates'][0]['content']['parts'] ?? [];
    foreach ($parts as $p) {
        if (isset($p['inlineData']['data'])) {
            return [
                $p['inlineData']['data'],
                $p['inlineData']['mimeType'] ?? 'image/png',
                $j['usageMetadata'] ?? null
            ];
        }
        if (isset($p['inline_data']['data'])) {
            return [
                $p['inline_data']['data'],
                $p['inline_data']['mime_type'] ?? 'image/png',
                $j['usage_metadata'] ?? null
            ];
        }
    }
    return [null, null, null];
}

/**
 * Determine a file extension based on MIME type. Defaults to PNG.
 */
function ext_for_mime_gemini(string $mime): string {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
}

/**
 * Generate a unique filename with prefix and extension.
 */
function safe_name_gemini(string $prefix, string $ext): string {
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
}

// -----------------------------------------------------------------------------
// Handle AJAX generation requests. The client posts action=generate_design to
// trigger image generation. We validate inputs, build a prompt, call the API,
// save the image to disk, append to the JSON DB, and return JSON to the client.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $displayText = trim($_POST['display_text'] ?? '');
    $graphicText = trim($_POST['graphic_prompt'] ?? '');
    $bgColor     = trim($_POST['bg_color'] ?? '#000000');
    $aspectRatio = $_POST['aspect_ratio'] ?? 'portrait';
    if ($displayText === '' || $graphicText === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the T‑shirt text and the graphic description.']);
        exit;
    }
    // Validate that the Gemini API key is available; instruct the user to configure it via the Config page
    if (!$GEMINI_API_KEY) {
        echo json_encode([
            'success' => false,
            'error'   => 'Gemini API key is not set. Please update it on the Config page.',
        ]);
        exit;
    }
    // Determine ratio description for the prompt
    switch ($aspectRatio) {
        case 'landscape':
            $ratioDesc = 'landscape 3:2';
            break;
        case 'square':
            $ratioDesc = 'square 1:1';
            break;
        default:
            $ratioDesc = 'portrait 2:3';
            break;
    }
    // Build the prompt for Gemini. We mirror the structure used for GPT
    // models but omit the transparent background option because Gemini
    // currently only supports simple prompts.
    $prompt = sprintf(
        'T‑shirt graphic design, %s aspect ratio. Flat, print‑ready art with a solid %s background. ' .
        'Bold, readable typography that says: "%s". Integrated with an illustration of: %s. ' .
        'Encapsulated composition, no mockup, no human model, no extra text, no wrinkles, no background scene. ' .
        'High contrast, vector style, suitable for direct printing on a shirt.',
        $ratioDesc,
        $bgColor,
        $displayText,
        $graphicText
    );
    [$ok, $err, $resp] = call_api_gemini($GEMINI_API_KEY, $ENDPOINT, $prompt);
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => $err ?: 'Unknown error generating image.']);
        exit;
    }
    [$b64, $mime, $usage] = parse_image_gemini($resp);
    if (!$b64) {
        echo json_encode(['success' => false, 'error' => 'No image data returned from Gemini.', 'raw' => $resp]);
        exit;
    }
    // Save the decoded image to disk
    $ext = ext_for_mime_gemini($mime);
    try {
        $filename = safe_name_gemini('ts', $ext);
    } catch (Exception $e) {
        $filename = safe_name_gemini('ts', $ext);
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, base64_decode($b64)) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file. Check folder permissions.']);
        exit;
    }
    // Append record to DB
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('ts_', true),
        'display_text' => $displayText,
        'graphic'      => $graphicText,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => 'gemini',
        'ratio'        => $aspectRatio,
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

// -----------------------------------------------------------------------------
// Load existing designs and set default values for the form. We reverse the
// array to show newest designs first.
$designs = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs = array_reverse($designs);
$preview = $designs[0] ?? null;
// Pre‑fill from POST or GET
$default_display_text = isset($_POST['display_text']) ? trim($_POST['display_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_graphic      = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg           = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#000000');

// Determine default aspect ratio for the form and preview. Accept
// 'landscape', 'portrait', or 'square'; default to portrait. Also compute
// human‑readable ratio and size for display.
$default_ratio = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : (isset($_GET['ratio']) ? $_GET['ratio'] : 'portrait');
switch ($default_ratio) {
    case 'landscape':
        $ratioDisplay = '3:2';
        $sizeDisplay  = '1536×1024';
        break;
    case 'square':
        $ratioDisplay = '1:1';
        $sizeDisplay  = '1024×1024';
        break;
    default:
        $default_ratio = 'portrait';
        $ratioDisplay = '2:3';
        $sizeDisplay  = '1024×1536';
        break;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>T‑Shirt Design Lab – Gemini</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Base reset */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
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
            .page-shell {
                grid-template-columns: minmax(0, 1fr);
            }
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
        .field-label span {
            color: #f97316;
            margin-left: 3px;
        }
        .input-text, .input-color {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(75, 85, 99, 0.9);
            background: rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            font-size: 0.9rem;
            outline: none;
        }
        .input-text:focus, .input-color:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.5);
        }

        /* Select input styling for aspect ratio */
        .input-select {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(75, 85, 99, 0.9);
            background: rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            font-size: 0.9rem;
            outline: none;
            appearance: none;
            background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20width%3D%2212%22%20height%3D%228%22%3E%3Cpath%20fill%3D%22%23ffffff%22%20d%3D%22M1%201l5%205%205-5%22/%3E%3C/svg%3E');
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 12px 8px;
        }
        .input-select:focus {
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
        .hint {
            font-size: 0.75rem;
            color: #6b7280;
        }
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
            background: radial-gradient(circle at top, #111827 0, #020617 60%);
            border-radius: 18px;
            padding: 16px;
            border: 1px solid rgba(55, 65, 81, 0.9);
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
            border-radius: 16px;
            border: 1px solid rgba(55, 65, 81, 0.8);
            overflow: hidden;
            background: #000;
            aspect-ratio: 2 / 3;
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
        /* Skeleton loader */
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
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .gallery-card {
            background: rgba(15, 23, 42, 0.9);
            border-radius: 18px;
            padding: 16px;
            border: 1px solid rgba(55, 65, 81, 0.8);
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
        .caption-text {
            font-weight: 500;
        }
        .caption-sub {
            font-size: 0.65rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
<div class="page-shell">
    <header class="page-header">
        <div>
            <div class="page-title">T‑Shirt Design Lab</div>
            <div class="page-subtitle">Generate print‑ready shirt graphics with Gemini</div>
        </div>
        <div class="pill">GEMINI_MODEL: <?php echo htmlspecialchars(GEMINI_MODEL); ?></div>
    </header>
    <!-- Left: Design form -->
    <section class="card">
        <div class="card-title">Design Prompt</div>
        <div class="card-sub">Describe the text and the graphic. The model lays them out together as a shirt design.</div>
        <form id="design-form" method="post" class="form-grid">
            <div>
                <label class="field-label">Text on the shirt <span>*</span></label>
                <input type="text" name="display_text" class="input-text" placeholder="e.g. Reach for the stars" required value="<?php echo htmlspecialchars($default_display_text); ?>">
            </div>
            <div>
                <label class="field-label">Graphic / Illustration description <span>*</span></label>
                <textarea name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. an astronaut floating among galaxies" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
            </div>
            <div>
                <label class="field-label">Background colour</label>
                <div class="field-inline">
                    <input type="color" name="bg_color" id="bg-color-input" class="input-color" value="<?php echo htmlspecialchars($default_bg ?: '#000000'); ?>">
                    <div class="bg-preview-chip">
                        <span id="bg-color-label">Solid background used in the prompt</span>
                        <span class="bg-preview-swatch" id="bg-color-swatch"></span>
                    </div>
                </div>
            </div>

            <!-- Aspect ratio selection -->
            <div>
                <label class="field-label">Aspect ratio</label>
                <select name="aspect_ratio" class="input-select">
                    <option value="portrait" <?php echo ($default_ratio === 'portrait') ? 'selected' : ''; ?>>Portrait (2:3)</option>
                    <option value="landscape" <?php echo ($default_ratio === 'landscape') ? 'selected' : ''; ?>>Landscape (3:2)</option>
                    <option value="square" <?php echo ($default_ratio === 'square') ? 'selected' : ''; ?>>Square (1:1)</option>
                </select>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn-primary" id="submit-btn">
                    <span class="icon">⚡</span>
                    <span>Generate T‑shirt Design</span>
                </button>
                <span class="hint">AJAX generation with preview + gallery.</span>
            </div>
            <div id="error-banner" class="error-banner" style="display:none;"></div>
        </form>
    </section>
    <!-- Right: Preview + Gallery -->
    <section class="preview-shell">
        <div class="preview-card">
            <div class="preview-meta">
                <div>
                    <span style="font-size:0.75rem;color:#9ca3af;">Latest design</span><br>
                    <strong id="latest-title"><?php echo $preview ? htmlspecialchars($preview['display_text']) : 'No designs yet'; ?></strong>
                </div>
                <div style="font-size:0.75rem;color:#6b7280;">
                    Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>
                    Size: <?php echo htmlspecialchars($sizeDisplay); ?>
                </div>
            </div>
            <div class="preview-frame" id="preview-frame">
                <?php if ($preview): ?>
                    <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color'] ?: '#000000'); ?></span>
                    <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest T‑shirt design">
                <?php else: ?>
                    <span class="preview-badge" id="preview-badge">Waiting…</span>
                    <img id="preview-image" src="" alt="" style="display:none;">
                    <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first design will appear here after you hit “Generate”.</span>
                <?php endif; ?>
            </div>
            <div class="meta-line" id="meta-text">
                <?php if ($preview): ?>
                    <span class="key">Text:</span> <?php echo htmlspecialchars($preview['display_text']); ?>
                <?php endif; ?>
            </div>
            <div class="meta-line" id="meta-graphic">
                <?php if ($preview): ?>
                    <span class="key">Graphic:</span> <?php echo htmlspecialchars($preview['graphic']); ?>
                <?php endif; ?>
            </div>
            <div class="meta-line" id="meta-created">
                <?php if ($preview): ?>
                    <span class="key">Created:</span> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($preview['created_at']))); ?>
                <?php endif; ?>
            </div>
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
<script>
/* JS – BG preview, AJAX submit, and skeleton loader */
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
            submitBtn.querySelector('span:last-child').textContent = 'Generating…';
        }
        setError('');
    }
    function stopLoading() {
        if (previewFrame) previewFrame.classList.remove('loading');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.querySelector('.icon').textContent = '⚡';
            submitBtn.querySelector('span:last-child').textContent = 'Generate T‑shirt Design';
        }
    }
    function updatePreview(design) {
        if (!design) return;
        if (previewTitle) {
            previewTitle.textContent = design.display_text || 'Latest design';
        }
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) {
            previewPlaceholder.style.display = 'none';
        }
        if (previewBadge) {
            previewBadge.textContent = 'PNG • ' + (design.bg_color || '#000000');
        }
        if (metaText) metaText.innerHTML = '<span class="key">Text:</span> ' + escapeHtml(design.display_text || '');
        if (metaGraphic) metaGraphic.innerHTML = '<span class="key">Graphic:</span> ' + escapeHtml(design.graphic || '');
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
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(form);
            fd.append('action', 'generate_design');
            const txt = (fd.get('display_text') || '').toString().trim();
            const gr  = (fd.get('graphic_prompt') || '').toString().trim();
            if (!txt || !gr) {
                setError('Please enter both the T‑shirt text and the graphic description.');
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
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();
</script>
</body>
</html>