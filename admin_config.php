<?php
/*
 * Admin Configuration Page
 *
 * This page allows administrators to view and update API keys and model
 * settings used throughout the Merch Admin suite. Configuration values
 * are stored in config.json and loaded via config.php. Updating the
 * values here will update the JSON file and take effect on subsequent
 * page loads. The page uses the same admin layout as other pages.
 */

// Load current configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$message = '';
// Handle form submission to update configuration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newConfig = $config;
    $newConfig['openai_api_key']    = trim($_POST['openai_api_key'] ?? '');
    $newConfig['gemini_api_key']    = trim($_POST['gemini_api_key'] ?? '');
    $newConfig['openai_model']      = trim($_POST['openai_model'] ?? '');
    $newConfig['gemini_model']      = trim($_POST['gemini_model'] ?? '');
    $newConfig['openai_chat_model'] = trim($_POST['openai_chat_model'] ?? '');
    // Add vector API key
    $newConfig['vector_api_key']    = trim($_POST['vector_api_key'] ?? '');
    // Persist the updated configuration
    $configPath = __DIR__ . '/config.json';
    if (@file_put_contents($configPath, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        // Reload the config variable for this page
        $config   = $newConfig;
        $message  = 'Configuration updated successfully.';
    } else {
        $message  = 'Failed to write configuration file. Check file permissions.';
    }
}

// Determine current script for nav highlighting
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$createPages    = [
    'admin_create.php',
    'admin_logo_creator.php',
    'admin_poster_creator.php',
    'admin_flyer_creator.php',
    'admin_social_creator.php',
    'admin_product_photo_creator.php'
];
$isCreate       = in_array($currentScript, $createPages);
$pageTitle = 'Admin — Configuration';
$activeSection = 'config';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Configuration</h1>
            <?php if ($message): ?>
                <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <div class="config-card">
                <h2>API Keys and Models</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="openai_api_key">OpenAI API Key</label>
                        <input type="text" id="openai_api_key" name="openai_api_key" value="<?php echo htmlspecialchars($config['openai_api_key'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="openai_model">OpenAI Image Model</label>
                        <input type="text" id="openai_model" name="openai_model" value="<?php echo htmlspecialchars($config['openai_model'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="openai_chat_model">OpenAI Chat Model</label>
                        <input type="text" id="openai_chat_model" name="openai_chat_model" value="<?php echo htmlspecialchars($config['openai_chat_model'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gemini_api_key">Gemini API Key</label>
                        <input type="text" id="gemini_api_key" name="gemini_api_key" value="<?php echo htmlspecialchars($config['gemini_api_key'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gemini_model">Gemini Image Model</label>
                        <input type="text" id="gemini_model" name="gemini_model" value="<?php echo htmlspecialchars($config['gemini_model'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="vector_api_key">Vector API Key</label>
                        <input type="text" id="vector_api_key" name="vector_api_key" value="<?php echo htmlspecialchars($config['vector_api_key'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn-primary">Save Settings</button>
                </form>
            </div>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
