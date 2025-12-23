<?php
/*
 * Admin Flyer Creator
 *
 * This page allows administrators to generate custom flyer designs using
 * Google's Gemini image model. Flyers are saved in the primary JSON
 * database and tagged with the 'flyer' tool. The UI accepts an event
 * title, optional details (date/location), a description of imagery, a
 * background colour and an optional reference image. The resulting
 * design is presented in a live preview and stored with other flyers.
 */

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';
// Define model constants from configuration if not already defined
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// Assign API keys from configuration
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
// Build Gemini endpoint
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage paths
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/flyer_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Load shared helpers and prepare storage
require_once __DIR__ . '/functions.php';
ensure_storage();

// Handle AJAX generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $title    = trim($_POST['title_text'] ?? '');
    $details  = trim($_POST['details_text'] ?? '');
    $graphic  = trim($_POST['graphic_prompt'] ?? '');
    $bgColor  = trim($_POST['bg_color'] ?? '#ffffff');
    if ($title === '' || $graphic === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the flyer title and the imagery description.']);
        exit;
    }
    // Validate that the Gemini API key is available; instruct the user to configure it via the Config page
    if (!$GEMINI_API_KEY) {
        echo json_encode([
            'success' => false,
            'error'   => 'Gemini API key is not configured. Please update it on the Config page.',
        ]);
        exit;
    }
    // Determine aspect ratio for flyer (landscape, portrait or square)
    $ratio = $_POST['aspect_ratio'] ?? 'portrait';
    switch ($ratio) {
        case 'landscape':
            $ratioDesc = 'landscape 3:2';
            $size = '1536x1024';
            break;
        case 'square':
            $ratioDesc = 'square 1:1';
            $size = '1024x1024';
            break;
        default:
            $ratioDesc = 'portrait 2:3';
            $size = '1024x1536';
            break;
    }
    // Build prompt for flyer design using the selected ratio
    $prompt = sprintf(
        'Flyer design, %s aspect ratio. Use a solid %s background. Event name: "%s". Details: "%s". Incorporate imagery: %s. ' .
        'Bold, legible typography, modern layout, balanced composition.',
        $ratioDesc,
        $bgColor,
        $title,
        $details,
        $graphic
    );
    // Reference image handling
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
    $parts = [];
    if ($prompt !== '') {
        $parts[] = ['text' => $prompt];
    }
    if ($inlineData) {
        $parts[] = ['inline_data' => $inlineData];
    }
    $body = ['contents' => [ ['parts' => $parts] ]];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $ENDPOINT . '?key=' . urlencode($GEMINI_API_KEY),
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
    if ($res === false) {
        echo json_encode(['success' => false, 'error' => 'cURL error: ' . $err]);
        exit;
    }
    if ($code !== 200) {
        echo json_encode(['success' => false, 'error' => 'HTTP ' . $code . "\n" . $res]);
        exit;
    }
    $json = json_decode($res, true);
    $partsOut = $json['candidates'][0]['content']['parts'] ?? [];
    $b64  = null;
    $mime = null;
    foreach ($partsOut as $p) {
        if (isset($p['inlineData']['data'])) {
            $b64  = $p['inlineData']['data'];
            $mime = $p['inlineData']['mimeType'] ?? 'image/png';
            break;
        }
        if (isset($p['inline_data']['data'])) {
            $b64  = $p['inline_data']['data'];
            $mime = $p['inline_data']['mime_type'] ?? 'image/png';
            break;
        }
    }
    if (!$b64) {
        echo json_encode(['success' => false, 'error' => 'No image data returned from Gemini.', 'raw' => $res]);
        exit;
    }
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    try {
        $filename = 'flyer_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'flyer_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, base64_decode($b64)) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('flyer_', true),
        'display_text' => $title,
        'details'      => $details,
        'graphic'      => $graphic,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => 'gemini',
        'tool'         => 'flyer'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'           => $record['id'],
            'display_text' => $record['display_text'],
            'details'      => $record['details'],
            'graphic'      => $record['graphic'],
            'bg_color'     => $record['bg_color'],
            'file'         => $record['file'],
            'created_at'   => $record['created_at'],
            'image_url'    => 'generated_tshirts/' . rawurlencode($record['file']),
        ],
    ]);
    exit;
}

