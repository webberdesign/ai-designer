<?php
/*
 * Admin Business Card Creator
 *
 * This page allows administrators to generate custom business card designs using either
 * OpenAI's image model or Google's Gemini image model. Users can specify the name,
 * title/position, contact information, and an optional graphic description. A background
 * colour may be selected and a transparent background option is available. An optional
 * reference image can be uploaded to influence the design when using Gemini. Generated
 * business cards are stored in their own JSON database and displayed in a gallery.
 */

// Load configuration and shared helpers
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Define model constants if not already defined
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}

// Assign API keys from configuration
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';

// Construct the Gemini endpoint
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage paths
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/business_card_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Ensure any other necessary storage exists
ensure_storage();

// Handle AJAX request to generate a business card
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $name    = trim($_POST['name_text'] ?? '');
    $title   = trim($_POST['title_text'] ?? '');
    $contact = trim($_POST['contact_text'] ?? '');
    $graphic = trim($_POST['graphic_prompt'] ?? '');
    $bgColor = trim($_POST['bg_color'] ?? '#ffffff');
    // Transparent background option; when checked we pass 'transparent' to API
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    // Validate required fields: at least name and graphic description
    if ($name === '' || $graphic === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter both the name and imagery description.']);
        exit;
    }
    // Determine selected model; default to Gemini
    $chosenModel = $_POST['image_model'] ?? 'gemini';
    // Build prompt for business card design
    // Use a landscape ratio (3:2) to approximate business card dimensions. We place
    // the name and title prominently and include contact details if provided.
    $details  = '';
    if ($title !== '') {
        $details .= " Title: $title.";
    }
    if ($contact !== '') {
        $details .= " Contact: $contact.";
    }
    // Determine aspect ratio based on selection (landscape, portrait or square)
    $ratio = $_POST['aspect_ratio'] ?? 'landscape';
    switch ($ratio) {
        case 'portrait':
            $ratioDesc = 'portrait 2:3';
            $size = '1024x1536';
            break;
        case 'square':
            $ratioDesc = 'square 1:1';
            $size = '1024x1024';
            break;
        default:
            // Default to landscape 3:2 ratio
            $ratioDesc = 'landscape 3:2';
            $size = '1536x1024';
            break;
    }
    // Build prompt using the selected ratio description
    $prompt = sprintf(
        'Business card design, %s aspect ratio. Use a solid %s background. Name: "%s".%s Incorporate imagery: %s. ' .
        'Professional, modern typography, balanced layout, clean aesthetic.',
        $ratioDesc,
        $bgColor,
        $name,
        $details,
        $graphic
    );
    // Handle reference image for Gemini
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
    $fileMime = 'image/png';
    $imgData  = null;
    // Choose API based on the selected model
    if ($chosenModel === 'openai') {
        // Validate OpenAI API key
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
            exit;
        }
        // Use the appropriate size for the chosen aspect ratio
        $openRes = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, $size, OPENAI_MODEL);
        if (!$openRes[0]) {
            echo json_encode(['success' => false, 'error' => $openRes[1] ?: 'Error generating image with OpenAI.']);
            exit;
        }
        $fileMime = $openRes[1];
        $imgData  = base64_decode($openRes[2]);
    } else {
        // Validate Gemini API key
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
    // Determine file extension from MIME
    $ext = match ($fileMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    // Generate unique filename with prefix
    try {
        $filename = 'bcard_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'bcard_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    // Append record to the business card DB
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'           => uniqid('bcard_', true),
        'name'         => $name,
        'title'        => $title,
        'contact'      => $contact,
        'graphic'      => $graphic,
        'bg_color'     => $bgColor,
        'file'         => $filename,
        'created_at'   => date('c'),
        'published'    => false,
        'source'       => $chosenModel,
        'tool'         => 'business_card'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'        => $record['id'],
            'name'      => $record['name'],
            'title'     => $record['title'],
            'contact'   => $record['contact'],
            'graphic'   => $record['graphic'],
            'bg_color'  => $record['bg_color'],
            'file'      => $record['file'],
            'created_at'=> $record['created_at'],
            'image_url' => 'generated_tshirts/' . rawurlencode($record['file'])
        ],
    ]);
    exit;
}

// Load existing business card designs
$designsAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs    = array_reverse($designsAll);
$preview    = $designs[0] ?? null;

