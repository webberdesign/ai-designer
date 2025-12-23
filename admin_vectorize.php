<?php
/*
 * Admin Vectorize Page
 *
 * This page allows administrators to convert an existing design into a vector-style graphic.
 * An admin can open the page from the media library by clicking the “Vectorize” button next
 * to any design. The page loads the selected design, displays its details, and offers a
 * form for specifying an optional vector style. When the user submits the form, the
 * application calls the Gemini image API to generate a simplified vector art version
 * using the original image as a base. The resulting vector image is saved in
 * generated_tshirts/ and recorded in vector_designs.json with a link back to the
 * original design ID. The UI includes a preview of the original design, a skeleton
 * loader during generation, and a preview of the vector output with a download link.
 */

// No authentication or credit checks required for vectorization. This page
// converts raster images into vector formats using an external API defined
// in the configuration. We include config and helpers for saving files.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
// Define vectorizer constants from config if not already defined. This allows
// fallback to default credentials when the config file does not specify them.
if (!defined('VECTORIZER_API_ID')) {
    define('VECTORIZER_API_ID', $config['vectorizer_api_id'] ?? '');
}
if (!defined('VECTORIZER_API_SECRET')) {
    define('VECTORIZER_API_SECRET', $config['vectorizer_api_secret'] ?? '');
}
if (!defined('VECTORIZER_ENDPOINT')) {
    define('VECTORIZER_ENDPOINT', $config['vectorizer_endpoint'] ?? '');
}
if (!defined('VECTORIZER_SAVE_DIR')) {
    define('VECTORIZER_SAVE_DIR', $config['vectorizer_save_dir'] ?? '');
}
if (!defined('VECTORIZER_SAVE_URL')) {
    define('VECTORIZER_SAVE_URL', $config['vectorizer_save_url'] ?? '');
}

// Helper function to find a design by ID across all known JSON files. Returns [record, fileKey]
function find_design_by_id(string $id): array {
    $files = [
        'tshirt'        => __DIR__ . '/tshirt_designs.json',
        'logo'          => __DIR__ . '/logo_designs.json',
        'poster'        => __DIR__ . '/poster_designs.json',
        'flyer'         => __DIR__ . '/flyer_designs.json',
        'social'        => __DIR__ . '/social_designs.json',
        'photo'         => __DIR__ . '/photo_designs.json',
        'business_card' => __DIR__ . '/business_card_designs.json',
        'certificate'   => __DIR__ . '/certificate_designs.json',
        'packaging'     => __DIR__ . '/packaging_designs.json',
        'illustration'  => __DIR__ . '/illustration_designs.json',
        'mockup'        => __DIR__ . '/mockup_designs.json',
        'vector'        => __DIR__ . '/vector_designs.json',
    ];
    foreach ($files as $key => $file) {
        if (!is_file($file)) continue;
        $records = json_decode(@file_get_contents($file), true);
        if (!is_array($records)) continue;
        foreach ($records as $rec) {
            if (($rec['id'] ?? '') === $id) {
                $rec['tool'] = $rec['tool'] ?? $key;
                return [$rec, $key];
            }
        }
    }
    return [null, null];
}

// Ensure a design ID is provided. The page is invoked via the media library
// which passes the record ID as a query parameter.
$designId = $_GET['id'] ?? '';
if ($designId === '') {
    die('Missing design ID.');
}

// Load the design record
[$design, $designTool] = find_design_by_id($designId);
if (!$design) {
    die('Design not found.');
}

// Determine the path to the original image
$origFile  = $design['file'] ?? '';
$origPath  = __DIR__ . '/generated_tshirts/' . $origFile;
if (!is_file($origPath)) {
    die('Original image not found.');
}
// Build the image URL for preview
$origUrl   = 'generated_tshirts/' . rawurlencode($origFile);

// Prepare variables for success/error states
$vectorError  = '';
$vectorSuccess= '';
$vectorImageUrl = '';

