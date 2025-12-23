<?php
// Admin Idea Generator page. This page replicates the T‑shirt idea generator within the admin dashboard.
// It allows admins to generate and store creative shirt concepts using the OpenAI chat API.

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';
// Define the OpenAI chat model constant from configuration if not already defined
if (!defined('OPENAI_CHAT_MODEL')) {
    define('OPENAI_CHAT_MODEL', $config['openai_chat_model'] ?? 'gpt-4o');
}
// Assign the OpenAI API key from configuration (leave empty if not set)
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
$IDEAS_DB_FILE  = __DIR__ . '/tshirt_ideas.json';

// Ensure the ideas database exists
if (!file_exists($IDEAS_DB_FILE)) {
    file_put_contents($IDEAS_DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Helper to save a new idea to the JSON DB
function save_idea_admin(array $idea, string $dbFile)
{
    $db = json_decode(@file_get_contents($dbFile), true) ?: [];
    $db[] = $idea;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Handle AJAX generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_idea') {
    header('Content-Type: application/json');
    $theme = trim($_POST['theme'] ?? '');
    // Validate API key
    global $OPENAI_API_KEY, $IDEAS_DB_FILE;
    // Validate that the OpenAI API key is available; instruct the user to configure it via the Config page
    if (!$OPENAI_API_KEY) {
        echo json_encode([
            'success' => false,
            'error'   => 'OpenAI API key is not set. Please update it on the Config page.',
        ]);
        exit;
    }
    // Build prompt
    $instruction = "You are a creative T‑shirt idea generator. Answer with a single JSON object having exactly four keys: \"text\", \"graphic\", \"bg_color\" and \"design_colors\". "
        . "The \"text\" field should be a short, catchy phrase that could appear on a shirt. "
        . "The \"graphic\" field should briefly describe an illustration that complements the text. "
        . "The \"bg_color\" field should be a hex colour code (e.g., #1A202C) representing the shirt background. "
        . "The \"design_colors\" field should be an array of two or three hex colour codes representing the colours used in the graphic and text. "
        . "Do not wrap the JSON in markdown fences or add any commentary. Just return the JSON.";
    if ($theme !== '') {
        $instruction .= " Create a concept inspired by the theme: {$theme}.";
    }
    $messages = [
        ['role' => 'system', 'content' => 'You are an assistant that returns creative shirt ideas in JSON format as instructed.'],
        ['role' => 'user',   'content' => $instruction],
    ];
    $payload = [
        'model'       => OPENAI_CHAT_MODEL,
        'messages'    => $messages,
        'max_tokens'  => 200,
        'temperature' => 0.8,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($status >= 400) {
        $message = $data['error']['message'] ?? 'Unknown API error';
        echo json_encode(['success' => false, 'error' => "OpenAI API error ({$status}): {$message}"]);
        exit;
    }
    $content = $data['choices'][0]['message']['content'] ?? '';
    $idea = json_decode($content, true);
    if (!is_array($idea) || !isset($idea['text'], $idea['graphic'], $idea['bg_color'], $idea['design_colors'])) {
        echo json_encode([
            'success' => false,
            'error'   => 'The AI did not return a valid JSON idea. Ensure the model instruction is followed.',
            'raw'     => $content,
        ]);
        exit;
    }
    $record = [
        'id'            => uniqid('idea_', true),
        'text'          => trim($idea['text']),
        'graphic'       => trim($idea['graphic']),
        'bg_color'      => trim($idea['bg_color']),
        'design_colors' => (array)$idea['design_colors'],
        'theme'         => $theme,
        'created_at'    => date('c'),
    ];
    save_idea_admin($record, $IDEAS_DB_FILE);
    echo json_encode(['success' => true, 'idea' => $record]);
    exit;
}

// Load existing ideas
$ideas = json_decode(@file_get_contents($IDEAS_DB_FILE), true) ?: [];
$ideas = array_reverse($ideas);
$pageTitle = 'Admin — Idea Generator';
$activeSection = 'idea';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Idea Generator</h1>
            <div class="page-shell">
                <header class="page-header">
                    <div>
                        <div class="page-title">T‑Shirt Idea Generator</div>
                        <div class="card-sub">Create and manage shirt concepts before turning them into artwork</div>
                    </div>
                    <div class="pill" style="background:none;color:#9ca3af;">MODEL: <?php echo htmlspecialchars(OPENAI_CHAT_MODEL); ?></div>
                </header>
                <!-- Left column: idea generator form -->
                <section class="card">
                    <div class="card-title">Generate a new idea</div>
                    <div class="card-sub">Optionally provide a theme to inspire the concept</div>
                    <form id="idea-form" class="form-grid" method="post">
                        <div>
                            <label class="field-label">Theme / Topic</label>
                            <input type="text" name="theme" class="input-text" placeholder="e.g., space exploration, vintage cars">
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn-primary" id="generate-btn">
                                <span class="icon">🎲</span>
                                <span>Generate Idea</span>
                            </button>
                            <span class="hint">Ideas are stored for later use</span>
                        </div>
                        <div id="error-banner" class="error-banner" style="display:none;"></div>
                    </form>
                </section>
                <!-- Right column: ideas list -->
                <section class="card">
                    <div class="card-title">Saved Ideas</div>
                    <div class="card-sub">Click “Use Idea” to pre‑fill the designer form</div>
                    <div class="idea-list" id="idea-list">
                        <?php if (!empty($ideas)): ?>
                            <?php foreach ($ideas as $idea): ?>
                                <div class="idea-card">
                                    <h4><?php echo htmlspecialchars($idea['text']); ?></h4>
                                    <div style="font-size:0.8rem;color:#9ca3af;">Graphic: <?php echo htmlspecialchars($idea['graphic']); ?></div>
                                    <div style="font-size:0.8rem;color:#9ca3af;">BG: <span style="color:<?php echo htmlspecialchars($idea['bg_color']); ?>; font-weight:600;"><?php echo htmlspecialchars($idea['bg_color']); ?></span></div>
                                    <div class="colors">
                                        <?php foreach ($idea['design_colors'] as $clr): ?>
                                            <span class="color-swatch" style="background: <?php echo htmlspecialchars($clr); ?>;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="idea-actions">
                                        <a href="admin_design_lab.php?text=<?php echo rawurlencode($idea['text']); ?>&graphic=<?php echo rawurlencode($idea['graphic']); ?>&bg=<?php echo rawurlencode($idea['bg_color']); ?>&colors=<?php echo rawurlencode(implode(',', $idea['design_colors'])); ?>">Use Idea</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="hint">No ideas yet. Generate one!</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div><script>
/* JS for idea generation via AJAX */
(function() {
    const form = document.getElementById('idea-form');
    const generateBtn = document.getElementById('generate-btn');
    const errorBanner = document.getElementById('error-banner');
    const ideaList = document.getElementById('idea-list');
    function setError(msg) {
        if (!msg) {
            errorBanner.style.display = 'none';
            errorBanner.textContent = '';
        } else {
            errorBanner.style.display = 'block';
            errorBanner.textContent = msg;
        }
    }
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(form);
        formData.append('action', 'generate_idea');
        setError('');
        generateBtn.disabled = true;
        generateBtn.querySelector('.icon').textContent = '⏳';
        generateBtn.querySelector('span:last-child').textContent = 'Generating…';
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                setError(data.error || 'Failed to generate idea.');
            } else {
                const idea = data.idea;
                const div = document.createElement('div');
                div.className = 'idea-card';
                div.innerHTML = `
                    <h4>${escapeHtml(idea.text)}</h4>
                    <div style="font-size:0.8rem;color:#9ca3af;">Graphic: ${escapeHtml(idea.graphic)}</div>
                    <div style="font-size:0.8rem;color:#9ca3af;">BG: <span style="color:${escapeHtml(idea.bg_color)}; font-weight:600;">${escapeHtml(idea.bg_color)}</span></div>
                    <div class="colors">
                        ${idea.design_colors.map(c => `<span class="color-swatch" style="background:${escapeHtml(c)};"></span>`).join('')}
                    </div>
                    <div class="idea-actions">
                        <a href="admin_design_lab.php?text=${encodeURIComponent(idea.text)}&graphic=${encodeURIComponent(idea.graphic)}&bg=${encodeURIComponent(idea.bg_color)}&colors=${encodeURIComponent(idea.design_colors.join(','))}">Use Idea</a>
                    </div>
                `;
                if (ideaList.firstChild) {
                    ideaList.insertBefore(div, ideaList.firstChild);
                } else {
                    ideaList.appendChild(div);
                }
            }
        })
        .catch(() => {
            setError('Network error while generating idea.');
        })
        .finally(() => {
            generateBtn.disabled = false;
            generateBtn.querySelector('.icon').textContent = '🎲';
            generateBtn.querySelector('span:last-child').textContent = 'Generate Idea';
        });
    });
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();
</script>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