// Default values from POST or GET for sticky forms
$default_name    = isset($_POST['name_text']) ? trim($_POST['name_text']) : (isset($_GET['name']) ? trim($_GET['name']) : '');
$default_title   = isset($_POST['title_text']) ? trim($_POST['title_text']) : (isset($_GET['title']) ? trim($_GET['title']) : '');
$default_contact = isset($_POST['contact_text']) ? trim($_POST['contact_text']) : (isset($_GET['contact']) ? trim($_GET['contact']) : '');
$default_graphic = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['graphic']) ? trim($_GET['graphic']) : '');
$default_bg      = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#ffffff');
$default_model   = isset($_POST['image_model']) ? $_POST['image_model'] : 'gemini';

// Determine default aspect ratio for sticky form and preview metadata
$default_ratio = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : 'landscape';
switch ($default_ratio) {
    case 'portrait':
        $ratioDisplay = '2:3';
        $sizeDisplay  = '1024×1536';
        break;
    case 'square':
        $ratioDisplay = '1:1';
        $sizeDisplay  = '1024×1024';
        break;
    default:
        $ratioDisplay = '3:2';
        $sizeDisplay  = '1536×1024';
        break;
}

$pageTitle = 'Admin — Business Card Creator';
$activeSection = 'create';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Business Card Creator</h1>
            <div class="page-shell">
                <!-- Left: Form -->
                <section class="card">
                    <div class="card-title">Business Card Details</div>
                    <div class="card-sub">Enter the name, title and contact info. Describe the imagery and select a background colour. You may also upload a reference image.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="generate_design">
                        <div>
                            <label class="field-label">Name <span class="required">*</span></label>
                            <input type="text" name="name_text" class="input-text" placeholder="e.g. Jane Doe" required value="<?php echo htmlspecialchars($default_name); ?>">
                        </div>
                        <div>
                            <label class="field-label">Title / Position</label>
                            <input type="text" name="title_text" class="input-text" placeholder="e.g. CEO" value="<?php echo htmlspecialchars($default_title); ?>">
                        </div>
                        <div>
                            <label class="field-label">Contact Info</label>
                            <input type="text" name="contact_text" class="input-text" placeholder="e.g. phone, email" value="<?php echo htmlspecialchars($default_contact); ?>">
                        </div>
                        <div>
                            <label class="field-label">Imagery Description <span class="required">*</span></label>
                            <textarea name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. abstract wave pattern" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
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
                            <label class="field-label">Transparent background</label>
                            <input type="checkbox" name="transparent_bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>>
                            <span class="hint">Check to request a PNG with transparency (for OpenAI).</span>
                        </div>
                        <div>
                            <label class="field-label">Aspect ratio</label>
                            <select name="aspect_ratio" class="input-select">
                                <option value="landscape" <?php echo ($default_ratio === 'landscape') ? 'selected' : ''; ?>>Landscape (3:2)</option>
                                <option value="portrait" <?php echo ($default_ratio === 'portrait') ? 'selected' : ''; ?>>Portrait (2:3)</option>
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
                            <label class="field-label">Reference image (optional)</label>
                            <div class="upload-area" id="upload-area">Drag & drop or click to upload</div>
                            <input type="file" name="ref_image" id="ref-image-input" accept="image/*" style="display:none;">
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="submit-btn">
                                <span class="icon">⚡</span>
                                <span>Generate Card</span>
                            </button>
                            <span class="hint">Generation may take a few seconds. Cards are stored in their own JSON database.</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <!-- Right: Preview & Gallery -->
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#9ca3af;">Latest card</span><br>
                                <strong id="latest-title">
                                    <?php echo $preview ? htmlspecialchars($preview['name']) : 'No designs yet'; ?>
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
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest business card design">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#6b7280;padding:0 16px;text-align:center;">
                                    Your first design will appear here after you hit “Generate”.
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-name">
                            <?php if ($preview): ?>
                                <span class="key">Name:</span> <?php echo htmlspecialchars($preview['name']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-title">
                            <?php if ($preview): ?>
                                <span class="key">Title:</span> <?php echo htmlspecialchars($preview['title']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-contact">
                            <?php if ($preview): ?>
                                <span class="key">Contact:</span> <?php echo htmlspecialchars($preview['contact']); ?>
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
                    <!-- Gallery -->
                    <div class="gallery-card">
                        <div class="gallery-title-row">
                            <div class="gallery-title">Business cards</div>
                            <div class="gallery-count" id="gallery-count"><?php echo count($designs); ?> stored</div>
                        </div>
                        <div class="gallery-grid" id="gallery-grid">
                            <?php if (!empty($designs)): ?>
                                <?php foreach (array_slice($designs, 0, 12) as $item): ?>
                                    <div class="gallery-item">
                                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['file']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                        <div class="gallery-item-caption">
                                            <span class="caption-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                            <span class="caption-title"><?php echo htmlspecialchars($item['title']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:0.8rem;color:#6b7280;">Generated cards will appear here.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>

<!-- JavaScript for form handling, colour preview, file upload, AJAX submission -->
<script>
/* Colour preview update */
(function() {
    const bgInput = document.getElementById('bg-color-input');
    const bgSwatch = document.getElementById('bg-color-swatch');
    if (bgInput && bgSwatch) {
        const updateSwatch = () => { bgSwatch.style.background = bgInput.value || '#000000'; };
        updateSwatch();
        bgInput.addEventListener('input', updateSwatch);
    }
})();

/* Upload area drag & drop handling */
(function() {
    const uploadArea = document.getElementById('upload-area');
    const fileInput  = document.getElementById('ref-image-input');
    if (!uploadArea || !fileInput) return;
    function setDragState(over) {
        if (over) uploadArea.classList.add('dragover');
        else uploadArea.classList.remove('dragover');
    }
    ['dragenter','dragover'].forEach(ev => {
        uploadArea.addEventListener(ev, e => { e.preventDefault(); setDragState(true); });
    });
    ['dragleave','drop'].forEach(ev => {
        uploadArea.addEventListener(ev, e => { e.preventDefault(); setDragState(false); });
    });
    uploadArea.addEventListener('drop', e => {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            uploadArea.textContent = e.dataTransfer.files[0].name;
        }
    });
    uploadArea.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) {
            uploadArea.textContent = fileInput.files[0].name;
        }
    });
})();

