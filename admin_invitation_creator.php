<?php
/*
 * Admin Invitation Creator
 *
 * Generates invitation designs for events such as weddings, birthdays, corporate
 * gatherings and showers. Supports Gemini and OpenAI image models, optional
 * reference images, aspect ratio selection, and transparent backgrounds.
 * Designs are stored in invitation_designs.json with tool=invitation so they
 * can be filtered in the media library.
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

// API keys and endpoints
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
$ENDPOINT       = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage paths
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/invitation_designs.json';

// Ensure storage exists
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
ensure_storage();

// Handle invitation generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $eventName = trim($_POST['title_text'] ?? '');
    $occasion  = trim($_POST['occasion'] ?? 'celebration');
    $details   = trim($_POST['details_text'] ?? '');
    $graphic   = trim($_POST['graphic_prompt'] ?? '');
    $bgColor   = trim($_POST['bg_color'] ?? '#ffffff');
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    if ($eventName === '' || $graphic === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter an event name and describe the imagery.']);
        exit;
    }

    $occasionLabel = match ($occasion) {
        'wedding'    => 'an elegant wedding',
        'birthday'   => 'a lively birthday',
        'shower'     => 'a baby or bridal shower',
        'corporate'  => 'a formal corporate event',
        'holiday'    => 'a festive holiday gathering',
        default      => 'a modern celebration',
    };

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
            $ratioDesc = 'portrait 2:3 aspect ratio';
            $sizeParam = '1024x1536';
            break;
    }

    $detailPart = $details !== '' ? ' Details: "' . $details . '".' : '';
    $backgroundLine = $transparent
        ? 'Transparent background suitable for print or digital use.'
        : 'Use a solid ' . $bgColor . ' background.';
    $prompt = sprintf(
        'Invitation design, %s. %s Event name: "%s". Occasion: %s.%s Imagery to feature: %s. Clear typography, generous margins, modern invitation layout.',
        $ratioDesc,
        $backgroundLine,
        $eventName,
        $occasionLabel,
        $detailPart,
        $graphic
    );

    // Reference image for Gemini
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

    $chosenModel = $_POST['image_model'] ?? 'gemini';
    $fileMime    = 'image/png';
    $imgData     = null;

    if ($chosenModel === 'openai') {
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it on the Config page.']);
            exit;
        }
        $openRes = send_openai_image_request($prompt, $background, $OPENAI_API_KEY, $sizeParam, OPENAI_MODEL);
        if (!$openRes[0]) {
            echo json_encode(['success' => false, 'error' => $openRes[1] ?: 'Error generating invitation with OpenAI.']);
            exit;
        }
        $fileMime = $openRes[1];
        $imgData  = base64_decode($openRes[2]);
    } else {
        if (!$GEMINI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured. Please set it on the Config page.']);
            exit;
        }
        $gemRes = send_gemini_image_request($prompt, $inlineData, $GEMINI_API_KEY, $ENDPOINT);
        if (!$gemRes[0]) {
            echo json_encode(['success' => false, 'error' => $gemRes[1] ?: 'Error generating invitation with Gemini.']);
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
        $filename = 'invitation_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'invitation_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }

    $db   = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'        => uniqid('invitation_', true),
        'title'     => $eventName,
        'occasion'  => $occasion,
        'details'   => $details,
        'bg_color'  => $bgColor,
        'aspect'    => $ratio,
        'file'      => $filename,
        'created_at'=> date('c'),
        'published' => false,
        'source'    => $chosenModel,
        'tool'      => 'invitation',
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'success' => true,
        'design'  => [
            'id'        => $record['id'],
            'title'     => $record['title'],
            'occasion'  => $record['occasion'],
            'details'   => $record['details'],
            'bg_color'  => $record['bg_color'],
            'file'      => $record['file'],
            'created_at'=> $record['created_at'],
            'image_url' => 'generated_tshirts/' . rawurlencode($record['file'])
        ]
    ]);
    exit;
}

// Load designs
$designsAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$designs    = array_reverse($designsAll);
$preview    = $designs[0] ?? null;

// Sticky defaults
$default_title   = isset($_POST['title_text']) ? trim($_POST['title_text']) : '';
$default_details = isset($_POST['details_text']) ? trim($_POST['details_text']) : '';
$default_graphic = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : '';
$default_bg      = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : '#ffffff';
$default_model   = isset($_POST['image_model']) ? $_POST['image_model'] : 'gemini';
$default_ratio   = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : 'portrait';
$default_occ     = isset($_POST['occasion']) ? $_POST['occasion'] : 'celebration';

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

$pageTitle     = 'Admin — Invitation Creator';
$activeSection = 'create';
$extraCss      = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Invitation Creator</h1>
            <div class="page-shell">
                <section class="card">
                    <div class="card-title">Invitation Details</div>
                    <div class="card-sub">Describe the event, pick a style, and add imagery notes. Upload a reference image if you want the AI to follow a layout or motif.</div>
                    <form id="design-form" method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="generate_design">
                        <div>
                            <label class="field-label" for="title-text">Event Name <span class="required">*</span></label>
                            <input type="text" id="title-text" name="title_text" class="input-text" placeholder="e.g. Olivia &amp; Noah Wedding" required value="<?php echo htmlspecialchars($default_title); ?>">
                        </div>
                        <div>
                            <label class="field-label" for="occasion">Occasion</label>
                            <select id="occasion" name="occasion" class="input-select">
                                <?php
                                $occasions = [
                                    'celebration' => 'Celebration',
                                    'wedding'     => 'Wedding',
                                    'birthday'    => 'Birthday',
                                    'shower'      => 'Baby/Bridal Shower',
                                    'corporate'   => 'Corporate Event',
                                    'holiday'     => 'Holiday Party'
                                ];
                                foreach ($occasions as $key => $label) {
                                    $selected = ($key === $default_occ) ? 'selected' : '';
                                    echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="details-text">Details (date, venue)</label>
                            <input type="text" id="details-text" name="details_text" class="input-text" placeholder="e.g. 14 Sep • 6 PM • The Conservatory" value="<?php echo htmlspecialchars($default_details); ?>">
                        </div>
                        <div>
                            <label class="field-label" for="graphic-prompt">Imagery Description <span class="required">*</span></label>
                            <textarea id="graphic-prompt" name="graphic_prompt" class="input-text" rows="3" placeholder="e.g. botanical wreath, gold foil accents" required><?php echo htmlspecialchars($default_graphic); ?></textarea>
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
                            <label class="field-label" for="transparent-bg">
                                <input type="checkbox" id="transparent-bg" name="transparent_bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>> Transparent background
                            </label>
                        </div>
                        <div>
                            <label class="field-label" for="aspect-ratio">Aspect Ratio</label>
                            <select id="aspect-ratio" name="aspect_ratio" class="input-select">
                                <option value="portrait" <?php echo ($default_ratio === 'portrait') ? 'selected' : ''; ?>>Portrait (2:3)</option>
                                <option value="landscape" <?php echo ($default_ratio === 'landscape') ? 'selected' : ''; ?>>Landscape (3:2)</option>
                                <option value="square" <?php echo ($default_ratio === 'square') ? 'selected' : ''; ?>>Square (1:1)</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="image-model">Image Model</label>
                            <select id="image-model" name="image_model" class="input-select">
                                <option value="gemini" <?php echo ($default_model === 'gemini') ? 'selected' : ''; ?>>Gemini 2.5</option>
                                <option value="openai" <?php echo ($default_model === 'openai') ? 'selected' : ''; ?>>OpenAI (GPT)</option>
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
                            <button type="submit" class="btn-primary" id="submit-btn"><span class="icon">💌</span><span>Generate Invitation</span></button>
                            <span class="hint">Invites are saved automatically and shown in the gallery.</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <section class="preview-shell">
                    <div class="preview-card">
                        <div class="preview-meta">
                            <div>
                                <span style="font-size:0.75rem;color:#cbd5e1;">Latest invitation</span><br>
                                <strong id="latest-title"><?php echo $preview ? htmlspecialchars($preview['title']) : 'No invitations yet'; ?></strong>
                            </div>
                            <div style="font-size:0.75rem;color:#cbd5e1;">Aspect: <?php echo htmlspecialchars($ratioDisplay); ?><br>Size: <?php echo htmlspecialchars($sizeDisplay); ?></div>
                        </div>
                        <div class="preview-frame" id="preview-frame">
                            <?php if ($preview): ?>
                                <span class="preview-badge" id="preview-badge"><?php echo strtoupper($preview['occasion'] ?? 'Event'); ?></span>
                                <img id="preview-image" src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest invitation">
                            <?php else: ?>
                                <span class="preview-badge" id="preview-badge">Waiting…</span>
                                <img id="preview-image" src="" alt="" style="display:none;">
                                <span id="preview-placeholder" style="font-size:0.8rem;color:#cbd5e1;padding:0 16px;text-align:center;">Your first invitation will appear here after you generate one.</span>
                            <?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-title">
                            <?php if ($preview): ?><span class="key">Event:</span> <?php echo htmlspecialchars($preview['title']); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-occasion">
                            <?php if ($preview): ?><span class="key">Occasion:</span> <?php echo htmlspecialchars($preview['occasion']); ?><?php endif; ?>
                        </div>
                        <div class="meta-line" id="meta-details">
                            <?php if ($preview && ($preview['details'] ?? '')): ?><span class="key">Details:</span> <?php echo htmlspecialchars($preview['details']); ?><?php endif; ?>
                        </div>
                        <?php if ($preview): ?>
                            <a href="admin_edit_design.php?id=<?php echo urlencode($preview['id']); ?>" class="btn-edit" target="_blank">Edit</a>
                        <?php endif; ?>
                    </div>
                    <div class="gallery-card">
                        <div class="gallery-title-row">
                            <div class="gallery-title">Recent invitations</div>
                            <div class="gallery-count" id="gallery-count"><?php echo count($designs); ?> stored</div>
                        </div>
                        <div class="gallery-grid" id="gallery-grid">
                            <?php if (!empty($designs)): ?>
                                <?php foreach (array_slice($designs, 0, 12) as $item): ?>
                                    <div class="gallery-item">
                                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['file']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                                        <div class="gallery-item-caption">
                                            <span class="caption-text"><?php echo htmlspecialchars($item['title']); ?></span>
                                            <span class="caption-sub"><?php echo htmlspecialchars($item['occasion']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:0.8rem;color:#6b7280;">Invitations you generate will show up here as a mini gallery.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div><script>
/* JS – colour preview, drag‑and‑drop upload, AJAX submission and gallery updates */
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
    const metaOccasion= document.getElementById('meta-occasion');
    const metaDetails = document.getElementById('meta-details');
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
            return;
        }
        errorBox.style.display = 'block';
        errorBox.textContent = msg;
    }
    function startLoading() {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class=\"icon\">⏳</span><span>Generating…</span>';
        }
        if (previewFrame) previewFrame.classList.add('loading');
    }
    function stopLoading() {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span class=\"icon\">💌</span><span>Generate Invitation</span>';
        }
        if (previewFrame) previewFrame.classList.remove('loading');
    }
    function updatePreview(design) {
        if (!design) return;
        if (previewTitle) previewTitle.textContent = design.title || 'Latest invitation';
        if (previewImg) {
            previewImg.src = design.image_url;
            previewImg.style.display = 'block';
        }
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        if (previewBadge) previewBadge.textContent = (design.occasion || 'EVENT').toUpperCase();
        if (metaTitle) metaTitle.innerHTML = '<span class=\"key\">Event:</span> ' + (design.title || '');
        if (metaOccasion) metaOccasion.innerHTML = '<span class=\"key\">Occasion:</span> ' + (design.occasion || '');
        if (metaDetails) metaDetails.innerHTML = design.details ? '<span class=\"key\">Details:</span> ' + design.details : '';
    }
    function prependToGallery(design) {
        if (!galleryGrid || !design) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'gallery-item';
        wrapper.innerHTML = `
            <img src=\"${design.image_url}\" alt=\"${(design.title || '').replace(/\"/g, '&quot;')}\">
            <div class=\"gallery-item-caption\">
                <span class=\"caption-text\">${design.title || ''}</span>
                <span class=\"caption-sub\">${design.occasion || ''}</span>
            </div>
        `;
        if (galleryGrid.firstChild) {
            galleryGrid.insertBefore(wrapper, galleryGrid.firstChild);
        } else {
            galleryGrid.appendChild(wrapper);
        }
        if (galleryCount) {
            const current = parseInt(galleryCount.textContent, 10) || 0;
            galleryCount.textContent = (current + 1) + ' stored';
        }
    }
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
                placeholder.textContent = e.dataTransfer.files[0].name;
            }
        });
        dropArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files[0]) {
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
                setError('Please enter an event name and imagery description.');
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
