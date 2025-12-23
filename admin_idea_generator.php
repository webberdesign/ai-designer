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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Idea Generator</title>
    <style>
        /* Admin base styles (copied from admin.php) */
        :root {
            --primary: #111827;
            --secondary: #1f2937;
            --accent: #2563eb;
            --accent-light: #3b82f6;
            --text-light: #f3f4f6;
            --text-muted: #9ca3af;
            --bg: #f6f7fb;
            --bg-dark: #0f172a;
            --warning: #ef4444;
            --success: #10b981;
        }
        body {
            margin: 0;
            font-family: system-ui, sans-serif;
            background: var(--bg);
            color: #333;
        }
        .admin-container {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        header.admin-header {
            background: var(--primary);
            color: var(--text-light);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .brand {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        header .top-nav a {
            color: var(--text-light);
            text-decoration: none;
            margin-left: 20px;
            font-size: 0.9rem;
        }
        .admin-main {
            flex: 1;
            display: flex;
            min-height: 0;
        }
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
        h1 {
            font-size: 1.6rem;
            margin-top: 0;
            margin-bottom: 20px;
        }

        /* Idea generator styles (from tshirt_idea_generator.php) */
        .page-shell {
            width: 100%;
            max-width: 1200px;
            background: radial-gradient(circle at top left, #111827 0, #020617 55%, #020617 100%);
            border-radius: 24px;
            padding: 24px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
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
            margin-bottom: 12px;
        }
        .page-title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: #e5e7eb;
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
            color: #e5e7eb;
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
        .input-text {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(75, 85, 99, 0.9);
            background: rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            font-size: 0.9rem;
            outline: none;
        }
        .input-text:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.5);
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
        .hint {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .error-banner {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #fecaca;
            background: rgba(127, 29, 29, 0.6);
            border-radius: 10px;
            padding: 8px 10px;
            border: 1px solid rgba(248, 113, 113, 0.7);
        }
        .idea-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .idea-card {
            background: radial-gradient(circle at top, #111827 0, #020617 60%);
            border-radius: 16px;
            border: 1px solid rgba(55, 65, 81, 0.9);
            padding: 14px;
            color: #e5e7eb;
        }
        .idea-card h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .idea-card .colors {
            display: flex;
            gap: 6px;
            margin: 6px 0;
        }
        .color-swatch {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid rgba(55, 65, 81, 0.8);
        }
        .idea-actions {
            margin-top: 8px;
            display: flex;
            gap: 10px;
        }
        .idea-actions a {
            font-size: 0.75rem;
            color: #06b6d4;
            text-decoration: none;
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
        <?php
            // Determine the current page for navigation highlighting
            $currentScript = basename($_SERVER['SCRIPT_NAME']);
        ?>
        <aside class="admin-sidebar">
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <!-- Unified Design Lab navigation -->
            <a href="admin_design_lab.php" class="<?php echo ($currentScript === 'admin_design_lab.php' || $currentScript === 'admin_design_lab_gemini.php') ? 'active' : ''; ?>">Design Lab</a>
            <!-- Removed separate Gemini Lab link -->
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_create.php" class="<?php echo $currentScript === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
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
            </div>
        </section>
    </div>
</div>
<script>
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
</body>
</html>