/* AJAX form submission */
(function() {
    const form      = document.getElementById('design-form');
    const submitBtn = document.getElementById('submit-btn');
    const errorBox  = document.getElementById('error-banner');
    const previewFrame   = document.getElementById('preview-frame');
    const previewImg     = document.getElementById('preview-image');
    const previewBadge   = document.getElementById('preview-badge');
    const latestTitle    = document.getElementById('latest-title');
    const previewPlaceholder = document.getElementById('preview-placeholder');
    const metaName    = document.getElementById('meta-name');
    const metaTitle   = document.getId('meta-title');
    const metaContact = document.getElementById('meta-contact');
    const metaGraphic = document.getElementById('meta-graphic');
    const metaCreated = document.getElementById('meta-created');
    const galleryGrid = document.getElementById('gallery-grid');
    const galleryCount= document.getElementById('gallery-count');
    if (!form) return;
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
            submitBtn.querySelector('span:last-child').textContent = 'Generate Card';
        }
    }
    function updatePreview(design) {
        if (!design) return;
        if (latestTitle) {
            latestTitle.textContent = design.name || 'Latest card';
        }
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) {
            previewPlaceholder.style.display = 'none';
        }
        if (previewBadge) {
            previewBadge.textContent = 'PNG • ' + (design.bg_color || '#ffffff');
        }
        if (metaName) {
            metaName.innerHTML = '<span class="key">Name:</span> ' + (design.name || '');
        }
        if (metaTitle) {
            metaTitle.innerHTML = '<span class="key">Title:</span> ' + (design.title || '');
        }
        if (metaContact) {
            metaContact.innerHTML = '<span class="key">Contact:</span> ' + (design.contact || '');
        }
        if (metaGraphic) {
            metaGraphic.innerHTML = '<span class="key">Graphic:</span> ' + (design.graphic || '');
        }
        if (metaCreated) {
            metaCreated.innerHTML = '<span class="key">Created:</span> ' + (design.created_at || '');
        }
    }
    function prependToGallery(design) {
        if (!galleryGrid || !design) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'gallery-item';
        wrapper.innerHTML = `
            <img src="${design.image_url}" alt="${(design.name || '').replace(/"/g, '&quot;')}">
            <div class="gallery-item-caption">
                <span class="caption-name">${design.name || ''}</span>
                <span class="caption-title">${design.title || ''}</span>
            </div>
        `;
        galleryGrid.insertBefore(wrapper, galleryGrid.firstChild);
        if (galleryCount) {
            const count = parseInt(galleryCount.textContent) || 0;
            galleryCount.textContent = (count + 1) + ' stored';
        }
    }
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(form);
        fd.append('action', 'generate_design');
        // Validate fields client-side
        const name = fd.get('name_text').toString().trim();
        const graphic = fd.get('graphic_prompt').toString().trim();
        if (!name || !graphic) {
            setError('Please enter both the name and imagery description.');
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
                setError(data.error || 'Error generating card.');
                return;
            }
            setError('');
            updatePreview(data.design);
            prependToGallery(data.design);
        })
        .catch(() => {
            setError('Network error while generating card.');
        })
        .finally(() => {
            stopLoading();
        });
    });
})();
</script>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