// Load flyers from DB
$designsAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designsFiltered = array_filter($designsAll, function ($item) {
    return ($item['tool'] ?? '') === 'flyer';
});
$designs = array_reverse($designsFiltered);
$preview = $designs[0] ?? null;
// Default form values
$default_title   = isset($_POST['title_text']) ? trim($_POST['title_text']) : (isset($_GET['text']) ? trim($_GET['text']) : '');
$default_details = isset($_POST['details_text']) ? trim($_POST['details_text']) : (isset($_GET['details']) ? trim($_GET['details']) : '');
$default_graphic = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg      = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#ffffff');

// Determine default selected image model (OpenAI or Gemini)
$default_model = isset($_POST['image_model']) ? $_POST['image_model'] : 'gemini';

// Determine default aspect ratio for sticky form and preview metadata
$default_ratio = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : 'portrait';
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
$pageTitle = 'Admin — Flyer Creator';
$activeSection = 'create';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Flyer Creator</h1>
            <div class="page-shell">
                <section class="card">
                    <div class="card-title">Flyer Prompt</div>
                    <div class="card-sub">Enter the event name, optional details (date/location) and imagery description. You can also upload a reference image.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="generate_design">
                        <div>
                            <label class="field-label" for="title-text">Event Name <span class="required">*</span></label>
                            <input type="text" id="title-text" name="title_text" class="input-text" placeholder="e.g. Music Night" required value="<?php echo htmlspecialchars($default_title); ?>">
                        </div>
                        <div>
                            <label class="field-label" for="details-text">Details (date &amp; location)</label>
                            <input type="text" id="details-text" name="details_text" class="input-text" placeholder="e.g. 22 Apr • Central Park" value="<?php echo htmlspecialchars($default_details); ?>">
                        </div>
                        <div>
                            <label class="field-label" for="graphic-prompt">Imagery Description <span class="required">*</span></label>
                            <textarea id="graphic-prompt" name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. saxophone, city skyline" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
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
                                <option value="portrait" <?php echo ($default_ratio === 'portrait') ? 'selected' : ''; ?>>Portrait (2:3)</option>
                                <option value="landscape" <?php echo ($default_ratio === 'landscape') ? 'selected' : ''; ?>>Landscape (3:2)</option>
                                <option value="square" <?php echo ($default_ratio === 'square') ? 'selected' : ''; ?>>Square (1:1)</option>
                            </select>
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
                                <span>Generate Flyer</span>
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
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest flyer</span><br>
                                <strong id="latest-title"><?php echo $preview ? htmlspecialchars($preview['display_text']) : 'No flyers yet'; ?></strong>
                            </div>
                            <div style="font-size:0.75rem;color:#6b7280;">Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>Size: <?php echo htmlspecialchars($sizeDisplay); ?></div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color'] ?: '#ffffff'); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest flyer">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first flyer will appear here after you hit “Generate”.</span>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-title">
                            <?php if ($preview): ?><span class="key">Event:</span> <?php echo htmlspecialchars($preview['display_text']); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-details">
                            <?php if ($preview): ?><span class="key">Details:</span> <?php echo htmlspecialchars($preview['details'] ?? ''); ?><?php endif; ?>
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
                            <div class="gallery-title">Recent flyers</div>
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
                                <div style="font-size:0.8rem;color:#6b7280;">Flyers you generate will show up here as a mini gallery.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div><script>
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
    const metaDetails = document.getElementById('meta-details');
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
            submitBtn.querySelector('span:last-child').textContent = 'Generate Flyer';
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
        if (previewTitle) previewTitle.textContent = design.display_text || 'Latest flyer';
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        if (previewBadge) previewBadge.textContent = 'PNG • ' + (design.bg_color || '#ffffff');
        if (metaTitle) metaTitle.innerHTML    = '<span class="key">Event:</span> ' + escapeHtml(design.display_text || '');
        if (metaDetails) metaDetails.innerHTML  = '<span class="key">Details:</span> ' + escapeHtml(design.details || '');
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
                setError('Please enter both the flyer title and the imagery description.');
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
<?php require_once __DIR__ . '/admin_footer.php'; ?>
