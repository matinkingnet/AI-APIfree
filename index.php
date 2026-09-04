<?php
// id=telegram= @matiniza
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$apiKey = trim((string)($config['api_key'] ?? ''));
$model  = trim((string)($config['model'] ?? 'gemini-3.6-flash'));

$userFile = __DIR__ . '/user.json';

if (!file_exists($userFile)) {
    file_put_contents($userFile, '[]', LOCK_EX);
}

/*
|--------------------------------------------------------------------------
| API REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=utf-8');

    try {

        /*
         * اول JSON را بخوان
         */
        $rawBody = file_get_contents('php://input');

        $input = json_decode($rawBody, true);

        /*
         * اگر JSON نبود، POST معمولی را بخوان
         */
        if (!is_array($input)) {
            $input = $_POST;
        }

        $message = trim((string)($input['message'] ?? ''));

        $previousInteractionId = trim(
            (string)($input['previous_interaction_id'] ?? '')
        );

        if ($message === '') {
            echo json_encode([
                'success' => false,
                'error'   => 'پیام خالی است.'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($apiKey === '') {
            throw new Exception('API Key در config.php تنظیم نشده است.');
        }

        /*
         * ساخت درخواست Gemini Interactions API
         */
        $payload = [
            'model' => $model,
            'input' => $message,
        ];

        /*
         * ادامه مکالمه
         */
        if ($previousInteractionId !== '') {
            $payload['previous_interaction_id'] = $previousInteractionId;
        }

        $ch = curl_init(
            'https://generativelanguage.googleapis.com/v1beta/interactions'
        );

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],

            CURLOPT_POSTFIELDS => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
            ),

            CURLOPT_TIMEOUT => 120,

            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $response = curl_exec($ch);

        $curlError = curl_error($ch);

        $httpCode = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($response === false) {
            throw new Exception(
                'خطا در اتصال به : ' . $curlError
            );
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new Exception(
                'پاسخ نامعتبر از API دریافت شد.'
            );
        }

        /*
         * خطای API
         */
        if ($httpCode >= 400 || isset($data['error'])) {

            $apiError = $data['error']['message']
                ?? 'خطای ناشناخته از ';

            throw new Exception(
                'Gemini: ' . $apiError
            );
        }

        /*
         * استخراج متن پاسخ
         */
        $answer = '';

        /*
         * ساختار معمول Interactions API
         */
        if (!empty($data['steps']) && is_array($data['steps'])) {

            foreach ($data['steps'] as $step) {

                if (
                    isset($step['type']) &&
                    $step['type'] === 'model_output' &&
                    isset($step['content']) &&
                    is_array($step['content'])
                ) {

                    foreach ($step['content'] as $content) {

                        if (
                            isset($content['type']) &&
                            $content['type'] === 'text' &&
                            isset($content['text'])
                        ) {

                            $answer .= (string)$content['text'];
                        }
                    }
                }
            }
        }

        /*
         * fallback
         */
        if ($answer === '' && !empty($data['outputs'])) {

            foreach ($data['outputs'] as $output) {

                if (
                    isset($output['text']) &&
                    is_string($output['text'])
                ) {

                    $answer .= $output['text'];
                }

                if (
                    isset($output['content']) &&
                    is_array($output['content'])
                ) {

                    foreach ($output['content'] as $content) {

                        if (
                            isset($content['text']) &&
                            is_string($content['text'])
                        ) {

                            $answer .= $content['text'];
                        }
                    }
                }
            }
        }

        /*
         * fallback نهایی برای ساختارهای متفاوت
         */
        if ($answer === '') {

            if (
                isset($data['response']) &&
                is_string($data['response'])
            ) {
                $answer = $data['response'];
            }

            elseif (
                isset($data['text']) &&
                is_string($data['text'])
            ) {
                $answer = $data['text'];
            }
        }

        if (trim($answer) === '') {

            throw new Exception(
                'پاسخ متنی از مدل دریافت نشد.'
            );
        }

        /*
         * ذخیره مکالمه
         */
        $users = [];

        $json = file_get_contents($userFile);

        if ($json !== false && trim($json) !== '') {

            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                $users = $decoded;
            }
        }

        $userId = $_COOKIE['matin_user'] ?? '';

        if ($userId === '') {

            $userId = bin2hex(
                random_bytes(16)
            );

            setcookie(
                'matin_user',
                $userId,
                [
                    'expires'  => time() + 60 * 60 * 24 * 365,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        if (!isset($users[$userId])) {

            $users[$userId] = [
                'created_at' => date('Y-m-d H:i:s'),
                'messages'   => [],
            ];
        }

        $users[$userId]['messages'][] = [
            'role'      => 'user',
            'content'   => $message,
            'created_at'=> date('Y-m-d H:i:s'),
        ];

        $users[$userId]['messages'][] = [
            'role'      => 'assistant',
            'content'   => $answer,
            'created_at'=> date('Y-m-d H:i:s'),
        ];

        file_put_contents(
            $userFile,
            json_encode(
                $users,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ),
            LOCK_EX
        );

        /*
         * پاسخ به JavaScript
         */
        echo json_encode([
            'success' => true,
            'answer'  => $answer,
            'interaction_id' => $data['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (Throwable $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Matin AI</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #080808;
            --panel: #101010;
            --panel-2: #151515;
            --border: rgba(255,255,255,.08);
            --text: #ffffff;
            --muted: #8b8b8b;
            --red: #ff1f35;
            --red-dark: #b90015;
        }

        body {
            font-family:
                Arial,
                Tahoma,
                sans-serif;

            background:
                radial-gradient(
                    circle at 50% -20%,
                    rgba(255,31,53,.15),
                    transparent 40%
                ),
                var(--bg);

            color: var(--text);

            min-height: 100vh;

            overflow: hidden;
        }

        .app {
            height: 100vh;
            display: flex;
        }

        /* SIDEBAR */

        .sidebar {
            width: 260px;
            border-left: 1px solid var(--border);
            background: rgba(12,12,12,.95);
            padding: 20px;

            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px;
        }

        .logo {
            width: 42px;
            height: 42px;

            border-radius: 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            background:
                linear-gradient(
                    135deg,
                    var(--red),
                    #72000c
                );

            font-weight: 900;
            font-size: 18px;

            box-shadow:
                0 0 25px rgba(255,31,53,.25);
        }

        .brand-name {
            font-size: 17px;
            font-weight: 800;
        }

        .brand-sub {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
        }

        .new-chat {
            border: 1px solid rgba(255,31,53,.35);
            background: rgba(255,31,53,.08);
            color: white;

            padding: 13px;
            border-radius: 12px;

            cursor: pointer;

            transition: .2s;

            font-size: 14px;
        }

        .new-chat:hover {
            background: var(--red);
            border-color: var(--red);
        }

        .history-title {
            color: #666;
            font-size: 11px;
            margin-top: 12px;
        }

        .history {
            flex: 1;
            overflow-y: auto;
        }

        .history-item {
            padding: 11px;
            margin-top: 6px;

            border-radius: 9px;

            color: #aaa;

            font-size: 12px;

            cursor: pointer;
        }

        .history-item:hover {
            background: rgba(255,255,255,.05);
            color: white;
        }

        .sidebar-bottom {
            border-top: 1px solid var(--border);
            padding-top: 15px;

            color: #777;
            font-size: 12px;
        }

        /* MAIN */

        .main {
            flex: 1;

            display: flex;
            flex-direction: column;

            min-width: 0;
        }

        .topbar {
            height: 70px;

            border-bottom: 1px solid var(--border);

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 25px;

            background: rgba(8,8,8,.8);
        }

        .status {
            display: flex;
            align-items: center;
            gap: 9px;

            font-size: 13px;
        }

        .dot {
            width: 8px;
            height: 8px;

            border-radius: 50%;

            background: #22c55e;

            box-shadow:
                0 0 12px #22c55e;
        }

        .model {
            color: #777;
            font-size: 11px;
        }

        /* CHAT */

        .chat {
            flex: 1;

            overflow-y: auto;

            padding: 40px 20px 150px;
        }

        .chat-inner {
            max-width: 850px;
            margin: auto;
        }

        .welcome {
            text-align: center;

            margin-top: 8vh;
        }

        .welcome-icon {
            width: 70px;
            height: 70px;

            margin: auto auto 22px;

            border-radius: 22px;

            display: flex;
            align-items: center;
            justify-content: center;

            background:
                linear-gradient(
                    135deg,
                    var(--red),
                    #65000a
                );

            font-size: 28px;
            font-weight: 900;

            box-shadow:
                0 15px 50px rgba(255,31,53,.2);
        }

        .welcome h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .welcome p {
            color: var(--muted);
            font-size: 14px;
        }

        .message {
            display: flex;

            margin-bottom: 28px;

            animation: appear .25s ease;
        }

        @keyframes appear {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.user {
            justify-content: flex-start;
        }

        .message.ai {
            justify-content: flex-end;
        }

        .bubble {
            max-width: 75%;

            padding: 15px 17px;

            border-radius: 16px;

            line-height: 1.8;

            font-size: 14px;

            white-space: pre-wrap;
        }

        .user .bubble {
            background: #1d1d1d;
            border: 1px solid var(--border);
        }

        .ai .bubble {
            background:
                linear-gradient(
                    145deg,
                    rgba(255,31,53,.12),
                    rgba(255,255,255,.025)
                );

            border: 1px solid rgba(255,31,53,.15);
        }

        .typing {
            display: flex;
            gap: 5px;
            padding: 10px;
        }

        .typing span {
            width: 6px;
            height: 6px;

            background: var(--red);

            border-radius: 50%;

            animation: blink 1s infinite;
        }

        .typing span:nth-child(2) {
            animation-delay: .15s;
        }

        .typing span:nth-child(3) {
            animation-delay: .3s;
        }

        @keyframes blink {
            0%,100% {
                opacity: .2;
            }

            50% {
                opacity: 1;
            }
        }

        /* COMPOSER */

        .composer-area {
            position: fixed;

            bottom: 0;
            left: 260px;
            right: 0;

            padding: 20px;

            background:
                linear-gradient(
                    transparent,
                    #080808 35%
                );
        }

        .composer {
            max-width: 850px;
            margin: auto;

            display: flex;
            align-items: flex-end;
            gap: 10px;

            padding: 10px;

            border-radius: 17px;

            background: #111;

            border: 1px solid rgba(255,255,255,.1);

            box-shadow:
                0 -10px 50px rgba(0,0,0,.5);
        }

        textarea {
            flex: 1;

            resize: none;

            border: 0;
            outline: 0;

            background: transparent;

            color: white;

            font-family: inherit;

            font-size: 14px;

            min-height: 48px;
            max-height: 150px;

            padding: 14px;

            direction: rtl;
        }

        textarea::placeholder {
            color: #666;
        }

        #send-button {
            width: 48px;
            height: 48px;

            border: 0;

            border-radius: 13px;

            background: var(--red);

            color: white;

            cursor: pointer;

            font-size: 19px;

            transition: .2s;
        }

        #send-button:hover {
            transform: translateY(-2px);

            box-shadow:
                0 8px 25px rgba(255,31,53,.3);
        }

        #send-button:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none;
        }

        /* MOBILE */

        @media(max-width: 700px) {

            .sidebar {
                display: none;
            }

            .composer-area {
                left: 0;
            }

            .topbar {
                padding: 0 15px;
            }

            .chat {
                padding-top: 25px;
            }

            .welcome h1 {
                font-size: 25px;
            }

            .bubble {
                max-width: 90%;
            }
        }

    </style>

</head>

<body>

<div class="app">

    <aside class="sidebar">

        <div class="brand">

            <div class="logo">
                M
            </div>

            <div>

                <div class="brand-name">
                    Matin AI
                </div>

                <div class="brand-sub">
                    Intelligent Assistant
                </div>

            </div>

        </div>

        <button
            class="new-chat"
            id="new-chat-button"
            type="button"
        >
            + گفتگوی جدید
        </button>

        <div class="history-title">
            گفتگوهای اخیر
        </div>

        <div
            class="history"
            id="history"
        >
        </div>

        <div class="sidebar-bottom">
            Matin AI
            <br>
            <span>Powered by AI</span>
        </div>

    </aside>

    <main class="main">

        <header class="topbar">

            <div class="status">

                <span class="dot"></span>

                <span>
                    سلام رفیق گاردریل نداره مراقب سوال هاباش
                </span>

            </div>

            <div class="model">
                Matin AI • 
            </div>

        </header>

        <section
            class="chat"
            id="chat-messages"
        >

            <div class="chat-inner">

                <div
                    class="welcome"
                    id="welcome"
                >

                    <div class="welcome-icon">
                        M
                    </div>

                    <h1>
                      سلام به هوش متین خوش امدین 
                    </h1>

                    <p>
                        سلام رفیق اینچا هیچ گارد وجود نداره و اینکه دیتابیسم وجود نداره پس از هک جلو گیری فرماید باتشکر توسعه دهنده متین 
                    </p>

                </div>

            </div>

        </section>

        <div class="composer-area">

            <div class="composer">

                <textarea
                    id="message-input"
                    placeholder="پیامت را برای Matin AI بنویس..."
                    rows="1"
                ></textarea>

                <button
                    id="send-button"
                    type="button"
                    title="ارسال"
                >
                    ↑
                </button>

            </div>

        </div>

    </main>

</div>


<script>

const input = document.getElementById('message-input');
const sendButton = document.getElementById('send-button');
const chat = document.getElementById('chat-messages');
const welcome = document.getElementById('welcome');
const newChatButton = document.getElementById('new-chat-button');

let previousInteractionId = null;


/*
|--------------------------------------------------------------------------
| Add message
|--------------------------------------------------------------------------
*/

function addMessage(text, type) {

    if (welcome) {
        welcome.style.display = 'none';
    }

    const wrapper = document.createElement('div');

    wrapper.className =
        'message ' +
        (type === 'user' ? 'user' : 'ai');

    const bubble = document.createElement('div');

    bubble.className = 'bubble';

    bubble.textContent = text;

    wrapper.appendChild(bubble);

    chat.querySelector('.chat-inner').appendChild(wrapper);

    chat.scrollTop = chat.scrollHeight;

    return wrapper;
}


/*
|--------------------------------------------------------------------------
| Loading
|--------------------------------------------------------------------------
*/

function addLoading() {

    const wrapper = document.createElement('div');

    wrapper.className = 'message ai';

    wrapper.id = 'loading-message';

    wrapper.innerHTML = `
        <div class="bubble">
            <div class="typing">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    `;

    chat.querySelector('.chat-inner').appendChild(wrapper);

    chat.scrollTop = chat.scrollHeight;
}


/*
|--------------------------------------------------------------------------
| Remove loading
|--------------------------------------------------------------------------
*/

function removeLoading() {

    const loading =
        document.getElementById('loading-message');

    if (loading) {
        loading.remove();
    }
}


/*
|--------------------------------------------------------------------------
| Send message
|--------------------------------------------------------------------------
*/

async function sendMessage() {

    const message = input.value.trim();

    /*
     * مهم:
     * قبل از ارسال بررسی می‌کنیم که واقعا پیام داریم.
     */

    if (!message) {

        input.focus();

        return;
    }

    /*
     * UI
     */

    addMessage(message, 'user');

    input.value = '';

    input.style.height = '48px';

    sendButton.disabled = true;

    addLoading();

    try {

        /*
         * ارسال به همین index.php
         */

        const response = await fetch(
            window.location.href,
            {
                method: 'POST',

                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },

                body: JSON.stringify({
                    message: message,
                    previous_interaction_id:
                        previousInteractionId
                })
            }
        );

        const data = await response.json();

        removeLoading();

        if (!data.success) {

            addMessage(
                'خطا: ' +
                (data.error || 'خطای نامشخص'),
                'ai'
            );

            return;
        }

        /*
         * ذخیره ID برای ادامه مکالمه
         */

        if (data.interaction_id) {

            previousInteractionId =
                data.interaction_id;
        }

        addMessage(
            data.answer,
            'ai'
        );

    }

    catch (error) {

        removeLoading();

        addMessage(
            'ارتباط با سرور برقرار نشد. مطمئن شو PHP و API Key درست تنظیم شده‌اند.',
            'ai'
        );

        console.error(error);

    }

    finally {

        sendButton.disabled = false;

        input.focus();
    }
}


/*
|--------------------------------------------------------------------------
| Send button
|--------------------------------------------------------------------------
*/

sendButton.addEventListener(
    'click',
    sendMessage
);


/*
|--------------------------------------------------------------------------
| Enter = Send
| Shift + Enter = New line
|--------------------------------------------------------------------------
*/

input.addEventListener(
    'keydown',
    function(event) {

        if (
            event.key === 'Enter' &&
            !event.shiftKey
        ) {

            event.preventDefault();

            sendMessage();
        }
    }
);


/*
|--------------------------------------------------------------------------
| Auto resize textarea
|--------------------------------------------------------------------------
*/

input.addEventListener(
    'input',
    function() {

        this.style.height = '48px';

        this.style.height =
            Math.min(
                this.scrollHeight,
                150
            ) + 'px';
    }
);


/*
|--------------------------------------------------------------------------
| New chat
|--------------------------------------------------------------------------
*/

newChatButton.addEventListener(
    'click',
    function() {

        previousInteractionId = null;

        const inner =
            chat.querySelector('.chat-inner');

        inner.innerHTML = '';

        const newWelcome =
            document.createElement('div');

        newWelcome.className = 'welcome';

        newWelcome.innerHTML = `
            <div class="welcome-icon">M</div>

            <h1>
                سلام، من Matin AI هستم
            </h1>

            <p>
                هر چیزی می‌خواهی بپرس؛ از برنامه‌نویسی تا ایده‌پردازی.
            </p>
        `;

        inner.appendChild(newWelcome);

        input.focus();
    }
);


/*
|--------------------------------------------------------------------------
| Focus
|--------------------------------------------------------------------------
*/

input.focus();

</script>

</body>
</html>
