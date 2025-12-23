<?php
/*
 * Admin Cover Art Creator
 *
 * This page allows administrators to generate cover art for different media such as
 * books, magazines, presentations, music albums, and brochures. Administrators
 * can specify a title and optional subtitle, choose a category, describe imagery
 * to include, pick a background colour and aspect ratio, and optionally upload
 * or select a reference image to influence the design when using Gemini. The
 * generated covers are stored in their own JSON database and displayed in a
 * gallery. Each record is tagged with tool => 'cover' to distinguish it from
 * other design types.
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
$DB_FILE    = __DIR__ . '/cover_designs.json';

// Ensure directories and DB exist
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
// Ensure other storage directories exist
ensure_storage();

// Handle AJAX request to generate a cover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_design') {
    header('Content-Type: application/json');
    $title    = trim($_POST['title_text'] ?? '');
    $subtitle = trim($_POST['subtitle_text'] ?? '');
    $category = trim($_POST['category_select'] ?? 'book');
    $imagery  = trim($_POST['graphic_prompt'] ?? '');
    $bgColor  = trim($_POST['bg_color'] ?? '#ffffff');
    $transparent = isset($_POST['transparent_bg']);
    $background  = $transparent ? 'transparent' : null;
    // Validate
    if ($title === '' || $imagery === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter a title and describe the imagery.']);
        exit;
    }
    // Determine selected model
    $chosenModel = $_POST['image_model'] ?? 'gemini';
    // Determine aspect ratio
    $ratio = $_POST['aspect_ratio'] ?? 'portrait';
    switch ($ratio) {
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
    // Build prompt. Include category and subtitle when provided. Always mention solid background colour in prompt.
    $subtitlePart = $subtitle !== '' ? " Subtitle: \"$subtitle\"." : '';
    $prompt = sprintf(
        'Cover art design for a %s, %s aspect ratio. Title: "%s".%s Use imagery: %s. Solid %s background. Balanced composition, professional and appealing.',
        $category,
        $ratioDesc,
        $title,
        $subtitlePart,
        $imagery,
        $bgColor
    );
    // Handle reference image when using Gemini
    $inlineData = null;
    // Uploaded file
    if (isset($_FILES['ref_image']) && $_FILES['ref_image']['error'] === UPLOAD_ERR_OK && $_FILES['ref_image']['size'] > 0) {
        $tmp  = $_FILES['ref_image']['tmp_name'];
        $mime = mime_content_type($tmp) ?: 'image/png';
        $bytes = file_get_contents($tmp);
        if ($bytes !== false) {
            $inlineData = ['mime_type' => $mime, 'data' => base64_encode($bytes)];
        }
    } elseif (!empty($_POST['existing_ref'])) {
        // Use existing design as reference
        $refId = $_POST['existing_ref'];
        // Search all design DBs for the matching record to get file
        $refFile = find_file_by_id($refId);
        if ($refFile) {
            $refPath = __DIR__ . '/generated_tshirts/' . $refFile;
            if (is_file($refPath)) {
                $mime  = mime_content_type($refPath) ?: 'image/png';
                $bytes = file_get_contents($refPath);
                if ($bytes !== false) {
                    $inlineData = ['mime_type' => $mime, 'data' => base64_encode($bytes)];
                }
            }
        }
    }
    $fileMime = 'image/png';
    $imgData  = null;
    if ($chosenModel === 'openai') {
        if (!$OPENAI_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured. Please set it via the Config page.']);
            exit;
        }
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
        $filename = 'cover_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'cover_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    if (file_put_contents($filepath, $imgData) === false) {
        echo json_encode(['success' => false, 'error' => 'Could not save generated file.']);
        exit;
    }
    // Save record
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'         => uniqid('cover_', true),
        'title'      => $title,
        'subtitle'   => $subtitle,
        'category'   => $category,
        'imagery'    => $imagery,
        'bg_color'   => $bgColor,
        'aspect'     => $ratio,
        'file'       => $filename,
        'created_at' => date('c'),
        'published'  => false,
        'source'     => $chosenModel,
        'tool'       => 'cover'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'         => $record['id'],
            'title'      => $record['title'],
            'subtitle'   => $record['subtitle'],
            'category'   => $record['category'],
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

// Default sticky form values
$default_title    = isset($_POST['title_text']) ? trim($_POST['title_text']) : (isset($_GET['title']) ? trim($_GET['title']) : '');
$default_subtitle = isset($_POST['subtitle_text']) ? trim($_POST['subtitle_text']) : (isset($_GET['subtitle']) ? trim($_GET['subtitle']) : '');
$default_category = isset($_POST['category_select']) ? $_POST['category_select'] : (isset($_GET['cat']) ? $_GET['cat'] : 'book');
$default_imagery  = isset($_POST['graphic_prompt']) ? trim($_POST['graphic_prompt']) : (isset($_GET['imagery']) ? trim($_GET['imagery']) : '');
$default_bg       = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : (isset($_GET['bg']) ? trim($_GET['bg']) : '#ffffff');
$default_model    = isset($_POST['image_model']) ? $_POST['image_model'] : 'gemini';
$default_ratio    = isset($_POST['aspect_ratio']) ? $_POST['aspect_ratio'] : 'portrait';
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

// Build reference options list across all tools for selecting as reference
$reference_options = get_all_reference_options();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Cover Art Creator</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin-styles.css">
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
            <h1>Cover Art Creator</h1>
            <div class="card">
                <h2>Create Cover Art</h2>
                <form id="cover-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="generate_design">
                    <label class="field-label">Title <span>*</span></label>
                    <input type="text" name="title_text" value="<?php echo htmlspecialchars($default_title); ?>" required>
                    <label class="field-label">Subtitle (optional)</label>
                    <input type="text" name="subtitle_text" value="<?php echo htmlspecialchars($default_subtitle); ?>">
                    <label class="field-label">Category</label>
                    <select name="category_select">
                        <?php $cats = ['book','magazine','presentation','music','brochure']; foreach ($cats as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo $default_category === $c ? 'selected' : ''; ?>><?php echo ucwords($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="field-label">Imagery / Description <span>*</span></label>
                    <textarea name="graphic_prompt" rows="3" required><?php echo htmlspecialchars($default_imagery); ?></textarea>
                    <label class="field-label">Background colour</label>
                    <input type="color" name="bg_color" value="<?php echo htmlspecialchars($default_bg); ?>">
                    <div style="margin-bottom:12px;">
                        <input type="checkbox" name="transparent_bg" id="transparent_bg" <?php echo isset($_POST['transparent_bg']) ? 'checked' : ''; ?>>
                        <label for="transparent_bg">Transparent background (OpenAI only)</label>
                    </div>
                    <label class="field-label">Aspect Ratio</label>
                    <select name="aspect_ratio">
                        <option value="portrait" <?php echo $default_ratio === 'portrait' ? 'selected' : ''; ?>>Portrait 2:3</option>
                        <option value="landscape" <?php echo $default_ratio === 'landscape' ? 'selected' : ''; ?>>Landscape 3:2</option>
                        <option value="square" <?php echo $default_ratio === 'square' ? 'selected' : ''; ?>>Square 1:1</option>
                    </select>
                    <label class="field-label">Image Model</label>
                    <select name="image_model">
                        <option value="gemini" <?php echo $default_model === 'gemini' ? 'selected' : ''; ?>>Gemini</option>
                        <option value="openai" <?php echo $default_model === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                    </select>
                    <label class="field-label">Reference Design (optional)</label>
                    <select name="existing_ref">
                        <option value="">-- Select --</option>
                        <?php foreach ($reference_options as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt['id']); ?>"><?php echo htmlspecialchars($opt['title'] ?? $opt['display_text'] ?? $opt['subject'] ?? $opt['name'] ?? $opt['id']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="field-label">Upload Reference (optional)</label>
                    <input type="file" name="ref_image" accept="image/*">
                    <button type="submit" class="btn">Generate Cover</button>
                </form>
            </div>
            <div class="card">
                <h2>Preview</h2>
                <?php if ($preview): ?>
                    <div style="margin-bottom:12px; color:#6b7280; font-size:0.9rem;">
                        Aspect Ratio: <?php echo $ratioDisplay; ?> (<?php echo $sizeDisplay; ?>)
                    </div>
                    <div class="preview-frame" id="preview-frame" style="margin-bottom:12px;">
                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($preview['file']); ?>" alt="Latest cover" style="width:100%;height:auto;border-radius:6px;">
                    </div>
                    <table style="width:100%;font-size:0.85rem;border-collapse:collapse;">
                        <tr><th style="width:35%;text-align:left;color:#6b7280;padding:4px 6px;">Title</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars($preview['title']); ?></td></tr>
                        <?php if (!empty($preview['subtitle'])): ?><tr><th style="color:#6b7280;padding:4px 6px;">Subtitle</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars($preview['subtitle']); ?></td></tr><?php endif; ?>
                        <tr><th style="color:#6b7280;padding:4px 6px;">Category</th><td style="color:#374151;padding:4px 6px;"><?php echo ucwords($preview['category']); ?></td></tr>
                        <tr><th style="color:#6b7280;padding:4px 6px;">Imagery</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars($preview['imagery']); ?></td></tr>
                        <tr><th style="color:#6b7280;padding:4px 6px;">Created</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($preview['created_at']))); ?></td></tr>
                    </table>
                <?php else: ?>
                    <p style="color:#6b7280;">No covers generated yet. Your first cover will appear here after you hit "Generate Cover".</p>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>Recent Covers</h2>
                <div class="gallery" id="gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;">
                    <?php foreach ($designs as $item): ?>
                        <div class="gallery-item">
                            <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['file']); ?>" alt="cover thumbnail">
                            <div class="gallery-info">
                                <div class="title"><?php echo htmlspecialchars($item['title']); ?></div>
                                <div class="meta"><?php echo ucwords($item['category']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</div>
<script>
// Cover generation via AJAX
document.getElementById('cover-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = e.target;
    const btn  = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    const fd   = new FormData(form);
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(resp => resp.json()).then(data => {
        if (btn) btn.disabled = false;
        if (data.error) {
            alert(data.error);
        } else {
            if (data.design) {
                // Reload page to update preview and gallery
                window.location.reload();
            }
        }
    }).catch(() => {
        if (btn) btn.disabled = false;
        alert('An error occurred generating the cover.');
    });
});
</script>
</body>
</html>