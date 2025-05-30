<?php
/*
Plugin Name: OpenAI Chat Assistant
Description: Adds a chat interface to interact with OpenAI Assistants on your WordPress site, with a 5-message limit and 24-hour restriction per IP.
Version: 1.9.37
Author: Waiheke & Grok
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Create/update the chat messages table on plugin activation
if (!function_exists('oca_chat_activate')) {
    function oca_chat_activate() {
        oca_create_tables();
        oca_migrate_tables();
    }
    register_activation_hook(__FILE__, 'oca_chat_activate');
}

// Function to create tables
if (!function_exists('oca_create_tables')) {
    function oca_create_tables() {
        global $wpdb;

        if (!isset($wpdb)) {
            error_log('OpenAI Chat Assistant: $wpdb not available during table creation.');
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        // Table for storing message pairs
        $messages_table = $wpdb->prefix . 'chat_messages';
        $messages_sql = "CREATE TABLE IF NOT EXISTS $messages_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            assistant_name VARCHAR(50) NOT NULL,
            user_message TEXT NOT NULL,
            assistant_message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        if (file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            error_log('OpenAI Chat Assistant: Attempting to create tables...');
            dbDelta($messages_sql);

            // Verify table creation
            $messages_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$messages_table'");
            if ($messages_table_exists !== $messages_table) {
                error_log('OpenAI Chat Assistant: Failed to create wp_chat_messages table.');
            } else {
                error_log('OpenAI Chat Assistant: wp_chat_messages table created successfully.');
            }

            // Test database insert to confirm table is writable
            if ($messages_table_exists === $messages_table) {
                $test_insert = $wpdb->insert(
                    $messages_table,
                    array(
                        'ip_address' => 'test_ip',
                        'session_id' => 'test_session',
                        'assistant_name' => 'Hellen',
                        'user_message' => 'Test user message',
                        'assistant_message' => 'Test assistant message',
                        'created_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s')
                );
                if ($test_insert === false) {
                    error_log('OpenAI Chat Assistant: Test insert failed: ' . $wpdb->last_error);
                    error_log('OpenAI Chat Assistant: Test insert query: ' . $wpdb->last_query);
                } else {
                    error_log('OpenAI Chat Assistant: Test insert successful.');
                    // Clean up test data
                    $wpdb->delete($messages_table, array('ip_address' => 'test_ip', 'session_id' => 'test_session'));
                }
            }
        } else {
            error_log('OpenAI Chat Assistant: Could not include upgrade.php during table creation.');
        }
    }
}

// Function to migrate existing tables
if (!function_exists('oca_migrate_tables')) {
    function oca_migrate_tables() {
        global $wpdb;

        $messages_table = $wpdb->prefix . 'chat_messages';

        // Check if the assistant_name column exists
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $messages_table LIKE 'assistant_name'");
        if (empty($columns)) {
            error_log('OpenAI Chat Assistant: Adding assistant_name column to wp_chat_messages table...');
            $alter_sql = "ALTER TABLE $messages_table ADD COLUMN assistant_name VARCHAR(50) NOT NULL AFTER session_id";
            $result = $wpdb->query($alter_sql);

            if ($result === false) {
                error_log('OpenAI Chat Assistant: Failed to add assistant_name column: ' . $wpdb->last_error);
            } else {
                error_log('OpenAI Chat Assistant: Successfully added assistant_name column to wp_chat_messages table.');

                // Update existing rows with a default assistant_name (Hellen)
                $wpdb->update(
                    $messages_table,
                    array('assistant_name' => 'Hellen'),
                    array('assistant_name' => ''),
                    array('%s'),
                    array()
                );
                error_log('OpenAI Chat Assistant: Updated existing rows with default assistant_name "Hellen".');
            }
        } else {
            error_log('OpenAI Chat Assistant: assistant_name column already exists in wp_chat_messages table.');
        }
    }
}

// Enqueue styles
if (!function_exists('oca_chat_enqueue_styles')) {
    function oca_chat_enqueue_styles() {
        $css_url = plugin_dir_url(__FILE__) . 'css/openai-chat.css';
        wp_enqueue_style('openai-chat-style', $css_url, array(), '1.9.37');
        error_log('OpenAI Chat Assistant: Enqueuing CSS at ' . $css_url);
    }
    add_action('wp_enqueue_scripts', 'oca_chat_enqueue_styles');
}

// Add settings page and messages page
if (!function_exists('oca_chat_register_settings')) {
    function oca_chat_register_settings() {
        // Main settings page
        add_menu_page(
            'OpenAI Chat Settings',
            'OpenAI Chat',
            'manage_options',
            'openai-chat-settings',
            'oca_chat_settings_page',
            'dashicons-admin-comments',
            81
        );

        // Submenu for settings
        add_submenu_page(
            'openai-chat-settings',
            'OpenAI Chat Settings',
            'Settings',
            'manage_options',
            'openai-chat-settings',
            'oca_chat_settings_page'
        );

        // Submenu for messages
        add_submenu_page(
            'openai-chat-settings',
            'Chat Messages',
            'Chat Messages',
            'manage_options',
            'openai-chat-messages',
            'oca_chat_messages_page'
        );

        // Ensure tables exist on admin page load
        oca_create_tables();
        oca_migrate_tables();
    }
    add_action('admin_menu', 'oca_chat_register_settings');
}

if (!function_exists('oca_chat_settings_init')) {
    function oca_chat_settings_init() {
        register_setting('openai_chat_options_group', 'openai_chat_api_key', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('openai_chat_options_group', 'openai_chat_assistant_id_hellen', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('openai_chat_options_group', 'openai_chat_assistant_id_george', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));

        add_settings_section(
            'openai_chat_settings_section',
            'OpenAI Configuration',
            null,
            'openai-chat-settings'
        );

        add_settings_field(
            'openai_chat_api_key',
            'OpenAI API Key',
            'oca_chat_api_key_field',
            'openai-chat-settings',
            'openai_chat_settings_section'
        );

        add_settings_field(
            'openai_chat_assistant_id_hellen',
            'Assistant ID (Hellen, Vocational Coach)',
            'oca_chat_assistant_id_hellen_field',
            'openai-chat-settings',
            'openai_chat_settings_section'
        );

        add_settings_field(
            'openai_chat_assistant_id_george',
            'Assistant ID (George, Goal’s Coach)',
            'oca_chat_assistant_id_george_field',
            'openai-chat-settings',
            'openai_chat_settings_section'
        );
    }
    add_action('admin_init', 'oca_chat_settings_init');
}

if (!function_exists('oca_chat_api_key_field')) {
    function oca_chat_api_key_field() {
        $value = get_option('openai_chat_api_key', '');
        echo '<input type="text" name="openai_chat_api_key" value="' . esc_attr($value) . '" size="50" />';
    }
}

if (!function_exists('oca_chat_assistant_id_hellen_field')) {
    function oca_chat_assistant_id_hellen_field() {
        $value = get_option('openai_chat_assistant_id_hellen', '');
        echo '<input type="text" name="openai_chat_assistant_id_hellen" value="' . esc_attr($value) . '" size="50" />';
    }
}

if (!function_exists('oca_chat_assistant_id_george_field')) {
    function oca_chat_assistant_id_george_field() {
        $value = get_option('openai_chat_assistant_id_george', '');
        echo '<input type="text" name="openai_chat_assistant_id_george" value="' . esc_attr($value) . '" size="50" />';
    }
}

if (!function_exists('oca_chat_settings_page')) {
    function oca_chat_settings_page() {
        ?>
        <div class="wrap">
            <h1>OpenAI Chat Assistant Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('openai_chat_options_group');
                do_settings_sections('openai-chat-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('oca_chat_messages_page')) {
    function oca_chat_messages_page() {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'chat_messages';

        // Pagination settings
        $messages_per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $messages_per_page;

        // Get total messages
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM $messages_table");
        error_log('OpenAI Chat Assistant: Total messages in wp_chat_messages: ' . $total_messages);

        $total_pages = ceil($total_messages / $messages_per_page);

        // Fetch messages for the current page
        $messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $messages_table ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $messages_per_page,
                $offset
            )
        );
        error_log('OpenAI Chat Assistant: Fetched messages: ' . print_r($messages, true));

        ?>
        <div class="wrap">
            <h1>Chat Messages</h1>
            <?php if ($total_messages == 0) : ?>
                <p>No messages have been recorded yet. Start a conversation to see messages here.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Assistant Name</th>
                            <th>User</th>
                            <th>Assistant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($messages) : ?>
                            <?php foreach ($messages as $message) : ?>
                                <tr>
                                    <td><?php echo esc_html($message->assistant_name); ?></td>
                                    <td><?php echo esc_html($message->user_message); ?></td>
                                    <td><?php echo esc_html($message->assistant_message); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="3">No messages found for this page.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                // Pagination links
                if ($total_pages > 1) {
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('« Previous'),
                        'next_text' => __('Next »'),
                        'total' => $total_pages,
                        'current' => $current_page,
                    ));
                    echo '</div></div>';
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Function to store message pair in the database
if (!function_exists('oca_store_message_pair')) {
    function oca_store_message_pair($user_ip, $session_id, $assistant_name, $user_message, $assistant_response) {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'chat_messages';

        // Ensure the table exists before attempting to insert
        oca_create_tables();
        oca_migrate_tables();

        error_log('OpenAI Chat Assistant: Storing message pair - IP: ' . $user_ip . ', Session: ' . $session_id . ', Assistant: ' . $assistant_name);
        error_log('OpenAI Chat Assistant: User message: ' . $user_message);
        error_log('OpenAI Chat Assistant: Assistant response: ' . $assistant_response);

        $insert_result = $wpdb->insert(
            $messages_table,
            array(
                'ip_address' => $user_ip,
                'session_id' => $session_id,
                'assistant_name' => $assistant_name,
                'user_message' => $user_message,
                'assistant_message' => $assistant_response ?: 'No response received from assistant.',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($insert_result === false) {
            error_log('OpenAI Chat Assistant: Failed to insert message pair: ' . $wpdb->last_error);
            error_log('OpenAI Chat Assistant: Last query: ' . $wpdb->last_query);
        } else {
            error_log('OpenAI Chat Assistant: Successfully inserted message pair for IP ' . $user_ip . ' and session ' . $session_id);
        }
    }
}

// Register the shortcode
if (!function_exists('oca_chat_shortcode')) {
    function oca_chat_shortcode() {
        // Ensure tables exist before proceeding
        oca_create_tables();
        oca_migrate_tables();

        $api_key = get_option('openai_chat_api_key');
        $assistant_id_hellen = get_option('openai_chat_assistant_id_hellen');
        $assistant_id_george = get_option('openai_chat_assistant_id_george');

        if (empty($api_key) || (empty($assistant_id_hellen) && empty($assistant_id_george))) {
            return '<p>Please configure the OpenAI API Key and at least one Assistant ID in the settings.</p>';
        }

        ob_start();
        $user_ip = $_SERVER['REMOTE_ADDR'];

        // Check message limit using transients
        $transient_key = 'chat_limit_' . md5($user_ip);
        $message_count = get_transient($transient_key);
        $cookie_name = 'openai_chat_usage';
        $cookie_value = isset($_COOKIE[$cookie_name]) ? intval($_COOKIE[$cookie_name]) : 0;

        // Sync transient with cookie if needed
        if ($message_count === false) {
            $message_count = $cookie_value;
            set_transient($transient_key, $message_count, 24 * HOUR_IN_SECONDS);
        } else {
            // Update cookie to match transient
            if ($cookie_value !== $message_count) {
                setcookie($cookie_name, $message_count, time() + (24 * 60 * 60), '/');
            }
        }

        $is_limit_reached = $message_count >= 5;
        $selected_coach = !empty($assistant_id_hellen) ? 'Hellen' : 'George';

        $initial_placeholder = $message_count == 0 ? 
            "What's your question? Ask about careers, goals, or anything else.." : 
            "Type your message here...";

        // Define avatar URLs
        $hellen_avatar_url = plugin_dir_url(__FILE__) . 'images/hellen-avatar.jpg';
        $george_avatar_url = plugin_dir_url(__FILE__) . 'images/george-avatar.jpg';

        // Inline styles with !important to force application
        $inline_styles = '
            <style>
                #chat-container {
                    max-width: 900px !important;
                    margin: 30px auto !important;
                    font-family: "Roboto", -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif !important;
                    background: #fff !important;
                    border-radius: 12px !important;
                    padding: 20px !important;
                    border: 1px solid #e0e0e0 !important;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
                    color: #263238 !important;
                }
                #chat-container #chat-output {
                    min-height: 300px !important;
                    max-height: 500px !important;
                    border: 1px solid #e0e0e0 !important;
                    border-radius: 8px !important;
                    padding: 15px !important;
                    background: #f5f5f5 !important;
                    overflow-y: auto !important;
                    margin-bottom: 20px !important;
                    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05) !important;
                }
                #chat-container #chat-output::-webkit-scrollbar {
                    width: 6px !important;
                }
                #chat-container #chat-output::-webkit-scrollbar-track {
                    background: #f0f0f0 !important;
                    border-radius: 10px !important;
                }
                #chat-container #chat-output::-webkit-scrollbar-thumb {
                    background: #b0bec5 !important;
                    border-radius: 10px !important;
                }
                #chat-container #chat-output::-webkit-scrollbar-thumb:hover {
                    background: #90a4ae !important;
                }
                #chat-container .message-bubble {
                    max-width: 70% !important;
                    margin: 10px 0 !important;
                    padding: 12px 18px !important;
                    border-radius: 15px !important;
                    line-height: 1.6 !important;
                    position: relative !important;
                }
                #chat-container .user-message {
                    background: #1e88e5 !important;
                    color: #fff !important;
                    margin-left: auto !important;
                    border-bottom-right-radius: 5px !important;
                }
                #chat-container .assistant-message {
                    background: #e0e0e0 !important;
                    color: #263238 !important;
                    margin-right: auto !important;
                    border-bottom-left-radius: 5px !important;
                }
                #chat-container .message-bubble strong {
                    font-weight: 600 !important;
                    display: block !important;
                    margin-bottom: 5px !important;
                }
                #chat-container .typing-indicator {
                    display: flex !important;
                    gap: 5px !important;
                    margin: 10px 0 !important;
                    padding: 12px 18px !important;
                    max-width: 70% !important;
                    margin-right: auto !important;
                    background: #e0e0e0 !important;
                    border-radius: 15px !important;
                    border-bottom-left-radius: 5px !important;
                }
                #chat-container .typing-indicator .dot {
                    width: 8px !important;
                    height: 8px !important;
                    background: #666 !important;
                    border-radius: 50% !important;
                    animation: typing 1.2s infinite !important;
                }
                #chat-container .typing-indicator .dot:nth-child(2) {
                    animation-delay: 0.2s !important;
                }
                #chat-container .typing-indicator .dot:nth-child(3) {
                    animation-delay: 0.4s !important;
                }
                @keyframes typing {
                    0%, 100% { opacity: 0.3; transform: translateY(0); }
                    50% { opacity: 1; transform: translateY(-3px); }
                }
                #chat-container #chat-input-area {
                    margin-bottom: 20px !important;
                }
                #chat-container #chat-form {
                    display: flex !important;
                    align-items: center !important;
                    gap: 10px !important;
                    background: #f5f5f5 !important;
                    border: 1px solid #e0e0e0 !important;
                    border-radius: 8px !important;
                    padding: 5px 10px !important;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
                }
                #chat-container #chat-input {
                    flex: 1 !important;
                    padding: 8px 10px !important;
                    border: none !important;
                    border-radius: 4px !important;
                    font-size: 14px !important;
                    background: transparent !important;
                    color: #263238 !important;
                    outline: none !important;
                }
                #chat-container #chat-input:focus {
                    outline: none !important;
                }
                #chat-container #chat-input::placeholder {
                    color: #b0bec5 !important;
                }
                #chat-container #assistant-selector {
                    position: relative !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: 5px !important;
                }
                #chat-container #assistant-selector .avatar {
                    width: 24px !important;
                    height: 24px !important;
                    border-radius: 50% !important;
                    object-fit: cover !important;
                }
                #chat-container #assistant-selector .selector-button {
                    display: flex !important;
                    align-items: center !important;
                    gap: 5px !important;
                    padding: 6px 0 !important;
                    background: none !important;
                    border: none !important;
                    font-size: 13px !important;
                    color: #666 !important;
                    cursor: pointer !important;
                    transition: color 0.2s !important;
                }
                #chat-container #assistant-selector .selector-button:hover {
                    color: #333 !important;
                }
                #chat-container #assistant-selector .selector-button::after {
                    content: "▼" !important;
                    font-size: 8px !important;
                    color: #666 !important;
                }
                #chat-container #assistant-selector .selector-menu {
                    display: none !important;
                    position: absolute !important;
                    top: 100% !important;
                    right: 0 !important;
                    background: #fff !important;
                    border: none !important;
                    border-radius: 4px !important;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
                    z-index: 10 !important;
                    min-width: 150px !important;
                }
                #chat-container #assistant-selector .selector-menu.active {
                    display: block !important;
                }
                #chat-container #assistant-selector .selector-menu div {
                    padding: 8px 12px !important;
                    font-size: 13px !important;
                    color: #333 !important;
                    cursor: pointer !important;
                    transition: background 0.2s !important;
                }
                #chat-container #assistant-selector .selector-menu div:hover {
                    background: #f0f0f0 !important;
                }
                #chat-container #send-button {
                    background: #b0bec5 !important;
                    border: none !important;
                    padding: 8px !important;
                    cursor: pointer !important;
                    color: #fff !important;
                    transition: background 0.2s !important;
                    width: 36px !important;
                    height: 36px !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    border-radius: 50% !important;
                }
                #chat-container #send-button.active {
                    background: #1e88e5 !important;
                }
                #chat-container #send-button:disabled {
                    cursor: not-allowed !important;
                    background: #b0bec5 !important;
                }
                #chat-container #send-button svg {
                    width: 24px !important;
                    height: 24px !important;
                }
                #chat-container .limit-message {
                    background: #f5f5f5 !important;
                    border: 1px solid #e0e0e0 !important;
                    border-radius: 8px !important;
                    padding: 20px !important;
                    text-align: center !important;
                    color: #263238 !important;
                    line-height: 1.6 !important;
                }
                #chat-container .limit-message strong {
                    font-size: 18px !important;
                    font-weight: 600 !important;
                    display: block !important;
                    margin-bottom: 5px !important;
                    color: #263238 !important;
                }
                #chat-container .limit-message p {
                    margin: 0 0 15px !important;
                    font-size: 15px !important;
                }
                #chat-container .trial-button {
                    display: inline-block !important;
                    padding: 10px 20px !important;
                    background: #1e88e5 !important;
                    color: #fff !important;
                    border-radius: 8px !important;
                    text-decoration: none !important;
                    font-weight: 500 !important;
                }
                #chat-container .trial-button:hover {
                    background: #1565c0 !important;
                }
                #chat-container #message-count {
                    margin-top: 10px !important;
                    font-size: 14px !important;
                    color: #b0bec5 !important;
                    text-align: center !important;
                }
            </style>
        ';
        ?>
        <?php echo $inline_styles; ?>
        <div id="chat-container">
            <div id="chat-output">
                <!-- No initial message -->
            </div>
            <div id="chat-input-area">
                <?php if ($is_limit_reached) { ?>
                    <div class="system-message limit-message">
                        <p>You have used the 5 messages available for today. Continue with a free trial to keep exploring, or come back tomorrow for 5 more messages.</p>
                        <a href="https://wai.waiheke.ai" target="_blank" class="trial-button">Start Free Trial</a>
                    </div>
                <?php } else { ?>
                    <form id="chat-form">
                        <input type="text" id="chat-input" placeholder="<?php echo esc_attr($initial_placeholder); ?>" required />
                        <div id="assistant-selector">
                            <img src="<?php echo !empty($assistant_id_hellen) ? esc_url($hellen_avatar_url) : esc_url($george_avatar_url); ?>" alt="Coach Avatar" class="avatar" id="coach-avatar" />
                            <div class="selector-button" id="selector-button">
                                <span id="selected-assistant">
                                    <?php echo !empty($assistant_id_hellen) ? 'Hellen' : 'George'; ?>
                                </span>
                            </div>
                            <div class="selector-menu" id="selector-menu">
                                <?php if (!empty($assistant_id_hellen)) { ?>
                                    <div data-value="hellen" data-name="Hellen" data-avatar="<?php echo esc_url($hellen_avatar_url); ?>">Hellen, Vocational Coach</div>
                                <?php } ?>
                                <?php if (!empty($assistant_id_george)) { ?>
                                    <div data-value="george" data-name="George" data-avatar="<?php echo esc_url($george_avatar_url); ?>">George, Goal’s Coach</div>
                                <?php } ?>
                            </div>
                        </div>
                        <button type="submit" id="send-button" disabled>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 19V5M5 12l7-7 7 7"/>
                            </svg>
                        </button>
                    </form>
                <?php } ?>
            </div>
            <p id="message-count">
                Messages remaining: <span id="remaining"><?php echo $is_limit_reached ? 0 : (5 - $message_count); ?></span>
            </p>
        </div>

        <script>
            console.log('Chat plugin loaded. Initial message count (transient):', <?php echo $message_count; ?>);
            const chatForm = document.getElementById('chat-form');
            const sendButton = document.getElementById('send-button');
            const chatInput = document.getElementById('chat-input');
            const selectorButton = document.getElementById('selector-button');
            const selectorMenu = document.getElementById('selector-menu');
            const selectedAssistantSpan = document.getElementById('selected-assistant');
            const coachAvatar = document.getElementById('coach-avatar');
            let selectedAssistant = '<?php echo !empty($assistant_id_hellen) ? 'hellen' : 'george'; ?>';
            let messageCount = <?php echo $message_count; ?>;
            const isLimitReached = <?php echo $is_limit_reached ? 'true' : 'false'; ?>;

            // Toggle selector menu
            if (selectorButton && selectorMenu) {
                selectorButton.addEventListener('click', function() {
                    selectorMenu.classList.toggle('active');
                });

                // Close menu when clicking outside
                document.addEventListener('click', function(e) {
                    if (!selectorButton.contains(e.target) && !selectorMenu.contains(e.target)) {
                        selectorMenu.classList.remove('active');
                    }
                });

                // Handle assistant selection
                selectorMenu.querySelectorAll('div').forEach(item => {
                    item.addEventListener('click', function() {
                        selectedAssistant = this.getAttribute('data-value');
                        selectedAssistantSpan.textContent = this.getAttribute('data-name');
                        coachAvatar.src = this.getAttribute('data-avatar');
                        selectorMenu.classList.remove('active');
                        console.log('Selected assistant:', selectedAssistant);
                        // Clear chat output when switching assistants
                        document.getElementById('chat-output').innerHTML = '';
                    });
                });
            }

            // Enable/disable send button based on input
            if (chatInput && sendButton) {
                chatInput.addEventListener('input', function() {
                    if (chatInput.value.trim().length > 0 && !isLimitReached) {
                        sendButton.classList.add('active');
                        sendButton.disabled = false;
                    } else {
                        sendButton.classList.remove('active');
                        sendButton.disabled = true;
                    }
                });
            }

            if (chatForm && sendButton && !isLimitReached) {
                chatForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    console.log('Form submitted successfully');

                    const input = document.getElementById('chat-input').value;
                    const outputDiv = document.getElementById('chat-output');
                    const chatInput = document.getElementById('chat-input');
                    const chatInputArea = document.getElementById('chat-input-area');
                    const messageCountDiv = document.getElementById('message-count');

                    if (messageCount >= 5) {
                        console.log('Message limit reached');
                        chatInputArea.innerHTML = '<div class="system-message limit-message"><p>You have used the 5 messages available for today. Continue with a free trial to keep exploring, or come back tomorrow for 5 more messages.</p><a href="https://wai.waiheke.ai" target="_blank" class="trial-button">Start Free Trial</a></div>';
                        document.getElementById('remaining').textContent = 0;
                        return;
                    }

                    console.log('Adding loading styles to button');
                    sendButton.disabled = true;
                    sendButton.classList.remove('active');

                    const userMessage = document.createElement('div');
                    userMessage.className = 'user-message message-bubble';
                    userMessage.innerHTML = '<strong>You:</strong> ' + input;
                    outputDiv.appendChild(userMessage);
                    userMessage.classList.add('fade-in');
                    document.getElementById('chat-input').value = '';
                    sendButton.disabled = true;
                    sendButton.classList.remove('active');

                    if (messageCount === 0) {
                        chatInput.placeholder = "Type your message here...";
                    }

                    // Add typing indicator
                    const typingIndicator = document.createElement('div');
                    typingIndicator.className = 'typing-indicator';
                    typingIndicator.innerHTML = '<div class="dot"></div><div class="dot"></div><div class="dot"></div>';
                    outputDiv.appendChild(typingIndicator);
                    outputDiv.scrollTop = outputDiv.scrollHeight;

                    // Simulate typing delay (1.5 seconds) before fetching response
                    setTimeout(() => {
                        fetch('<?php echo rest_url('openai_chat/v1/talk'); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                            },
                            body: JSON.stringify({ message: input, assistant: selectedAssistant })
                        })
                        .then(response => response.json())
                        .then(data => {
                            // Remove typing indicator
                            typingIndicator.remove();

                            if (data.response) {
                                console.log('Assistant response received:', data.response);
                                const assistantMessage = document.createElement('div');
                                assistantMessage.className = 'assistant-message message-bubble';
                                assistantMessage.innerHTML = '<strong>' + (selectedAssistant === 'hellen' ? 'Hellen' : 'George') + ':</strong> ' + data.response;
                                outputDiv.appendChild(assistantMessage);
                                assistantMessage.classList.add('fade-in');
                                outputDiv.scrollTop = outputDiv.scrollHeight;

                                // Increment message count and update display
                                messageCount++;
                                setCookie('openai_chat_usage', messageCount, 24);
                                setTransient('<?php echo $transient_key; ?>', messageCount, 24 * 60 * 60);
                                const remainingMessages = 5 - messageCount;
                                document.getElementById('remaining').textContent = remainingMessages;

                                if (messageCount >= 5) {
                                    console.log('Message limit reached after response');
                                    chatInputArea.innerHTML = '<div class="system-message limit-message"><p>You have used the 5 messages available for today. Continue with a free trial to keep exploring, or come back tomorrow for 5 more messages.</p><a href="https://wai.waiheke.ai" target="_blank" class="trial-button">Start Free Trial</a></div>';
                                    document.getElementById('remaining').textContent = 0;
                                }
                            } else {
                                console.error('Error in response:', data.message);
                                const errorMessage = document.createElement('div');
                                errorMessage.className = 'assistant-message message-bubble';
                                errorMessage.innerHTML = '<strong>' + (selectedAssistant === 'hellen' ? 'Hellen' : 'George') + ':</strong> ' + data.message;
                                outputDiv.appendChild(errorMessage);
                                errorMessage.classList.add('fade-in');
                            }
                        })
                        .catch(error => {
                            // Remove typing indicator on error
                            typingIndicator.remove();

                            console.error('Fetch error:', error);
                            const errorMessage = document.createElement('div');
                            errorMessage.className = 'assistant-message message-bubble';
                            errorMessage.innerHTML = '<strong>' + (selectedAssistant === 'hellen' ? 'Hellen' : 'George') + ':</strong> Error connecting to the assistant: ' + error.message;
                            outputDiv.appendChild(errorMessage);
                            errorMessage.classList.add('fade-in');
                        });
                    }, 1500); // 1.5-second delay for typing animation
                });
            } else {
                console.error('Chat form or send button not found in the DOM, or message limit reached');
            }

            function setCookie(name, value, hours) {
                const date = new Date();
                date.setTime(date.getTime() + (hours * 60 * 60 * 1000));
                document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/';
            }

            function getCookie(name) {
                const value = '; ' + document.cookie;
                const parts = value.split('; ' + name + '=');
                if (parts.length === 2) return parts.pop().split(';').shift();
            }

            function setTransient(name, value, expiration) {
                // This is handled server-side in PHP, but we log it here for clarity
                console.log('Setting transient:', name, 'to', value, 'with expiration', expiration);
            }
        </script>
        <style>
            @keyframes spin {
                0% { transform: translate(-50%, -50%) rotate(0deg); }
                100% { transform: translate(-50%, -50%) rotate(360deg); }
            }
        </style>
        <?php
        return ob_get_clean();
    }
    add_shortcode('openai_chat', 'oca_chat_shortcode');
}

// Register REST API endpoint
if (!function_exists('oca_chat_handle_request')) {
    function oca_chat_handle_request($request) {
        // Ensure tables exist before proceeding
        oca_create_tables();
        oca_migrate_tables();

        $api_key = get_option('openai_chat_api_key');
        $assistant_id_hellen = get_option('openai_chat_assistant_id_hellen');
        $assistant_id_george = get_option('openai_chat_assistant_id_george');

        error_log('OpenAI Chat Assistant: Starting oca_chat_handle_request');

        if (empty($api_key)) {
            error_log('OpenAI Chat Assistant: API key not configured.');
            return array('response' => false, 'message' => 'API key not configured.');
        }

        $message = sanitize_text_field($request->get_param('message'));
        $selected_assistant = sanitize_text_field($request->get_param('assistant'));
        $assistant_id = $selected_assistant === 'george' ? $assistant_id_george : $assistant_id_hellen;
        $assistant_name = $selected_assistant === 'george' ? 'George' : 'Hellen';

        error_log('OpenAI Chat Assistant: Received message: ' . $message . ', Assistant: ' . $selected_assistant . ', Assistant ID: ' . $assistant_id);

        if (empty($assistant_id)) {
            error_log('OpenAI Chat Assistant: Assistant ID not configured for ' . $selected_assistant);
            return array('response' => false, 'message' => 'Assistant ID not configured for the selected coach.');
        }

        $user_ip = $_SERVER['REMOTE_ADDR'];

        // Check message limit using transients
        $transient_key = 'chat_limit_' . md5($user_ip);
        $message_count = get_transient($transient_key);
        if ($message_count === false) {
            $message_count = 0;
        }

        if ($message_count >= 5) {
            error_log('OpenAI Chat Assistant: Message limit reached for IP ' . $user_ip);
            return array('response' => false, 'message' => '<p>You have used the 5 messages available for today. Continue with a free trial to keep exploring, or come back tomorrow for 5 more messages.</p><a href="https://wai.waiheke.ai" target="_blank" class="trial-button">Start Free Trial</a>');
        }

        // Generate or retrieve session ID
        $session_cookie_name = 'openai_chat_session';
        if (!isset($_COOKIE[$session_cookie_name])) {
            $session_id = wp_generate_uuid4();
            setcookie($session_cookie_name, $session_id, time() + (24 * 60 * 60), '/');
        } else {
            $session_id = $_COOKIE[$session_cookie_name];
        }

        error_log('OpenAI Chat Assistant: Session ID: ' . $session_id);

        $thread_id = null;

        // Use OpenAI API to create a thread if needed
        $thread_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2',
            ),
            'body' => json_encode(array()),
            'timeout' => 30,
        );

        error_log('OpenAI Chat Assistant: Creating new thread for IP ' . $user_ip);
        $thread_response = wp_remote_post('https://api.openai.com/v1/threads', $thread_args);

        if (is_wp_error($thread_response)) {
            error_log('OpenAI Chat Assistant: Thread Creation Error: ' . $thread_response->get_error_message());
            oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to create thread - ' . $thread_response->get_error_message());
            return array('response' => false, 'message' => 'Failed to create thread: ' . $thread_response->get_error_message());
        }

        $thread_body = json_decode(wp_remote_retrieve_body($thread_response), true);
        $thread_id = $thread_body['id'] ?? null;

        if (!$thread_id) {
            $error_message = isset($thread_body['error']) ? $thread_body['error']['message'] : 'Unknown error';
            error_log('OpenAI Chat Assistant: Thread Creation Failed: ' . print_r($thread_body, true));
            oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to initialize conversation - ' . $error_message);
            return array('response' => false, 'message' => 'Failed to initialize conversation: ' . $error_message);
        }
        error_log('OpenAI Chat Assistant: Created new thread_id for IP ' . $user_ip . ' (' . $selected_assistant . '): ' . $thread_id);

        $message_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2',
            ),
            'body' => json_encode(array(
                'role' => 'user',
                'content' => $message,
            )),
            'timeout' => 30,
        );

        error_log('OpenAI Chat Assistant: Sending message to thread ' . $thread_id);
        $message_response = wp_remote_post("https://api.openai.com/v1/threads/$thread_id/messages", $message_args);

        if (is_wp_error($message_response)) {
            error_log('OpenAI Chat Assistant: Message Sending Error: ' . $message_response->get_error_message());
            oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to send message - ' . $message_response->get_error_message());
            return array('response' => false, 'message' => 'Failed to send message: ' . $message_response->get_error_message());
        }

        $message_response_body = json_decode(wp_remote_retrieve_body($message_response), true);
        error_log('OpenAI Chat Assistant: Message sent successfully: ' . print_r($message_response_body, true));

        $run_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2',
            ),
            'body' => json_encode(array(
                'assistant_id' => $assistant_id,
            )),
            'timeout' => 30,
        );

        error_log('OpenAI Chat Assistant: Starting run for thread ' . $thread_id);
        $run_response = wp_remote_post("https://api.openai.com/v1/threads/$thread_id/runs", $run_args);

        if (is_wp_error($run_response)) {
            error_log('OpenAI Chat Assistant: Run Error: ' . $run_response->get_error_message());
            oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to process assistant response - ' . $run_response->get_error_message());
            return array('response' => false, 'message' => 'Failed to process assistant response: ' . $run_response->get_error_message());
        }

        $run_body = json_decode(wp_remote_retrieve_body($run_response), true);
        $run_id = $run_body['id'] ?? null;

        if (!$run_id) {
            $error_message = isset($run_body['error']) ? $run_body['error']['message'] : 'Unknown error';
            error_log('OpenAI Chat Assistant: Run Failed: ' . print_r($run_body, true));
            oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to process your request - ' . $error_message);
            return array('response' => false, 'message' => 'Failed to process your request: ' . $error_message);
        }

        error_log('OpenAI Chat Assistant: Run created successfully, Run ID: ' . $run_id);

        $max_attempts = 60;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $status_args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'OpenAI-Beta' => 'assistants=v2',
                ),
                'timeout' => 30,
            );

            error_log('OpenAI Chat Assistant: Checking run status for run ' . $run_id . ', attempt ' . ($attempt + 1));
            $status_response = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/runs/$run_id", $status_args);
            if (is_wp_error($status_response)) {
                error_log('OpenAI Chat Assistant: Run Status Check Error: ' . $status_response->get_error_message());
                oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to check run status - ' . $status_response->get_error_message());
                return array('response' => false, 'message' => 'Failed to check run status: ' . $status_response->get_error_message());
            }

            $status_body = json_decode(wp_remote_retrieve_body($status_response), true);
            error_log('OpenAI Chat Assistant: Run Status Attempt ' . ($attempt + 1) . ': ' . print_r($status_body, true));

            if ($status_body['status'] === 'completed') {
                error_log('OpenAI Chat Assistant: Run completed, retrieving messages for thread ' . $thread_id);
                $messages_response = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/messages", $status_args);
                if (is_wp_error($messages_response)) {
                    error_log('OpenAI Chat Assistant: Messages Retrieval Error: ' . $messages_response->get_error_message());
                    oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Failed to retrieve messages - ' . $messages_response->get_error_message());
                    return array('response' => false, 'message' => 'Failed to retrieve messages: ' . $messages_response->get_error_message());
                }

                $messages_body = json_decode(wp_remote_retrieve_body($messages_response), true);
                error_log('OpenAI Chat Assistant: Messages Retrieved: ' . print_r($messages_body, true));

                $assistant_response = '';
                foreach ($messages_body['data'] as $msg) {
                    if ($msg['role'] === 'assistant') {
                        $content = $msg['content'] ?? [];
                        foreach ($content as $content_item) {
                            if (isset($content_item['type'])) {
                                if ($content_item['type'] === 'text') {
                                    $assistant_response .= $content_item['text']['value'] ?? '';
                                } elseif ($content_item['type'] === 'image_file') {
                                    $assistant_response .= '[Image response received, but cannot display images in this chat.]';
                                } else {
                                    $assistant_response .= '[Unsupported content type: ' . $content_item['type'] . ']';
                                }
                            }
                        }

                        if (empty($assistant_response)) {
                            $assistant_response = 'No response content found.';
                        }
                        break;
                    }
                }

                error_log('OpenAI Chat Assistant: Assistant response: ' . $assistant_response);

                // Store the message pair
                oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, $assistant_response);

                break;
            } elseif (in_array($status_body['status'], ['failed', 'cancelled', 'expired'])) {
                $error_message = $status_body['last_error']['message'] ?? 'Run failed with status: ' . $status_body['status'];
                error_log('OpenAI Chat Assistant: Run Failed with Status: ' . $status_body['status']);
                oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Assistant run failed - ' . $error_message);
                return array('response' => false, 'message' => 'Assistant run failed: ' . $error_message);
            }

            sleep(1);
            $attempt++;
        }

        if (!isset($assistant_response)) {
            error_log('OpenAI Chat Assistant: Assistant took too long to respond or no response was found after ' . $max_attempts . ' seconds.');
            oca_store_message_pair($user_ip, $session_id, $assistant_name, $message, 'Error: Assistant took too long to respond or no response was found after ' . $max_attempts . ' seconds.');
            return array('response' => false, 'message' => 'Assistant took too long to respond or no response was found after ' . $max_attempts . ' seconds.');
        }

        // Increment message count
        $message_count++;
        set_transient($transient_key, $message_count, 24 * HOUR_IN_SECONDS);
        setcookie('openai_chat_usage', $message_count, time() + (24 * 60 * 60), '/');

        return array('response' => $assistant_response);
    }

    add_action('rest_api_init', function () {
        register_rest_route('openai_chat/v1', '/talk', array(
            'methods' => 'POST',
            'callback' => 'oca_chat_handle_request',
            'permission_callback' => '__return_true'
        ));
    });
}