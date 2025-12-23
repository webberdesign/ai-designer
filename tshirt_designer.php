<?php
/*
 * PAGE NAME: tshirt_designer.php
 *
 * An AJAX‑driven T‑shirt design lab that leverages the gpt‑image‑1 model
 * to generate high resolution artwork. This version accepts optional GET
 * parameters (text, graphic, bg, colors) which pre‑fill the form when
 * arriving from the idea generator page. Like the earlier version, it
 * stores completed designs in a JSON file and shows a gallery of recent
 * creations. Users can edit their inputs before generating and view the
 * result with a skeleton loader while waiting.
 */

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';
// Bring in shared helper functions for image generation
require_once __DIR__ . '/functions.php';

// Define Gemini constants from config if not already defined. We allow users to
// choose between OpenAI and Gemini models for front‑end generation. The
// default Gemini model is taken from configuration.
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}

// Define the OpenAI image model constant from configuration if not already defined
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// Assign the OpenAI API key from configuration (leave empty if not set)
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';

// Assign the Gemini API key from configuration (leave empty if not set)
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';

// Build the Gemini endpoint URL using the configured model
$GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";
// Set output directory and JSON database path
$OUTPUT_DIR     = __DIR__ . '/generated_tshirts';
$DB_FILE        = __DIR__ . '/tshirt_designs.json';

