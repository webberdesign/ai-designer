<?php
/*
 * Admin Logo Creator
 *
 * This page allows administrators to generate logo concepts using the
 * Gemini image model. It reuses the common admin layout and provides
 * an input form for the brand name (display text), a description of
 * imagery to include (graphic prompt), and a background colour picker.
 * Generated logos are saved alongside other designs in the JSON DB and
 * displayed in a gallery. Each record is tagged with 'tool' => 'logo'
 * to distinguish it from other design types.
 */

// Model and API configuration loaded from config.json via config.php.
require_once __DIR__ . '/config.php';
// Define the Gemini and OpenAI model constants based on configuration. This ensures the same model names are used across the suite.
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// Assign API keys from config (fallback to empty string)
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
// Construct the Gemini API endpoint using the configured model
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage paths
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/logo_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Handle direct logo uploads (non‑AJAX)
// Administrators can upload their own logo designs via the form below. When a file
// named 'logo_upload' is posted with action 'upload_logo', the image is saved to
// the generated_tshirts folder and a new record is appended to logo_designs.json.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'upload_logo'
    && isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === UPLOAD_ERR_OK
    && $_FILES['logo_upload']['size'] > 0) {
    $displayText = trim($_POST['upload_display_text'] ?? '');
    if ($displayText === '') {
        $displayText = 'Custom Logo';
    }
    $tmpPath  = $_FILES['logo_upload']['tmp_name'];
    $origName = $_FILES['logo_upload']['name'];
    $mime     = mime_content_type($tmpPath) ?: 'application/octet-stream';
    $allowed  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'];
    $ext      = isset($allowed[$mime]) ? $allowed[$mime] : 'png';
    try {
        $filename = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'logo_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $destPath = $OUTPUT_DIR . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        @copy($tmpPath, $destPath);
    }
    // Convert to PNG for consistency and transparency support
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
    // Append to logo DB with source=upload and tool=logo
    $dbUploads = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('logo_', true),
        'display_text' => $displayText,
        'graphic'      => pathinfo($origName, PATHINFO_FILENAME),
        'bg_color'     => '',
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => 'upload',
        'tool'         => 'logo',
    ];
    $dbUploads[] = $record;
    file_put_contents($DB_FILE, json_encode($dbUploads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Redirect back to avoid resubmission and show new logo in gallery
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Load shared helpers and ensure storage
require_once __DIR__ . '/functions.php';
ensure_storage();

// Helper: call Gemini API with a prompt
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

// Helper: parse Gemini response to extract image
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

// Determine extension from mime type
function ext_for_mime_gemini(string $mime): string {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
}

// Generate a unique filename
function safe_name_gemini(string $prefix, string $ext): string {
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
}

// Handle AJAX generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $displayText = trim($_POST['display_text'] ?? '');
    $graphicText = trim($_POST['graphic_prompt'] ?? '');
    $bgColor     = trim($_POST['bg_color'] ?? '#ffffff');
    // Determine if the user wants a transparent background. When checked, pass 'transparent' to the API and avoid
    // sending the hex colour to OpenAI as the background parameter. Otherwise, leave background null so that
    // the default (opaque) behaviour is used. The hex colour is still included in the prompt for design guidance.
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    if ($displayText === '' || $graphicText === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the brand text and the graphic description.']);
        exit;
    }
    // Determine which model to use based on the select input (default Gemini)
    $chosenModel = $_POST['image_model'] ?? 'gemini';
    // Determine the aspect ratio for this generation. Defaults to portrait if unspecified.
    $ratio = $_POST['aspect_ratio'] ?? 'portrait';
    switch ($ratio) {
        case 'landscape':
            $ratioDesc = 'landscape 3:2 aspect ratio';
            $sizeParam = '1536x1024';
            break;
        case 'square':
            $ratioDesc = 'square 1:1 aspect ratio';
            $sizeParam = '1024x1024';
            break;
        default:
            // portrait by default
            $ratioDesc = 'portrait 2:3 aspect ratio';
            $sizeParam = '1024x1536';
            break;
    }
    // Capture optional logo type to influence the style
    $logoType = trim($_POST['logo_type'] ?? '');
    // Build prompt for logo design including aspect ratio description and optional type/style. The colour is passed via the prompt for guidance.
    $typeDesc = $logoType !== '' ? " Style: $logoType." : '';
    $prompt = sprintf(
        'Logo design, %s. Vector flat art with a solid %s background. Design a logo for "%s". The logo should incorporate: %s.%s Minimalistic, modern, balanced composition, suitable for branding.',
        $ratioDesc,
        $bgColor,
        $displayText,
        $graphicText,
        $typeDesc
    );
    $fileMime = 'image/png';
    $imgData  = null;
    // Choose API based on model
    if ($chosenModel === 'openai') {
        // Ensure OpenAI API key is configured
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
            exit;
        }
        // Use the background value determined by the transparent checkbox. Passing the raw hex colour as the
        // background parameter causes API errors. Therefore only 'transparent' or null are valid values.
        $openRes = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, $sizeParam, OPENAI_MODEL);
        if (!$openRes[0]) {
            echo json_encode(['success' => false, 'error' => $openRes[1] ?: 'Error generating image with OpenAI.']);
            exit;
        }
        $fileMime = $openRes[1];
        $imgData  = base64_decode($openRes[2]);
    } else {
        // Default to Gemini
        if (!$GEMINI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured. Please set it via the Config page.']);
            exit;
        }
        // Prepare inline image data if a reference image was uploaded. Only use the reference with Gemini.
        $inlineData = null;
        if (isset($_FILES['ref_image']) && $_FILES['ref_image']['error'] === UPLOAD_ERR_OK && $_FILES['ref_image']['size'] > 0) {
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
        $gemRes = send_gemini_image_request($prompt, $inlineData, $GEMINI_API_KEY, $ENDPOINT);
        if (!$gemRes[0]) {
            echo json_encode(['success' => false, 'error' => $gemRes[1] ?: 'Error generating image with Gemini.']);
            exit;
        }
        $fileMime = $gemRes[1];
        $imgData  = base64_decode($gemRes[2]);
    }
    // Determine file extension based on MIME
    $ext = match ($fileMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    // Generate filename
    try {
        $filename = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'logo_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    // Append record to DB
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('logo_', true),
        'display_text' => $displayText,
        'graphic'      => $graphicText,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => $chosenModel,
        'tool'         => 'logo',
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

// Load existing designs
$designs = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs = array_reverse($designs);
$preview = $designs[0] ?? null;
// Default values from POST/GET
$default_display_text = isset($_POST['display_text']) ? trim($_POST['display_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_graphic      = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg           = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#ffffff');

// Determine default selected image model (OpenAI or Gemini) for the dropdown. Preserve the last chosen option if the form was submitted.
$default_model = isset($_POST['image_model']) ? $_POST['image_model'] : 'gemini';

// Determine default aspect ratio for the logo. Preserve the last chosen ratio if the form was submitted.
// Options: portrait (2:3), landscape (3:2), square (1:1). Default to portrait.
$default_ratio = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : 'portrait';
// Compute display labels for ratio and size for the preview meta. This does not persist per design but reflects the current selection.
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
        $ratioDisplay = '2:3';
        $sizeDisplay  = '1024×1536';
        break;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Logo Creator</title>
    <!--
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
        /* Design form styles similar to other labs */
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
        .field-label span { color: #f97316; margin-left: 3px; }
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
        /* Dropdown style consistent with text inputs */
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
            -webkit-appearance: none;
        }
        .input-text:focus, .input-color:focus {
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
            background: linear-gradient(120deg, rgba(30, 64, 175, 0.1) 0%, rgba(148, 163, 184, 0.35) 40%, rgba(30, 64, 175, 0.1) 80%);
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
        .caption-text { font-weight: 500; }
        .caption-sub { font-size: 0.65rem; color: #9ca3af; }

        /* Upload area styling */
        .upload-area {
            width: 100%;
            padding: 15px;
            border: 2px dashed rgba(75, 85, 99, 0.9);
            border-radius: 10px;
            text-align: center;
            color: #9ca3af;
            cursor: pointer;
            margin-top: 4px;
        }
        .upload-area.dragover {
            border-color: #38bdf8;
            background-color: rgba(56, 189, 248, 0.1);
        }
        .upload-area span {
            display: inline-block;
            pointer-events: none;
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
    </style>
    -->
    <!-- Use shared admin styles and design lab styles for light theme -->
    <link rel="stylesheet" href="admin-styles.css">
    <link rel="stylesheet" href="design_lab.css">
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <span class="menu-toggle" id="mobileMenuToggle">&#9776;</span>
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <?php $currentScript = basename($_SERVER['SCRIPT_NAME']); ?>
        <aside class="admin-sidebar">
            <!-- Reordered navigation: Create, Idea Generator, Library, Designs, Products, Orders, Config -->
            <a href="admin_create.php" class="<?php echo $currentScript === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Logo Creator</h1>
            <div class="page-shell">
                <header class="page-header">
                    <div>
                        <div class="page-title">Logo Design Lab</div>
                        <div class="page-subtitle">Generate logo concepts using OpenAI or Gemini</div>
                    </div>
                    <div class="pill">Models: GPT &amp; Gemini</div>
                </header>

                <!-- Upload your own logo design -->
                <section class="card" style="margin-bottom: 20px;">
                    <div class="card-title">Upload Your Own Logo</div>
                    <div class="card-sub">Choose an image file to upload an existing logo. You can optionally specify the brand name. Uploaded logos are stored in your history and can be edited later.</div>
                    <form method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="upload_logo">
                        <div>
                            <label class="field-label">Logo file <span>*</span></label>
                            <input type="file" name="logo_upload" accept="image/*" class="input-text" required>
                        </div>
                        <div>
                            <label class="field-label">Brand name (optional)</label>
                            <input type="text" name="upload_display_text" class="input-text" placeholder="Brand name">
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary">Upload Logo</button>
                        </div>
                    </form>
                </section>
                <!-- Left: form -->
                <section class="card">
                    <div class="card-title">Brand & Imagery</div>
                    <div class="card-sub">Enter the brand name and describe elements to include in the logo.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <div>
                            <label class="field-label">Brand name <span>*</span></label>
                            <input type="text" name="display_text" class="input-text" placeholder="e.g. Moonlight Café" required value="<?php echo htmlspecialchars($default_display_text); ?>">
                        </div>
                        <div>
                            <label class="field-label">Logo style (optional)</label>
                            <select name="logo_type" class="input-select">
                                <option value="">-- Select style --</option>
                                <?php
                                $types = ['sports','corporate','retail','rugged','sophisticated','playful','minimalistic','vintage','tech','luxury','casual'];
                                $selType = $_POST['logo_type'] ?? '';
                                foreach ($types as $t) {
                                    $selected = ($t === $selType) ? 'selected' : '';
                                    echo '<option value="' . $t . '" ' . $selected . '>' . ucfirst($t) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Imagery / Symbol description <span>*</span></label>
                            <textarea name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. crescent moon with a steaming cup" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
                        </div>
                        <div>
                            <label class="field-label">Background colour</label>
                            <div class="field-inline">
                                <input type="color" name="bg_color" id="bg-color-input" class="input-color" value="<?php echo htmlspecialchars($default_bg ?: '#ffffff'); ?>">
                                <div class="bg-preview-chip">
                                    <span id="bg-color-label">Solid background used in the prompt</span>
                                    <span class="bg-preview-swatch" id="bg-color-swatch"></span>
                                </div>
                            </div>
                        </div>
        <!-- Transparent background option -->
        <div>
            <label class="field-label" for="transparent-bg">
                <input type="checkbox" name="transparent_bg" id="transparent-bg" style="margin-right:6px;" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>> Transparent background
            </label>
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
                        <!-- Image model selection -->
                        <div>
                            <label class="field-label">Image model</label>
                            <select name="image_model" class="input-select">
                                <option value="openai" <?php echo ($default_model === 'openai') ? 'selected' : ''; ?>>OpenAI (GPT)</option>
                                <option value="gemini" <?php echo ($default_model === 'gemini') ? 'selected' : ''; ?>>Gemini 2.5</option>
                            </select>
                        </div>
        <!-- Reference image upload -->
        <div>
            <label class="field-label" for="ref-image">Reference Image (optional)</label>
            <div class="upload-area" id="upload-area">
                <span id="upload-placeholder">Drag &amp; drop image here or click to select</span>
                <input type="file" name="ref_image" id="ref-image" accept="image/*" hidden>
            </div>
        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="submit-btn"><span class="icon">⚡</span><span>Generate Logo</span></button>
                            <span class="hint">Generation may take a few seconds. Logos are stored in JSON.</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <!-- Right: preview + gallery -->
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest logo</span><br>
                                <strong id="latest-title"><?php echo $preview ? htmlspecialchars($preview['display_text']) : 'No logos yet'; ?></strong>
                            </div>
                            <div style="font-size:0.75rem;color:#6b7280;">Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>Size: <?php echo htmlspecialchars($sizeDisplay); ?></div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color'] ?: '#ffffff'); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest logo">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first logo will appear here after you hit “Generate”.</span>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-text">
                            <?php if ($preview): ?><span class="key">Brand:</span> <?php echo htmlspecialchars($preview['display_text']); ?><?php endif; ?>
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
                            <div class="gallery-title">Recent logos</div>
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
                                <div style="font-size:0.8rem;color:#6b7280;">Logos you generate will show up here as a mini gallery.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>
<script>
/* JS – colour preview, AJAX submission and gallery updates */
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

    // Elements for reference image upload
    const dropArea = document.getElementById('upload-area');
    const fileInput = document.getElementById('ref-image');
    const placeholderText = document.getElementById('upload-placeholder');

    // Set up drag & drop upload area for optional reference image (Gemini only)
    if (dropArea && fileInput) {
        ['dragenter','dragover'].forEach(ev => {
            dropArea.addEventListener(ev, e => {
                e.preventDefault();
                dropArea.classList.add('dragover');
            });
        });
        ['dragleave','drop'].forEach(ev => {
            dropArea.addEventListener(ev, e => {
                e.preventDefault();
                dropArea.classList.remove('dragover');
            });
        });
        dropArea.addEventListener('drop', e => {
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                fileInput.files = e.dataTransfer.files;
                placeholderText.textContent = e.dataTransfer.files[0].name;
            }
        });
        dropArea.addEventListener('click', () => {
            fileInput.click();
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files[0]) {
                placeholderText.textContent = fileInput.files[0].name;
            }
        });
    }
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
            submitBtn.querySelector('span:last-child').textContent = 'Generate Logo';
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
        if (previewTitle) previewTitle.textContent = design.display_text || 'Latest logo';
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        if (previewBadge) previewBadge.textContent = 'PNG • ' + (design.bg_color || '#ffffff');
        if (metaText) metaText.innerHTML = '<span class="key">Brand:</span> ' + escapeHtml(design.display_text || '');
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
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(form);
            fd.append('action', 'generate_design');
            const txt = (fd.get('display_text') || '').toString().trim();
            const gr  = (fd.get('graphic_prompt') || '').toString().trim();
            if (!txt || !gr) {
                setError('Please enter both the brand name and the imagery description.');
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
                    setError(data.error || 'Error generating logo.');
                    return;
                }
                setError('');
                updatePreview(data.design);
                prependToGallery(data.design);
            })
            .catch(() => {
                setError('Network error while generating logo.');
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