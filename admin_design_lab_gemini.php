<?php
// Admin Gemini Design Lab page. This page embeds a T‑shirt design generator using the Gemini image API
// inside the admin dashboard. It replicates the look and feel of the existing design lab but
// generates images via the Gemini generative model instead of the OpenAI image API.

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';

// Define the OpenAI model constant based on configuration for consistent usage
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}

// Define model and API details for Gemini based on configuration
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Also get OpenAI API key for optional model selection
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';

// Paths for output and database
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/tshirt_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Load shared helpers
require_once __DIR__ . '/functions.php';
ensure_storage();

// Helper: call Gemini API to generate an image from a prompt. No base image is provided.
function call_api_gemini(string $key, string $endpoint, string $prompt): array {
    $parts = [];
    if ($prompt !== '') {
        $parts[] = ['text' => $prompt];
    }
    // Build request body
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

// Parse the Gemini API response to extract image data and mime type
function parse_image_gemini(string $json): array {
    $j = json_decode($json, true);
    $parts = $j['candidates'][0]['content']['parts'] ?? [];
    foreach ($parts as $p) {
        if (isset($p['inlineData']['data'])) {
            return [$p['inlineData']['data'], $p['inlineData']['mimeType'] ?? 'image/png', $j['usageMetadata'] ?? null];
        }
        if (isset($p['inline_data']['data'])) {
            return [$p['inline_data']['data'], $p['inline_data']['mime_type'] ?? 'image/png', $j['usage_metadata'] ?? null];
        }
    }
    return [null, null, null];
}

// Determine file extension from mime type
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

// Handle AJAX generation requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $displayText = trim($_POST['display_text'] ?? '');
    $graphicText = trim($_POST['graphic_prompt'] ?? '');
    $bgColor     = trim($_POST['bg_color'] ?? '#000000');
    // Transparent background handling: when the transparent checkbox is selected, set the background to 'transparent'
    // for OpenAI calls. Otherwise, leave background null so the API uses its default opaque background.
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    // Validate inputs
    if ($displayText === '' || $graphicText === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the T-shirt text and the graphic description.']);
        exit;
    }
    if (!$GEMINI_API_KEY || $GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured. Please set it via the Config page.']);
        exit;
    }
    // Build prompt similar to the OpenAI version. Gemini takes textual instructions.
    $prompt = sprintf(
        'T-shirt graphic design, portrait 2:3 aspect ratio. Flat, print-ready art with a solid %s background. ' .
        'Bold, readable typography that says: "%s". Integrated with an illustration of: %s. ' .
        'Encapsulated composition, no mockup, no human model, no extra text, no wrinkles, no background scene. ' .
        'High contrast, vector style, suitable for direct printing on a shirt.',
        $bgColor,
        $displayText,
        $graphicText
    );
    // Determine which model to use (default to gemini)
    $chosenModel = $_POST['image_model'] ?? 'gemini';
    $imgData  = null;
    $fileMime = 'image/png';
    if ($chosenModel === 'openai') {
        // Use OpenAI
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
            exit;
        }
        // When using OpenAI, pass the configured model name (OPENAI_MODEL constant)
        $openResult = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, '1024x1536', OPENAI_MODEL);
        if (!$openResult[0]) {
            echo json_encode(['success' => false, 'error' => $openResult[1] ?: 'Error generating image with OpenAI.']);
            exit;
        }
        $fileMime = $openResult[1];
        $imgData  = base64_decode($openResult[2]);
    } else {
        // Use Gemini
        if (!$GEMINI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured. Please set it via the Config page.']);
            exit;
        }
        $gemResult = send_gemini_image_request($prompt, null, $GEMINI_API_KEY, $ENDPOINT);
        if (!$gemResult[0]) {
            echo json_encode(['success' => false, 'error' => $gemResult[1] ?: 'Error generating image with Gemini.']);
            exit;
        }
        $fileMime = $gemResult[1];
        $imgData  = base64_decode($gemResult[2]);
    }
    // Determine extension based on MIME
    $ext = match ($fileMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    // Create filename and save
    try {
        $filename = safe_name_gemini('ts', $ext);
    } catch (Exception $e) {
        $filename = safe_name_gemini('ts', $ext);
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
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

// Load existing designs and pre-fill default values from query string
$designs = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs = array_reverse($designs);
$preview = $designs[0] ?? null;
$default_display_text = isset($_POST['display_text']) ? trim($_POST['display_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_graphic      = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg           = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#000000');
$pageTitle = 'Admin — Gemini Design Lab';
$activeSection = 'create';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Gemini Design Lab</h1>
            <div class="page-shell">
                <header class="page-header">
                    <div>
                        <div class="page-title">T-Shirt Design Lab (Gemini)</div>
                        <div class="page-subtitle">Generate print-ready shirt graphics with the Gemini model</div>
                    </div>
                    <div class="pill">GEMINI_MODEL: <?php echo htmlspecialchars(GEMINI_MODEL); ?></div>
                </header>
                <!-- Left: Design form -->
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
                        <!-- Transparent background option -->
                        <div>
                            <label class="field-label" for="transparent-bg">
                                <input type="checkbox" name="transparent_bg" id="transparent-bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>> Use transparent instead of solid colour
                            </label>
                        </div>
                        <div>
                            <label class="field-label">Image model</label>
                            <select name="image_model" class="input-select">
                                <option value="gemini" selected>Gemini 2.5</option>
                                <option value="openai">OpenAI (GPT)</option>
                            </select>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="submit-btn"><span class="icon">⚡</span><span>Generate T-shirt Design</span></button>
                            <span class="hint">Generation may take a few seconds. Designs are stored in a JSON DB.</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <!-- Right: Preview & Gallery -->
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
<!-- JavaScript for Gemini Design Lab -->
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
        wrapper.innerHTML = `
            <img src="${design.image_url}" alt="${(design.display_text || '').replace(/"/g, '&quot;')}">
            <div class="gallery-item-caption">
                <span class="caption-text">${design.display_text || ''}</span>
                <span class="caption-sub">${design.graphic || ''}</span>
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
<?php require_once __DIR__ . '/admin_footer.php'; ?>
