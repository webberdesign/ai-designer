<?php
/*
 * Admin Video Creator
 *
 * This page provides a basic interface for generating short videos using AI models.
 * Administrators can specify a video title, select a video type, choose a duration
 * in seconds, describe the content or script, and optionally supply a cover image
 * or reference design. The videos are generated using either a hypothetical Veo or
 * Sora API (this example does not call real APIs but stubs the behaviour by
 * generating a placeholder image). Generated videos are recorded in a JSON DB
 * with the tool set to 'video' for future browsing in the library. A gallery
 * displays previously created videos with their thumbnails.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Define constants if not defined
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', $config['gemini_model'] ?? 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', $config['openai_model'] ?? 'gpt-image-1');
}
// API keys
$GEMINI_API_KEY = $config['gemini_api_key'] ?? '';
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
// Gemini endpoint for generating placeholder images (used when Veo/Sora is unavailable)
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent";

// Storage for generated videos (we store thumbnails here) and DB
$OUTPUT_DIR = __DIR__ . '/generated_tshirts';
$DB_FILE    = __DIR__ . '/video_designs.json';

if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0775, true);
}
if (!file_exists($DB_FILE)) {
    file_put_contents($DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
ensure_storage();

// AJAX handler for video generation (placeholder implementation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_video') {
    header('Content-Type: application/json');
    $title    = trim($_POST['video_title'] ?? '');
    $videoType= trim($_POST['video_type'] ?? 'promo');
    $duration = intval($_POST['duration'] ?? 10);
    $script   = trim($_POST['script_prompt'] ?? '');
    if ($title === '' || $script === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter a title and describe the video content.']);
        exit;
    }
    // Model selection (Veo or Sora) – currently stubbed
    $model = $_POST['video_model'] ?? 'veo';
    // Attempt to create a thumbnail image representing the video (using Gemini as placeholder)
    $prompt = sprintf(
        'Poster image for a %s video titled "%s". %s. Vibrant composition, cinematic style.',
        $videoType,
        $title,
        $script
    );
    $fileMime = 'image/png';
    $imgData  = null;
    // Use Gemini or OpenAI to generate a representative thumbnail (not actual video). This is a placeholder.
    if ($GEMINI_API_KEY) {
        $gemRes = send_gemini_image_request($prompt, null, $GEMINI_API_KEY, $ENDPOINT);
        if ($gemRes[0]) {
            $fileMime = $gemRes[1];
            $imgData  = base64_decode($gemRes[2]);
        }
    }
    if (!$imgData && $OPENAI_API_KEY) {
        // fallback to OpenAI if Gemini fails
        $openRes = send_openai_image_request($prompt, null, $OPENAI_API_KEY, '1024x1536', OPENAI_MODEL);
        if ($openRes[0]) {
            $fileMime = $openRes[1];
            $imgData  = base64_decode($openRes[2]);
        }
    }
    // If still no image, create a blank placeholder
    if (!$imgData) {
        $imgData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAIAAAB7GkOtAAAAA3NCSVQICAjb4U/gAAAgAElEQVR4XuzcwQkCMAyAQY//6S4NJTWXAZ4Ql0+3RBIr8KpPstQAAAAAAAAAAAAAAAAAAADwHu5dnbQCAAAAcNlOsQAAAOC+hSUAAAAA8IEhNQAAAABw1UztAAAAALjPS1IAAABw1bLtAAAAALi9ktMAAABwdQPz6d0AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABwGhXgAAAB7kz7lgAAAABJRU5ErkJggg==');
        $fileMime = 'image/png';
    }
    // Determine extension
    $ext = match ($fileMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    try {
        $filename = 'video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = 'video_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = $OUTPUT_DIR . '/' . $filename;
    file_put_contents($filepath, $imgData);
    // Save record
    $db = json_decode(@file_get_contents($DB_FILE), true) ?: [];
    $record = [
        'id'         => uniqid('video_', true),
        'title'      => $title,
        'type'       => $videoType,
        'duration'   => $duration,
        'script'     => $script,
        'thumbnail'  => $filename,
        'created_at' => date('c'),
        'published'  => false,
        'source'     => $model,
        'tool'       => 'video'
    ];
    $db[] = $record;
    file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'success' => true,
        'design'  => [
            'id'       => $record['id'],
            'title'    => $record['title'],
            'thumbnail'=> $record['thumbnail'],
            'image_url'=> 'generated_tshirts/' . rawurlencode($record['thumbnail'])
        ],
    ]);
    exit;
}

// Load existing videos
$videosAll = json_decode(@file_get_contents($DB_FILE), true) ?: [];
$videos    = array_reverse($videosAll);
$preview   = $videos[0] ?? null;

// Default form values
$default_title   = isset($_POST['video_title']) ? trim($_POST['video_title']) : '';
$default_type    = isset($_POST['video_type']) ? trim($_POST['video_type']) : 'promo';
$default_duration= isset($_POST['duration']) ? intval($_POST['duration']) : 10;
if ($default_duration < 5) $default_duration = 5;
if ($default_duration > 60) $default_duration = 60;
$default_script  = isset($_POST['script_prompt']) ? trim($_POST['script_prompt']) : '';
$default_model   = isset($_POST['video_model']) ? trim($_POST['video_model']) : 'veo';

// Reference options (reuse design IDs for thumbnails if needed in future)
$reference_options = get_all_reference_options();

$pageTitle = 'Video Creator';
$activeSection = 'create';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Video Creator</h1>
            <div class="card">
                <h2>Create Video</h2>
                <form id="video-form" method="post">
                    <input type="hidden" name="action" value="generate_video">
                    <label class="field-label">Title <span>*</span></label>
                    <input type="text" name="video_title" value="<?php echo htmlspecialchars($default_title); ?>" required>
                    <label class="field-label">Type</label>
                    <select name="video_type">
                        <?php $types = ['promo','explainer','social','tutorial','event']; foreach ($types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $default_type === $type ? 'selected' : ''; ?>><?php echo ucwords($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="field-label">Duration (seconds)</label>
                    <input type="number" name="duration" min="5" max="60" value="<?php echo htmlspecialchars($default_duration); ?>" step="1">
                    <label class="field-label">Content / Script <span>*</span></label>
                    <textarea name="script_prompt" rows="3" required><?php echo htmlspecialchars($default_script); ?></textarea>
                    <label class="field-label">Video Model</label>
                    <select name="video_model">
                        <option value="veo" <?php echo $default_model === 'veo' ? 'selected' : ''; ?>>Veo</option>
                        <option value="sora" <?php echo $default_model === 'sora' ? 'selected' : ''; ?>>Sora</option>
                    </select>
                    <label class="field-label">Reference Design (optional)</label>
                    <select name="existing_ref">
                        <option value="">-- Select --</option>
                        <?php foreach ($reference_options as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt['id']); ?>"><?php echo htmlspecialchars($opt['title'] ?? $opt['display_text'] ?? $opt['subject'] ?? $opt['name'] ?? $opt['id']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="field-label">Cover Image (optional)</label>
                    <input type="file" name="ref_image" accept="image/*">
                    <button type="submit" class="btn">Generate Video</button>
                </form>
            </div>
            <div class="card">
                <h2>Preview</h2>
                <?php if ($preview): ?>
                    <div class="preview-frame" id="preview-frame" style="margin-bottom:12px;">
                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($preview['thumbnail']); ?>" alt="Latest video" style="width:100%;height:auto;border-radius:6px;">
                    </div>
                    <table style="width:100%;font-size:0.85rem;border-collapse:collapse;">
                        <tr><th style="width:35%;text-align:left;color:#6b7280;padding:4px 6px;">Title</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars($preview['title']); ?></td></tr>
                        <tr><th style="color:#6b7280;padding:4px 6px;">Type</th><td style="color:#374151;padding:4px 6px;"><?php echo ucwords($preview['type']); ?></td></tr>
                        <tr><th style="color:#6b7280;padding:4px 6px;">Duration</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars($preview['duration']); ?>s</td></tr>
                        <tr><th style="color:#6b7280;padding:4px 6px;">Created</th><td style="color:#374151;padding:4px 6px;"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($preview['created_at']))); ?></td></tr>
                    </table>
                <?php else: ?>
                    <p style="color:#6b7280;">No videos generated yet. Your first video will appear here after you hit "Generate Video".</p>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>Recent Videos</h2>
                <div class="gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;">
                    <?php foreach ($videos as $item): ?>
                        <div class="gallery-item">
                            <img src="<?php echo 'generated_tshirts/' . rawurlencode($item['thumbnail']); ?>" alt="video thumbnail">
                            <div class="gallery-info">
                                <div class="title"><?php echo htmlspecialchars($item['title']); ?></div>
                                <div class="meta"><?php echo ucwords($item['type']); ?> • <?php echo htmlspecialchars($item['duration']); ?>s</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div><script>
// Video generation via AJAX (placeholder)
document.getElementById('video-form').addEventListener('submit', function(e) {
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
                window.location.reload();
            }
        }
    }).catch(() => {
        if (btn) btn.disabled = false;
        alert('An error occurred generating the video.');
    });
});
</script>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
