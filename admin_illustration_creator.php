<?php
/*
 * Admin Illustration & Character Creator
 *
 * This page allows administrators to generate custom illustrations or character art
 * using either OpenAI's image model or Google's Gemini image model. Users can
 * describe the subject and optionally specify a style or theme. A background
 * colour may be selected and a transparency option is available when using
 * OpenAI. An optional reference image can be uploaded to influence the
 * generation when using Gemini. Generated illustrations are stored in their
 * own JSON database and displayed in a gallery.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Define model constants if not already defined
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// Assign API keys
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
// Build Gemini endpoint
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage paths
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/illustration_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Create other storage directories if needed
ensure_storage();

// Handle AJAX request to generate an illustration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $subject = trim($_POST['subject_text'] ?? '');
    $style   = trim($_POST['style_text'] ?? '');
    $bgColor = trim($_POST['bg_color'] ?? '#ffffff');
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    // Validate
    if ($subject === '') {
        echo json_encode(['success' => false, 'error' => 'Please describe the subject for the illustration.']);
        exit;
    }
    // Determine selected model
    $chosenModel = $_POST['image_model'] ?? 'gemini';
    // Determine aspect ratio for illustration (square, landscape or portrait)
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
    // Build prompt including style and ratio
    $styleDesc = $style !== '' ? " Style: $style." : '';
    $prompt = sprintf(
        'Illustration or character design, %s aspect ratio. Use a solid %s background. Subject: "%s".%s Balanced composition, high quality, professional illustration.',
        $ratioDesc,
        $bgColor,
        $subject,
        $styleDesc
    );
    // Handle reference image for Gemini
    $inlineData = null;
    if (isset($_FILES['ref_image']) && $_FILES['ref_image']['error'] === UPLOAD_ERR_OK && $_FILES['ref_image']['size'] > 0) {
        $tmp  = $_FILES['ref_image']['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'image/png';
        $bytes = file_get_contents($tmp);
        if ($bytes !== false) {
            $inlineData = [ 'mime_type' => $mime, 'data' => base64_encode($bytes) ];
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
    // Determine file extension
    $ext = match ($fileMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    try {
        $filename = 'illustration_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'illustration_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    // Save record
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'         => uniqid('illustration_', true),
        'subject'    => $subject,
        'style'      => $style,
        'bg_color'   => $bgColor,
        'file'       => $filename,
        'created_at' => date('c'),
        'published'  => false,
        'source'     => $chosenModel,
        'tool'       => 'illustration'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'         => $record['id'],
            'subject'    => $record['subject'],
            'style'      => $record['style'],
            'bg_color'   => $record['bg_color'],
            'file'       => $record['file'],
            'created_at' => $record['created_at'],
            'image_url'  => 'generated_tshirts/' . rawurlencode($record['file'])
        ],
    ]);
    exit;
}

// Load existing designs
$designsAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs    = array_reverse($designsAll);
$preview    = $designs[0] ?? null;

// Default values for sticky form
$default_subject = isset($_POST['subject_text']) ? trim($_POST['subject_text']) : (isset($_GET['subject']) ? trim($_GET['subject']) : '');
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

$pageTitle = 'Admin — Illustrations &amp; Characters Creator';
$activeSection = 'create';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Illustrations &amp; Characters</h1>
            <div class="page-shell">
                <!-- Form card -->
                <section class="card">
                    <div class="card-title">Design Prompt</div>
                    <div class="card-sub">Describe the subject and optionally a style. The model will generate a custom illustration.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="generate_design">
                        <div>
                            <label class="field-label">Subject <span class="required">*</span></label>
                            <input type="text" name="subject_text" class="input-text" placeholder="e.g. Girl with dragon wings" required value="<?php echo htmlspecialchars($default_subject); ?>">
                        </div>
                        <div>
                            <label class="field-label">Style / Theme</label>
                            <input type="text" name="style_text" class="input-text" placeholder="e.g. watercolor, anime, noir" value="<?php echo htmlspecialchars($default_style); ?>">
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
                                <span class="icon">🎨</span>
                                <span>Generate Illustration</span>
                            </button>
                            <span class="help">AJAX generation with preview + history gallery.</span>
                        </div>
                        <div class="error-banner" id="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <!-- Preview + Gallery -->
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest illustration</span><br>
                                <strong id="latest-title">
                                    <?php echo $preview ? htmlspecialchars($preview['subject']) : 'No designs yet'; ?>
                                </strong>
                            </div>
                            <div style="font-size:0.75rem;color:#6b7280;">Aspect ratio: <?php echo htmlspecialchars($ratioDisplay); ?><br>Size: <?php echo htmlspecialchars($sizeDisplay); ?></div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge">PNG • <?php echo htmlspecialchars($preview['bg_color']); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest illustration design">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">Your first design will appear here after you hit “Generate”.</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($preview): ?>
                            <div class="meta-line" id="meta-subject"><span class="key">Subject:</span> <?php echo htmlspecialchars($preview['subject']); ?></div>
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
                                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['file']); ?>" alt="<?php echo htmlspecialchars($item['subject']); ?>">
                                        <div class="gallery-item-caption">
                                            <span class="caption-text"><?php echo htmlspecialchars($item['subject']); ?></span>
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
            </div><script>
// JS helpers for illustration creator
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
    const metaSubject  = document.getElementById('meta-subject');
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
    function startLoading(){ if(previewFrame) previewFrame.classList.add('loading'); if(submitBtn){ submitBtn.disabled = true; submitBtn.querySelector('.icon').textContent = '🎨'; submitBtn.querySelector('span:last-child').textContent = 'Generating...'; } setError(''); }
    function stopLoading(){ if(previewFrame) previewFrame.classList.remove('loading'); if(submitBtn){ submitBtn.disabled = false; submitBtn.querySelector('.icon').textContent = '🎨'; submitBtn.querySelector('span:last-child').textContent = 'Generate Illustration'; } }
    function updatePreview(design){ if(!design) return; if(previewTitle){ previewTitle.textContent = design.subject || 'Latest illustration'; } if(previewImg){ previewImg.src = design.image_url; previewImg.style.display='block'; } if(previewPlaceholder){ previewPlaceholder.style.display='none'; } if(previewBadge){ previewBadge.textContent = 'PNG • ' + (design.bg_color || '#ffffff'); }
        if(metaSubject) metaSubject.innerHTML = '<span class="key">Subject:</span> ' + (design.subject || '');
        if(metaStyle){ if(design.style){ metaStyle.style.display='block'; metaStyle.innerHTML = '<span class="key">Style:</span> ' + design.style; } else { metaStyle.style.display='none'; } }
        if(metaCreated) metaCreated.innerHTML = '<span class="key">Created:</span> ' + design.created_at;
    }
    function prependToGallery(design){ if(!galleryGrid || !design) return; const wrapper = document.createElement('div'); wrapper.className = 'gallery-item'; wrapper.innerHTML = '<img src="'+design.image_url+'" alt="'+(design.subject||'')+'"><div class="gallery-item-caption"><span class="caption-text">'+(design.subject||'')+'</span>'+(design.style?'<span class="caption-sub">'+design.style+'</span>':'')+'</div>';
        if(galleryGrid.firstChild){ galleryGrid.insertBefore(wrapper, galleryGrid.firstChild); } else { galleryGrid.appendChild(wrapper); }
        if(galleryCount){ const current = parseInt(galleryCount.textContent) || 0; galleryCount.textContent = (current + 1) + ' saved'; }
    }
    // Drag and drop upload handling
    if(uploadWrapper && fileInput){
        ['dragenter','dragover'].forEach(ev => uploadWrapper.addEventListener(ev, e => { e.preventDefault(); uploadWrapper.classList.add('dragover'); }));
        ['dragleave','drop'].forEach(ev => uploadWrapper.addEventListener(ev, e => { e.preventDefault(); uploadWrapper.classList.remove('dragover'); }));
        uploadWrapper.addEventListener('drop', e => { if(e.dataTransfer.files && e.dataTransfer.files[0]){ fileInput.files = e.dataTransfer.files; uploadMsg.textContent = 'Selected '+e.dataTransfer.files[0].name; } });
        uploadWrapper.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', e => { if(e.target.files[0]){ uploadMsg.textContent = 'Selected '+e.target.files[0].name; } });
    }
    // Form submission via AJAX
    if(form){ form.addEventListener('submit', function(e){ e.preventDefault(); const formData = new FormData(form); formData.append('action','generate_design'); const subject = formData.get('subject_text')?.toString().trim(); if(!subject){ setError('Please describe the subject.'); return; } startLoading(); fetch(window.location.href, { method:'POST', body: formData }).then(res => res.json()).then(data => { if(!data.success){ setError(data.error || 'Error generating design.'); return; } setError(''); updatePreview(data.design); prependToGallery(data.design); }).catch(() => { setError('Network error while generating design.'); }).finally(() => { stopLoading(); }); }); }
})();
</script>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