// Separate database for user uploads. Uploaded designs (via the non‑AJAX file
// upload form) are stored in this file and tagged with tool=upload and
// source=upload. They are hidden by default in the media library unless
// the 'upload' filter is selected.
$UPLOADS_DB     = __DIR__ . '/upload_designs.json';
if (!file_exists($UPLOADS_DB)) {
    file_put_contents($UPLOADS_DB, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Make sure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// -----------------------------------------------------------------------------
// Handle direct design uploads (non‑AJAX)
// If a user uploads their own design via the file input, this block will save
// the uploaded image to the generated_tshirts folder, create a new design
// record in the JSON DB, and then redirect back to this page to show the
// result. This branch is triggered when a file is uploaded and no action
// parameter is set (i.e., not an AJAX generate request).
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST['action'])
    && isset($_FILES['design_upload'])
    && $_FILES['design_upload']['error'] === UPLOAD_ERR_OK
    && $_FILES['design_upload']['size'] > 0) {
    // Ensure the design text is provided (graphic prompt is optional when uploading)
    $displayText = trim($_POST['display_text'] ?? '');
    if ($displayText === '') {
        // If no display text, set a default placeholder
        $displayText = 'Custom Design';
    }
    // Determine original filename and MIME
    $tmpPath = $_FILES['design_upload']['tmp_name'];
    $origName = $_FILES['design_upload']['name'];
    $mime  = mime_content_type($tmpPath) ?: 'application/octet-stream';
    // Only allow common image types
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        // Unsupported format: move on with default PNG extension
        $ext = 'png';
    } else {
        $ext = $allowed[$mime];
    }
    // Generate a unique filename
    try {
        $filename = 'ts_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'ts_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $destPath = $OUTPUT_DIR . '/' . $filename;
    // Move uploaded file to destination; if it fails, attempt copy
    if (!move_uploaded_file($tmpPath, $destPath)) {
        @copy($tmpPath, $destPath);
    }
    // If original type not PNG and Imagick is available, convert to PNG to ensure transparency support
    if ($ext !== 'png' && class_exists('Imagick') && is_file($destPath)) {
        try {
            $im = new Imagick($destPath);
            $im->setImageFormat('png');
            $pngPath = preg_replace('~\.[A-Za-z0-9]+$~', '.png', $destPath);
            $im->writeImage($pngPath);
            $im->clear();
            $im->destroy();
            // Remove original and update filename
            @unlink($destPath);
            $destPath = $pngPath;
            $filename = basename($destPath);
        } catch (Throwable $t) {
            // Silently ignore conversion errors
        }
    }
    // Build record for uploads DB. Tag as tool=upload and source=upload so they
    // can be filtered separately in the media library. Use filename minus
    // extension as a placeholder graphic description.
    $uploadDb = json_decode(@file_get_contents($UPLOADS_DB), true) ?: [];
    $record = [
        'id'           => uniqid('upload_', true),
        'display_text' => $displayText,
        'graphic'      => pathinfo($origName, PATHINFO_FILENAME),
        'bg_color'     => '',
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'tool'         => 'upload',
        'source'       => 'upload'
    ];
    $uploadDb[] = $record;
    file_put_contents($UPLOADS_DB, json_encode($uploadDb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Redirect back to avoid resubmission and show new design
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}


// Helper for OpenAI image generation
// Accepts an optional $background parameter. If provided, it is used to set
// transparent or opaque backgrounds (e.g., 'transparent' or 'opaque').
// Update image generation helper to accept a custom size for different aspect ratios.
function generate_tshirt_image($apiKey, $prompt, &$error = '', $background = null, $size = '1024x1536')
{
    $url = 'https://api.openai.com/v1/images/generations';
    $payload = [
        'model'   => OPENAI_MODEL,
        'prompt'  => $prompt,
        // Set the size dynamically based on the selected aspect ratio. Valid sizes
        // include 1536x1024 (landscape 3:2), 1024x1536 (portrait 2:3) and 1024x1024 (square 1:1).
        'size'    => $size,
        'n'       => 1,
        'quality' => 'high',
        // Always request PNG to support transparency. This parameter must be called
        // output_format for gpt-image-1; see OpenAI API docs.
        'output_format'  => 'png',
    ];
    // If transparent background requested, set background parameter
    if ($background) {
        $payload['background'] = $background;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
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

// Handle AJAX request for image generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $displayText = trim($_POST['display_text'] ?? '');
    $graphicText = trim($_POST['graphic_prompt'] ?? '');
    $bgColor     = trim($_POST['bg_color'] ?? '#000000');
    $imageModel  = $_POST['image_model'] ?? 'openai';
    // Reference image selection: existing reference ID from library (optional)
    $existingRef = trim($_POST['existing_ref'] ?? '');
    $aspectRatio = $_POST['aspect_ratio'] ?? 'portrait';
    if ($displayText === '' || $graphicText === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the T‑shirt text and the graphic description.']);
        exit;
    }
    // Determine ratio description and size based on the selected aspect ratio
    switch ($aspectRatio) {
        case 'landscape':
            $ratioDesc = 'landscape 3:2';
            $size      = '1536x1024';
            break;
        case 'square':
            $ratioDesc = 'square 1:1';
            $size      = '1024x1024';
            break;
        default:
            $ratioDesc = 'portrait 2:3';
            $size      = '1024x1536';
            break;
    }
    // Build the prompt with the selected ratio description
    $prompt = sprintf(
'T-shirt graphic design, %s aspect ratio. 
Crisp, print-ready vector artwork on a solid %s background. 
Include a unified **encapsulating shape or border** (badge, crest, emblem, patch, shield, or geometric frame) that visually relates to the theme. 
Typography must be bold, centered, and clearly readable, featuring the exact phrase: "%s". 
Integrate the text with a strong illustration of: %s — both elements should feel fused into one cohesive emblem. 
No mockups, no humans, no photos, no scenes, no shadows, no wrinkles. 
Use high-contrast colors, clean edges, and a tight contained layout optimized for T-shirt printing.'
        $ratioDesc,
        $bgColor,
        $displayText,
        $graphicText
    );
    // Determine if transparent background is requested; only supported for OpenAI
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    $error  = '';
    $filename = null;
    // Process according to selected image model
    if ($imageModel === 'gemini') {
        // Ensure Gemini API key is set
        if (!$GEMINI_API_KEY) {
            echo json_encode([
                'success' => false,
                'error'   => 'Gemini API key is not set. Please update it on the Config page.',
            ]);
            exit;
        }
        // Determine inline reference image for Gemini. We check for an uploaded
        // reference file first, then fall back to an existing reference from the
        // library if provided. If none, we pass null. Note: reference is only
        // used for Gemini; OpenAI will ignore it.
        $inlineData = null;
        // Uploaded reference takes priority
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
        } elseif ($existingRef !== '') {
            // Look up the existing record to get file path and mime
            $foundRec = null;
            $searchFiles = [
                __DIR__ . '/tshirt_designs.json',
                __DIR__ . '/logo_designs.json',
                __DIR__ . '/poster_designs.json',
                __DIR__ . '/flyer_designs.json',
                __DIR__ . '/social_designs.json',
                __DIR__ . '/photo_designs.json',
                __DIR__ . '/business_card_designs.json',
                __DIR__ . '/certificate_designs.json',
                __DIR__ . '/packaging_designs.json',
                __DIR__ . '/illustration_designs.json',
                __DIR__ . '/mockup_designs.json',
                __DIR__ . '/vector_designs.json',
                __DIR__ . '/upload_designs.json'
            ];
            foreach ($searchFiles as $sf) {
                if (!file_exists($sf)) continue;
                $list = json_decode(@file_get_contents($sf), true);
                if (!is_array($list)) continue;
                foreach ($list as $rec) {
                    if (($rec['id'] ?? '') === $existingRef) {
                        $foundRec = $rec;
                        break 2;
                    }
                }
            }
            if ($foundRec && !empty($foundRec['file'])) {
                $filepath = __DIR__ . '/generated_tshirts/' . $foundRec['file'];
                if (is_file($filepath)) {
                    $mime = mime_content_type($filepath) ?: 'image/png';
                    $bytes = file_get_contents($filepath);
                    if ($bytes !== false) {
                        $inlineData = [
                            'mime_type' => $mime,
                            'data'      => base64_encode($bytes)
                        ];
                    }
                }
            }
        }
        // Send prompt to Gemini API (Gemini does not support background parameter)
        [$ok, $mimeOrErr, $b64] = send_gemini_image_request($prompt, $inlineData, $GEMINI_API_KEY, $GEMINI_ENDPOINT);
        if (!$ok) {
            echo json_encode(['success' => false, 'error' => $mimeOrErr ?: 'Unknown error generating image.']);
            exit;
        }
        $mime = $mimeOrErr;
        // Save the image to disk
        [$saved, $err, $filename] = save_image_base64($b64, $mime, 'ts');
        if (!$saved) {
            echo json_encode(['success' => false, 'error' => $err ?: 'Could not save generated file.']);
            exit;
        }
    } else {
        // Default to OpenAI
        if (!$OPENAI_API_KEY) {
            echo json_encode([
                'success' => false,
                'error'   => 'OpenAI API key is not set. Please update it on the Config page.',
            ]);
            exit;
        }
        // Generate image via OpenAI API
        $imgData = generate_tshirt_image($OPENAI_API_KEY, $prompt, $error, $background, $size);
        if ($imgData === null) {
            echo json_encode(['success' => false, 'error' => $error ?: 'Unknown error generating image.']);
            exit;
        }
        // Generate unique filename
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
    }
    // Record the design in the database
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('ts_', true),
        'display_text' => $displayText,
        'graphic'      => $graphicText,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => ($imageModel === 'gemini') ? 'gemini' : 'openai',
        'ratio'        => $aspectRatio,
        'tool'         => 'tshirt'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Respond with success and design details
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

// Load designs for initial display
// Load designs for initial display
$designs = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs = array_reverse($designs);
$preview = $designs[0] ?? null;

// Gather reference options from all design databases (including uploads). These
// records can be used as inline images for Gemini generation. We collect
// basic info: id, title, and file path. The library uses this mapping
// extensively; here we build a smaller list for the reference select.
$reference_options = [];
$refFiles = [
    __DIR__ . '/tshirt_designs.json',
    __DIR__ . '/logo_designs.json',
    __DIR__ . '/poster_designs.json',
    __DIR__ . '/flyer_designs.json',
    __DIR__ . '/social_designs.json',
    __DIR__ . '/photo_designs.json',
    __DIR__ . '/business_card_designs.json',
    __DIR__ . '/certificate_designs.json',
    __DIR__ . '/packaging_designs.json',
    __DIR__ . '/illustration_designs.json',
    __DIR__ . '/mockup_designs.json',
    __DIR__ . '/vector_designs.json',
    __DIR__ . '/upload_designs.json'
];
foreach ($refFiles as $rf) {
    if (!file_exists($rf)) continue;
    $list = json_decode(@file_get_contents($rf), true);
    if (!is_array($list)) continue;
    foreach ($list as $rec) {
        // Must have file to be used as reference
        if (!isset($rec['file'])) continue;
        $displayTitle = '';
        if (!empty($rec['display_text'])) {
            $displayTitle = $rec['display_text'];
        } elseif (!empty($rec['name'])) {
            $displayTitle = $rec['name'];
        } elseif (!empty($rec['title'])) {
            $displayTitle = $rec['title'];
        } elseif (!empty($rec['product_name'])) {
            $displayTitle = $rec['product_name'];
        } elseif (!empty($rec['subject'])) {
            $displayTitle = $rec['subject'];
        } else {
            $displayTitle = $rec['id'];
        }
        // Exclude this page's own uploads to avoid referencing itself? Not necessary.
        $reference_options[] = [
            'id'    => $rec['id'],
            'title' => $displayTitle,
            'file'  => $rec['file']
        ];
    }
}

// Pre‑fill values from GET parameters if provided and no POST values yet
$default_display_text = isset($_POST['display_text']) ? trim($_POST['display_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_graphic      = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg           = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#000000');

// Determine the default aspect ratio for pre‑filling the form and preview. We look
// first at POST data, then GET parameters, and fall back to portrait. Valid
// values are 'landscape', 'portrait', and 'square'.
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
        // fall back to portrait
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
    <title>T‑Shirt Design Lab – gpt-image-1</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!--
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

        /* Select input styling for aspect ratio and image model */
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
            /* Make arrow consistent with other inputs */
            background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20width%3D%2212%22%20height%3D%228%22%3E%3Cpath%20fill%3D%22%23ffffff%22%20d%3D%22M1%201l5%205%205-5%22/%3E%3C/svg%3E');
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 12px 8px;
        }
        .input-select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.5);
        }

        /* Select input styling matches text inputs */
        .input-select {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(75, 85, 99, 0.9);
            background: rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            font-size: 0.9rem;
            outline: none;
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
        .preview-shell { display: flex; flex-direction: column; gap: 12px; }
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
        .preview-meta strong { color: #e5e7eb; }
        /* Allow the generated design to breathe on the page by removing the tight
           rounded container and border. The preview frame now serves only as a
           positioning context and aspect ratio constraint without visually boxing
           in the artwork. */
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
        /* Skeleton loader */
        .preview-frame.loading { background: #020617; }
        .preview-frame.loading img { opacity: 0.1; filter: grayscale(1); }
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
        @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
        /* Gallery */
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
        .gallery-item-caption span { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .caption-text { font-weight: 500; }
        .caption-sub { font-size: 0.65rem; color: #9ca3af; }

        /* Editor link */
        .editor-link {
            margin-top: 6px;
            font-size: 0.75rem;
            color: #06b6d4;
            text-decoration: none;
        }
    </style>
    -->
    <!-- Use global public styles and T‑shirt specific overrides -->
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="tshirt-styles.css">
</head>
<body>
<div class="page-shell">
    <header class="page-header">
        <div>
            <div class="page-title">T‑Shirt Design Lab</div>
            <div class="page-subtitle">Generate print‑ready shirt graphics with gpt-image-1</div>
        </div>
        <div class="pill">OPENAI_MODEL: <?php echo htmlspecialchars(OPENAI_MODEL); ?></div>
    </header>
    <!-- Left – Form -->
    <section class="card">
        <div class="card-title">Design Prompt</div>
        <div class="card-sub">Describe the text and the graphic. The model lays them out together as a shirt design.</div>

        <!-- Custom design upload form -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-title">Upload Your Own Design</div>
            <div class="card-sub">Choose an image file to use as a T‑shirt design. You can optionally specify the text that appears on the design. The uploaded design will be saved in your history and can be used later as a reference.</div>
            <form method="post" enctype="multipart/form-data" style="margin-top: 12px;">
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Image file <span>*</span></label>
                    <input type="file" name="design_upload" accept="image/*" class="input-text" required>
                </div>
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Text for uploaded design</label>
                    <input type="text" name="display_text" class="input-text" placeholder="Optional display text">
                </div>
                <button type="submit" class="btn-primary">Upload Design</button>
            </form>
        </div>
        <form id="design-form" method="post" class="form-grid" enctype="multipart/form-data">
            <div>
                <label class="field-label">Text on the shirt <span>*</span></label>
                <input type="text" name="display_text" class="input-text" placeholder="e.g. Keep Looking Up" required value="<?php echo htmlspecialchars($default_display_text); ?>">
            </div>
            <div>
                <label class="field-label">Graphic / Illustration description <span>*</span></label>
                <textarea name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. an astronaut floating among stars" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
            </div>
            <div>
                <label class="field-label">Background color</label>
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

            <!-- Image model selection -->
            <div>
                <label class="field-label">Image model</label>
                <select name="image_model" class="input-select">
                    <option value="openai" <?php echo ((isset($_POST['image_model']) ? $_POST['image_model'] : 'openai') === 'openai') ? 'selected' : ''; ?>>OpenAI (GPT)</option>
                    <option value="gemini" <?php echo ((isset($_POST['image_model']) ? $_POST['image_model'] : 'openai') === 'gemini') ? 'selected' : ''; ?>>Gemini 2.5</option>
                </select>
            </div>

            <!-- Reference image selection -->
            <div>
                <label class="field-label">Reference image (optional)</label>
                <select name="existing_ref" class="input-select">
                    <option value="">— Select existing image —</option>
                    <?php foreach ($reference_options as $ref): ?>
                    <option value="<?php echo htmlspecialchars($ref['id']); ?>" <?php echo (isset($_POST['existing_ref']) && $_POST['existing_ref'] === $ref['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ref['title']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top:6px;">
                    <input type="file" name="ref_image" accept="image/*" class="input-select" style="padding:8px;">
                    <small style="color:#6b7280;">Upload new reference or choose an existing one.</small>
                </div>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn-primary" id="submit-btn">
                    <span class="icon">⚡</span>
                    <span>Generate T‑shirt Design</span>
                </button>
                <span class="hint">AJAX generation with preview + history gallery.</span>
            </div>
            <div>
                <label class="field-label" style="display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" name="transparent_bg" id="transparent-bg" style="width:16px;height:16px;" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>>
                    Transparent background
                </label>
            </div>
            <div id="error-banner" class="error-banner" style="display:none;"></div>
        </form>
    </section>
    <!-- Right – Preview + Gallery -->
    <section class="preview-shell">
        <div class="preview-card">
            <div class="preview-meta">
                <div>
                    <span style="font-size:0.75rem;color:#9ca3af;">Latest design</span><br>
                    <strong id="latest-title">
                        <?php echo $preview ? htmlspecialchars($preview['display_text']) : 'No designs yet'; ?>
                    </strong>
                </div>
                <div style="font-size:0.75rem;color:#6b7280;">
                    Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>
                    Size: <?php echo htmlspecialchars($sizeDisplay); ?>
                </div>
            </div>
            <div class="preview-frame" id="preview-frame">
                <?php if ($preview): ?>
                    <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color']); ?></span>
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
            <?php if ($preview): ?>
                <!-- Link to editor (Gemini) for further editing -->
                <a class="editor-link" href="pocket_photo_editor_software.php" target="_blank">Edit in Gemini Editor</a>
            <?php endif; ?>
        </div>
        <div class="gallery-card">
            <div class="gallery-title-row">
                <div class="gallery-title">Recent designs</div>
                <div class="gallery-count" id="gallery-count">
                    <?php echo count($designs); ?> stored in JSON
                </div>
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
/* JS – BG preview + AJAX + Skeleton */
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
        if (!msg) {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
        } else {
            errorBox.style.display = 'block';
            errorBox.textContent = msg;
        }
    }
    function startLoading() {
        previewFrame.classList.add('loading');
        submitBtn.disabled = true;
        submitBtn.querySelector('.icon').textContent = '🎨';
        submitBtn.querySelector('span:last-child').textContent = 'Generating…';
        setError('');
    }
    function stopLoading() {
        previewFrame.classList.remove('loading');
        submitBtn.disabled = false;
        submitBtn.querySelector('.icon').textContent = '⚡';
        submitBtn.querySelector('span:last-child').textContent = 'Generate T‑shirt Design';
    }
    function updatePreview(design) {
        if (!design) return;
        previewTitle.textContent = design.display_text || 'Latest design';
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
        metaText.innerHTML = '<span class="key">Text:</span> ' + escapeHtml(design.display_text || '');
        metaGraphic.innerHTML = '<span class="key">Graphic:</span> ' + escapeHtml(design.graphic || '');
        metaCreated.innerHTML = '<span class="key">Created:</span> ' + (design.created_at || '');
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
        const current = parseInt(galleryCount.textContent, 10) || 0;
        galleryCount.textContent = (current + 1) + ' stored in JSON';
    }
    if (form) {
        form.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('design-upload');
            const hasFile   = fileInput && fileInput.files && fileInput.files.length > 0;
            // When a file is selected, allow normal form submission (no AJAX)
            if (hasFile) {
                // Do not prevent default; return to let the browser submit
                return;
            }
            // Otherwise, handle via AJAX generation
            e.preventDefault();
            const fd = new FormData(form);
            fd.append('action', 'generate_design');
            const txt = (fd.get('display_text') || '').toString().trim();
            const gr  = (fd.get('graphic_prompt') || '').toString().trim();
            // Require both fields only when not uploading
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
                } else {
                    setError('');
                    updatePreview(data.design);
                    prependToGallery(data.design);
                }
            })
            .catch(() => { setError('Network error while generating design.'); })
            .finally(() => { stopLoading(); });
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

    // Upload area drag and drop handling
    (function() {
        const uploadArea    = document.getElementById('upload-area');
        const uploadInput   = document.getElementById('design-upload');
        const uploadPlaceholder = document.getElementById('upload-placeholder');
        if (!uploadArea || !uploadInput) return;
        // Update placeholder text when file is selected
        function updatePlaceholder(files) {
            if (!files || files.length === 0) {
                uploadPlaceholder.textContent = 'Drag & drop image or click to select';
            } else {
                const names = [];
                for (let i = 0; i < files.length; i++) {
                    names.push(files[i].name);
                }
                    uploadPlaceholder.textContent = names.join(', ');
            }
        }
        // Drag events
        uploadArea.addEventListener('dragenter', (e) => {
            e.preventDefault(); uploadArea.classList.add('dragover');
        });
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault(); uploadArea.classList.add('dragover');
        });
        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault(); uploadArea.classList.remove('dragover');
        });
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault(); uploadArea.classList.remove('dragover');
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                uploadInput.files = dt.files;
                updatePlaceholder(dt.files);
            }
        });
        // Click to open file dialog
        uploadArea.addEventListener('click', () => {
            uploadInput.click();
        });
        // Update on file selection
        uploadInput.addEventListener('change', () => {
            updatePlaceholder(uploadInput.files);
        });
    })();
})();
</script>
</body>
</html>