// Handle AJAX vectorization request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    // Optional style parameter (not used for external vectorizer)
    $style = trim($_POST['style'] ?? '');
    // Use external vectorizer API defined in config. We require API credentials
    // (ID and secret) and an endpoint. If these are not set, return error.
    // Load API credentials from the config, falling back to defined constants.
    $apiId     = $config['vectorizer_api_id']     ?? VECTORIZER_API_ID;
    $apiSecret = $config['vectorizer_api_secret'] ?? VECTORIZER_API_SECRET;
    $endpoint  = $config['vectorizer_endpoint']   ?? VECTORIZER_ENDPOINT;
    if ($apiId === '' || $apiSecret === '' || $endpoint === '') {
        echo json_encode(['error' => 'Vectorizer API credentials are not configured. Please set them in the Config page.']);
        exit;
    }
    // Destination directory and URL for vector files. Use config values if set,
    // otherwise fall back to defined constants and defaults.
    $saveDir = ($config['vectorizer_save_dir'] ?? '') ?: (VECTORIZER_SAVE_DIR ?: (__DIR__ . '/generated_vectors'));
    $saveUrl = ($config['vectorizer_save_url'] ?? '') ?: (VECTORIZER_SAVE_URL ?: (rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/generated_vectors'));
    if (!is_dir($saveDir)) {
        @mkdir($saveDir, 0755, true);
    }
    // Send the original image to the vectorizer service via HTTP POST with Basic auth
    $ch = curl_init($endpoint);
    $filename = basename($origPath);
    $cfile = new CURLFile($origPath);
    $postFields = [
        'image' => $cfile
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $apiId . ':' . $apiSecret,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => false,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($response === false || $status !== 200) {
        $msg = $err ?: ('Vectorizer API request failed with status ' . $status);
        echo json_encode(['error' => $msg]);
        exit;
    }
    // Determine file extension. Vectorizer.ai typically returns SVG content but
    // may send application/octet-stream with .svg data. Attempt to detect
    // vector format by searching for '<svg' in the response.
    // Determine extension: if the response contains an <svg> tag, assume SVG; else fall back to PDF
    $ext  = (strpos($response, '<svg') !== false) ? 'svg' : 'pdf';
    try {
        $newName = 'vector_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $designId) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $newName = 'vector_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $designId) . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $vecPath = rtrim($saveDir, '/') . '/' . $newName;
    if (@file_put_contents($vecPath, $response) === false) {
        echo json_encode(['error' => 'Failed to save vector file.']);
        exit;
    }
    // Record the vector design in vector_designs.json
    $vecDbPath = __DIR__ . '/vector_designs.json';
    $vecDb     = json_decode(@file_get_contents($vecDbPath), true) ?: [];
    $vecRecord = [
        'id'          => uniqid('vector_', true),
        'original_id' => $design['id'],
        'style'       => $style,
        'file'        => $newName,
        'created_at'  => date('c'),
        'tool'        => 'vector',
        'source'      => 'vectorizer'
    ];
    $vecDb[] = $vecRecord;
    @file_put_contents($vecDbPath, json_encode($vecDb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $vecUrl = rtrim($saveUrl, '/') . '/' . rawurlencode($newName);
    echo json_encode(['success' => 'Vectorization complete!', 'url' => $vecUrl]);
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Vectorize Design</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #111827;
            --secondary: #1f2937;
            --accent: #2563eb;
            --accent-light: #3b82f6;
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
        .admin-container { display: flex; min-height: 100vh; flex-direction: column; }
        header.admin-header {
            background: var(--primary);
            color: var(--text-light);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .brand { font-size: 1.4rem; font-weight: 700; letter-spacing: 0.03em; }
        header .top-nav a { color: var(--text-light); text-decoration: none; margin-left: 20px; font-size: 0.9rem; }
        .admin-main { flex: 1; display: flex; min-height: 0; }
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
        h1 { font-size: 1.6rem; margin-top: 0; margin-bottom: 20px; }
        .vector-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: flex-start;
        }
        .vector-preview, .vector-form {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .vector-preview img {
            width: 100%;
            height: auto;
            border-radius: 4px;
        }
        .field-label {
            font-size: 0.85rem;
            color: #4b5563;
            margin-bottom: 6px;
            display: block;
        }
        .input-text {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 12px;
        }
        .input-text:focus {
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
        .btn-primary:disabled { opacity: 0.6; cursor: wait; }
        .error-banner {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #b91c1c;
            background: #fee2e2;
            border-radius: 6px;
            padding: 8px 10px;
            border: 1px solid #fca5a5;
        }
        .success-banner {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #065f46;
            background: #d1fae5;
            border-radius: 6px;
            padding: 8px 10px;
            border: 1px solid #6ee7b7;
        }
        /* Skeleton loader for vectorization */
        .preview-frame {
            position: relative;
            border-radius: 6px;
            overflow: hidden;
        }
        .preview-frame.loading::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(30,64,175,0.1) 0%, rgba(148,163,184,0.35) 40%, rgba(30,64,175,0.1) 80%);
            animation: shimmer 1.4s infinite;
        }
        .preview-frame.loading::after {
            content: "Generating vector…";
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.8rem;
            color: #6b7280;
            text-shadow: 0 2px 4px rgba(0,0,0,0.7);
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
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
        <?php $currentScript = basename($_SERVER['SCRIPT_NAME']); ?>
        <aside class="admin-sidebar">
            <a href="admin.php?section=designs" class="">Designs</a>
            <a href="admin_design_lab.php" class="">Design Lab</a>
            <a href="admin_idea_generator.php" class="">Idea Generator</a>
            <a href="admin_create.php" class="">Create</a>
            <a href="admin_media_library.php" class="active">Library</a>
            <a href="admin.php?section=products" class="">Products</a>
            <a href="admin.php?section=orders" class="">Orders</a>
            <a href="admin_config.php" class="">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Vectorize Design</h1>
            <p>Original design ID: <?php echo htmlspecialchars($designId); ?></p>
            <div class="vector-container">
                <!-- Original preview -->
                <div class="vector-preview">
                    <h2>Original Design</h2>
                    <div class="preview-frame">
                        <img src="<?php echo htmlspecialchars($origUrl); ?>" alt="Original design">
                    </div>
                    <table style="width:100%;margin-top:12px;font-size:0.85rem;border-collapse:collapse;">
                        <?php foreach ($design as $k => $v): if (in_array($k, ['id','file'])) continue; ?>
                        <tr>
                            <th style="text-align:left;color:#6b7280;padding:4px 6px;width:35%;"><?php echo ucwords(str_replace('_',' ', $k)); ?></th>
                            <td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars(is_array($v) ? json_encode($v) : (string)$v); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <!-- Vectorization form and result -->
                <div class="vector-form">
                    <h2>Generate Vector</h2>
                    <form id="vector-form" method="post">
                        <label class="field-label">Style (optional)</label>
                        <input type="text" name="style" class="input-text" placeholder="e.g., flat, outline, minimal">
                        <button type="submit" class="btn-primary" id="vec-btn"><span class="icon">➜</span> <span>Vectorize</span></button>
                        <input type="hidden" name="action" value="vectorize">
                    </form>
                    <div id="vector-error" class="error-banner" style="display:none;"></div>
                    <div id="vector-success" class="success-banner" style="display:none;"></div>
                    <div id="vector-preview" style="margin-top:16px;"></div>
                </div>
            </div>
        </section>
    </div>
</div>
<script>
// AJAX vectorization handler
document.addEventListener('DOMContentLoaded', function() {
    const form      = document.getElementById('vector-form');
    const btn       = document.getElementById('vec-btn');
    const errorBox  = document.getElementById('vector-error');
    const successBox= document.getElementById('vector-success');
    const previewBox= document.getElementById('vector-preview');
    const previewFrame = document.querySelector('.vector-preview .preview-frame');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!btn) return;
            btn.disabled = true;
            // Show skeleton loader on original preview to indicate generation
            if (previewFrame) previewFrame.classList.add('loading');
            // Clear messages
            if (errorBox) { errorBox.style.display = 'none'; errorBox.textContent = ''; }
            if (successBox) { successBox.style.display = 'none'; successBox.textContent = ''; }
            previewBox.innerHTML = '';
            // Prepare form data
            const fd = new FormData(form);
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(resp => resp.json()).then(data => {
                if (previewFrame) previewFrame.classList.remove('loading');
                btn.disabled = false;
                if (data.error) {
                    if (errorBox) {
                        errorBox.textContent = data.error;
                        errorBox.style.display = 'block';
                    }
                } else {
                    if (successBox) {
                        successBox.textContent = data.success || 'Vectorization complete!';
                        successBox.style.display = 'block';
                    }
                    if (data.url) {
                        const div = document.createElement('div');
                        div.innerHTML = '<h3>Vectorized Output</h3><img src="' + data.url + '" alt="Vector result" style="width:100%;height:auto;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:12px;"><a href="' + data.url + '" download style="color:var(--accent);text-decoration:none;font-weight:600;">Download Vector</a>';
                        previewBox.appendChild(div);
                    }
                }
            }).catch(() => {
                if (previewFrame) previewFrame.classList.remove('loading');
                btn.disabled = false;
                if (errorBox) {
                    errorBox.textContent = 'An error occurred during vectorization.';
                    errorBox.style.display = 'block';
                }
            });
        });
    }
});
</script>
</body>
</html>