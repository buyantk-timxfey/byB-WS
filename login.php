<?php
session_start();
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';

    // ── Простой rate-limiting: 5 попыток / 15 минут на сессию ────────────────
    $now = time();
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = ['count' => 0, 'first' => $now];
    }
    $att = &$_SESSION['login_attempts'];
    if ($now - $att['first'] > 900) { $att = ['count' => 0, 'first' => $now]; }

    if ($att['count'] >= 5) {
        $error = 'Слишком много попыток входа. Подождите 15 минут.';
    } elseif (
        ADMIN_HASH !== '' &&
        hash_equals(ADMIN_EMAIL, $login) &&
        password_verify($password, ADMIN_HASH)
    ) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        unset($_SESSION['login_attempts']);
        issueDeviceToken($pdo);   // запомнить устройство для входа по PIN
        header('Location: index.php');
        exit;
    } else {
        $att['count']++;
        $error = 'Неверный логин или пароль';
    }
}

if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    header('Location: index.php');
    exit;
}

// PIN доступен только на запомненном устройстве и если PIN задан.
$pinAvailable = (currentDeviceId($pdo) !== null) && pinIsSet($pdo);
// По умолчанию показываем PIN, но если был неуспешный вход паролем — форму.
$showPin = $pinAvailable && !isset($error);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>byBuka — Вход</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #0F0F0F;
            color: #F5F5F5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* Фоновые частицы */
        .bg {
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(201,169,110,0.04) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(201,169,110,0.03) 0%, transparent 50%);
            z-index: 0;
        }

        .login-wrap {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 400px;
            padding: 20px;
            animation: fadeUp 0.6s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .login-logo {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-logo img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .login-card {
            background: #1A1A1A;
            border: 1px solid #2A2A2A;
            border-radius: 16px;
            padding: 36px;
        }

        .login-title {
            font-size: 20px;
            font-weight: 300;
            letter-spacing: -0.3px;
            margin-bottom: 6px;
        }

        .login-sub {
            font-size: 13px;
            color: #888;
            margin-bottom: 28px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            color: #888;
            margin-bottom: 6px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 11px 14px;
            background: #0F0F0F;
            border: 1px solid #2A2A2A;
            border-radius: 8px;
            color: #F5F5F5;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s ease;
        }

        .form-control:focus {
            border-color: #C9A96E;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: #C9A96E;
            color: #000;
            border: none;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
            margin-top: 8px;
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            background: #D4B87A;
        }

        .error {
            background: rgba(244,67,54,0.1);
            border: 1px solid rgba(244,67,54,0.2);
            color: #F44336;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: #555;
        }

        .btn-faceid {
            width: 100%;
            padding: 12px;
            background: transparent;
            color: #C9A96E;
            border: 1px solid rgba(201,169,110,0.35);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 10px;
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }
        .btn-faceid:hover { background: rgba(201,169,110,0.08); border-color: #C9A96E; }
        .btn-faceid svg { width: 18px; height: 18px; }

        .faceid-divider {
            display: none;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            color: #444;
            font-size: 12px;
        }
        .faceid-divider::before, .faceid-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #2A2A2A;
        }

        /* ── PIN: 4 поля ввода ── */
        .pin-boxes {
            display: flex;
            justify-content: center;
            gap: 14px;
            margin: 10px 0 20px;
        }
        .pin-box {
            width: 58px;
            height: 66px;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 28px;
            font-weight: 600;
            color: #F5F5F5;
            background: #0F0F0F;
            border: 1.5px solid #2A2A2A;
            border-radius: 14px;
            outline: none;
            caret-color: #C9A96E;
            transition: border-color 0.18s ease, box-shadow 0.2s ease,
                        transform 0.14s cubic-bezier(0.34,1.4,0.6,1), background 0.18s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .pin-box::placeholder { color: #2F2F2F; }
        .pin-box:focus {
            border-color: #C9A96E;
            box-shadow: 0 0 0 3px rgba(201,169,110,0.16), 0 0 16px rgba(201,169,110,0.32);
            transform: translateY(-3px);
        }
        .pin-box.filled {
            border-color: #C9A96E;
            background: #16130E;
            animation: pinpop 0.24s cubic-bezier(0.34,1.5,0.6,1);
        }
        .pin-box.error {
            border-color: #F44336 !important;
            box-shadow: 0 0 0 3px rgba(244,67,54,0.16);
            color: #F44336;
        }
        .pin-box.ok {
            border-color: #4CAF50 !important;
            background: #0E1610;
            box-shadow: 0 0 0 3px rgba(76,175,80,0.18), 0 0 18px rgba(76,175,80,0.45);
            color: #4CAF50;
            animation: pinok 0.4s ease;
        }
        @keyframes pinpop {
            0%   { transform: scale(1); }
            45%  { transform: scale(1.12); }
            100% { transform: scale(1); }
        }
        @keyframes pinok {
            0%   { transform: scale(1); }
            40%  { transform: scale(1.1) translateY(-2px); }
            100% { transform: scale(1); }
        }
        .pin-error {
            color: #F44336;
            font-size: 13px;
            text-align: center;
            min-height: 18px;
            margin-bottom: 12px;
        }
        .pin-switch {
            text-align: center;
            margin-top: 20px;
        }
        .pin-switch a {
            color: #888;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            border-bottom: 1px dashed #444;
            padding-bottom: 1px;
        }
        .pin-switch a:hover { color: #C9A96E; border-color: #C9A96E; }
        .shake { animation: shake 0.4s; }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%,60% { transform: translateX(-8px); }
            40%,80% { transform: translateX(8px); }
        }
    </style>
    <style>
        /* Particle canvas bg */
        #particles-canvas { opacity: .85; }
    </style>
</head>
<body>
    <canvas id="particles-canvas" style="position:fixed;inset:0;z-index:0;pointer-events:none"></canvas>

    <div class="login-wrap">
        <div class="login-logo">
            <img src="assets/img/logo1.png" alt="by B">
        </div>

        <div class="login-card">

            <!-- ── PIN-вход ── -->
            <div id="pin-view" style="<?= $showPin ? '' : 'display:none' ?>">
                <div class="login-title">С возвращением</div>
                <div class="login-sub">Введите PIN-код</div>

                <div class="pin-boxes" id="pin-boxes">
                    <input class="pin-box" type="tel" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Цифра 1">
                    <input class="pin-box" type="tel" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Цифра 2">
                    <input class="pin-box" type="tel" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Цифра 3">
                    <input class="pin-box" type="tel" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Цифра 4">
                </div>
                <div class="pin-error" id="pin-error"></div>

                <div class="pin-switch"><a onclick="showPwdView()">Войти паролем</a></div>
            </div>

            <!-- ── Вход по паролю ── -->
            <div id="pwd-view" style="<?= $showPin ? 'display:none' : '' ?>">
                <div class="login-title">Добро пожаловать</div>
                <div class="login-sub">Войдите в by B Workspace</div>

                <?php if (isset($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="login" class="form-control" placeholder="Логин" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Пароль</label>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <button type="submit" class="btn-login">Войти</button>
                </form>

                <div class="faceid-divider" id="faceid-divider">или</div>
                <button class="btn-faceid" id="btn-faceid" onclick="loginWithFaceId()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2v-4M9 21H5a2 2 0 0 1-2-2v-4m0 0h18"/>
                        <circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/>
                        <path d="M8 9.5C8.5 8.5 10 7.5 12 7.5s3.5 1 4 2.5M8 14.5C8.5 15.5 10 16.5 12 16.5s3.5-1 4-2.5"/>
                    </svg>
                    Войти через Face ID
                </button>

                <?php if ($pinAvailable): ?>
                <div class="pin-switch"><a onclick="showPinView()">Войти по PIN</a></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="login-footer">by B Workspace © 2026</div>
    </div>

<script>
var SHOW_PIN = <?= $showPin ? 'true' : 'false' ?>;

// ── Face ID / WebAuthn ────────────────────────────────────────────────────────

function b64url(buf) {
    return btoa(String.fromCharCode(...new Uint8Array(buf)))
        .replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}
function b64urlDecode(str) {
    str = str.replace(/-/g,'+').replace(/_/g,'/');
    while (str.length % 4) str += '=';
    return Uint8Array.from(atob(str), c => c.charCodeAt(0)).buffer;
}

async function loginWithFaceId() {
    try {
        const res = await fetch('api/webauthn.php?action=auth_begin');
        const opts = await res.json();
        if (opts.error || !opts.challenge) { alert(opts.error || 'Face ID недоступен'); return; }

        const assertion = await navigator.credentials.get({
            publicKey: {
                challenge: b64urlDecode(opts.challenge),
                rpId:      opts.rp_id,
                allowCredentials: [{
                    type: 'public-key',
                    id:   b64urlDecode(opts.credential_id),
                }],
                userVerification: 'required',
                timeout: 60000,
            }
        });

        const body = JSON.stringify({
            credentialId:      b64url(assertion.rawId),
            clientDataJSON:    b64url(assertion.response.clientDataJSON),
            authenticatorData: b64url(assertion.response.authenticatorData),
            signature:         b64url(assertion.response.signature),
        });

        const verif = await fetch('api/webauthn.php?action=auth_complete', {
            method: 'POST', headers: {'Content-Type':'application/json'}, body
        });
        const result = await verif.json();
        if (result.ok) { window.location.href = 'index.php'; }
        else { alert('Face ID: ' + (result.error || 'Ошибка проверки')); }
    } catch (e) {
        if (e.name !== 'NotAllowedError') alert('Face ID ошибка: ' + e.message);
    }
}

// Проверяем наличие сохранённого Face ID
async function checkFaceIdAvailable() {
    if (!window.PublicKeyCredential) return;
    try {
        const res = await fetch('api/webauthn.php?action=auth_begin');
        const opts = await res.json();
        if (opts.registered === false || opts.error) return;
        // Есть credential — показываем кнопку
        document.getElementById('btn-faceid').style.display   = 'flex';
        document.getElementById('faceid-divider').style.display = 'flex';
        // Авто-триггер Face ID только если не показан PIN-пад (иначе два запроса конкурируют)
        if (!SHOW_PIN) setTimeout(loginWithFaceId, 400);
    } catch {}
}

document.addEventListener('DOMContentLoaded', checkFaceIdAvailable);
</script>

<script>
// ── Регистрация Face ID после входа (вызывается из index.php) ─────────────────
async function registerFaceId() {
    if (!window.PublicKeyCredential) { alert('Ваш браузер не поддерживает Face ID'); return; }
    try {
        const res  = await fetch('api/webauthn.php?action=register_begin');
        const opts = await res.json();
        if (opts.error) { alert(opts.error); return; }

        const cred = await navigator.credentials.create({
            publicKey: {
                challenge: b64urlDecode(opts.challenge),
                rp:        { id: opts.rp_id, name: 'byBuka' },
                user:      { id: new TextEncoder().encode(opts.user_id), name: opts.user_name, displayName: opts.user_name },
                pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
                authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'preferred' },
                timeout: 60000,
            }
        });

        const body = JSON.stringify({
            clientDataJSON:    b64url(cred.response.clientDataJSON),
            attestationObject: b64url(cred.response.attestationObject),
        });
        const reg = await fetch('api/webauthn.php?action=register_complete', {
            method: 'POST', headers: {'Content-Type':'application/json'}, body
        });
        const result = await reg.json();
        if (result.ok) { alert('Face ID сохранён! Теперь можно входить биометрией.'); }
        else { alert('Ошибка: ' + (result.error || 'Неизвестная ошибка')); }
    } catch (e) {
        if (e.name !== 'NotAllowedError') alert('Ошибка Face ID: ' + e.message);
    }
}
// b64url / b64urlDecode уже определены в первом <script>-блоке выше
</script>

<script>
// ── PIN: 4 поля ввода (нативная клавиатура) ─────────────────────────────────────
let _pinBusy = false;
function pinBoxes() { return Array.from(document.querySelectorAll('#pin-boxes .pin-box')); }
function pinValue() { return pinBoxes().map(b => b.value).join(''); }
function pinClear() {
    pinBoxes().forEach(b => { b.value = ''; b.classList.remove('filled', 'error', 'ok'); });
}
function pinInit() {
    const boxes = pinBoxes();
    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g, '').slice(0, 1);
            box.classList.toggle('filled', box.value !== '');
            box.classList.remove('error');
            document.getElementById('pin-error').textContent = '';
            if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
            if (pinValue().length === 4) setTimeout(pinSubmit, 90);
        });
        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && i > 0) {
                e.preventDefault();
                boxes[i - 1].focus();
                boxes[i - 1].value = '';
                boxes[i - 1].classList.remove('filled');
            }
        });
        box.addEventListener('focus', () => box.select());
        box.addEventListener('paste', e => {
            const t = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 4);
            if (!t) return;
            e.preventDefault();
            boxes.forEach((b, j) => { b.value = t[j] || ''; b.classList.toggle('filled', !!t[j]); });
            if (t.length === 4) setTimeout(pinSubmit, 90);
            else (boxes[t.length] || boxes[3]).focus();
        });
    });
}
async function pinSubmit() {
    if (_pinBusy) return;
    _pinBusy = true;
    try {
        const data = await (await fetch('api/auth_pin.php?action=verify', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pin: pinValue() })
        })).json();
        if (data.ok) {
            pinBoxes().forEach(b => b.classList.add('ok'));
            setTimeout(() => window.location.href = 'index.php', 320);
            return;
        }
        _pinBusy = false;
        if (data.locked || data.fallback) { showPwdView(); return; }
        const wrap = document.getElementById('pin-boxes');
        pinBoxes().forEach(b => b.classList.add('error'));
        wrap.classList.remove('shake'); void wrap.offsetWidth; wrap.classList.add('shake');
        document.getElementById('pin-error').textContent =
            (data.error || 'Неверный PIN') + (data.left != null ? ` · осталось ${data.left}` : '');
        setTimeout(() => { pinClear(); pinBoxes()[0].focus(); }, 500);
    } catch (e) {
        _pinBusy = false;
        pinClear();
        document.getElementById('pin-error').textContent = 'Ошибка сети';
    }
}
function showPwdView() {
    document.getElementById('pin-view').style.display = 'none';
    document.getElementById('pwd-view').style.display = '';
}
function showPinView() {
    document.getElementById('pwd-view').style.display = 'none';
    document.getElementById('pin-view').style.display = '';
    pinClear();
    document.getElementById('pin-error').textContent = '';
    setTimeout(() => pinBoxes()[0] && pinBoxes()[0].focus(), 60);
}
document.addEventListener('DOMContentLoaded', () => {
    pinInit();
    if (SHOW_PIN) setTimeout(() => pinBoxes()[0] && pinBoxes()[0].focus(), 150);
});
</script>

<script>
(function () {
    const canvas = document.getElementById('particles-canvas');
    const ctx    = canvas.getContext('2d');
    const GOLD   = '201,169,110';
    const N      = 55;   // число частиц
    const SPEED  = 0.35;
    const LINK   = 140;  // расстояние для рисования линии
    const MOUSE_REPEL = 110;

    let W, H, pts, mouse = { x: -9999, y: -9999 };

    function resize() {
        W = canvas.width  = window.innerWidth;
        H = canvas.height = window.innerHeight;
    }

    function rand(a, b) { return a + Math.random() * (b - a); }

    function init() {
        resize();
        pts = Array.from({ length: N }, () => ({
            x:  rand(0, W),  y: rand(0, H),
            vx: rand(-SPEED, SPEED), vy: rand(-SPEED, SPEED),
            r:  rand(1.2, 2.4),
            o:  rand(0.35, 0.75)
        }));
    }

    function frame() {
        ctx.clearRect(0, 0, W, H);

        // Update + draw dots
        pts.forEach(p => {
            // Mouse repel
            const dx = p.x - mouse.x, dy = p.y - mouse.y;
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dist < MOUSE_REPEL && dist > 0) {
                const force = (MOUSE_REPEL - dist) / MOUSE_REPEL * 0.6;
                p.vx += dx / dist * force;
                p.vy += dy / dist * force;
            }

            // Speed cap
            const spd = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
            if (spd > SPEED * 3) { p.vx *= 0.96; p.vy *= 0.96; }

            p.x += p.vx; p.y += p.vy;
            if (p.x < 0) p.x = W; if (p.x > W) p.x = 0;
            if (p.y < 0) p.y = H; if (p.y > H) p.y = 0;

            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(${GOLD},${p.o})`;
            ctx.fill();
        });

        // Draw links
        for (let i = 0; i < pts.length; i++) {
            for (let j = i + 1; j < pts.length; j++) {
                const dx = pts[i].x - pts[j].x, dy = pts[i].y - pts[j].y;
                const d  = Math.sqrt(dx * dx + dy * dy);
                if (d < LINK) {
                    ctx.beginPath();
                    ctx.moveTo(pts[i].x, pts[i].y);
                    ctx.lineTo(pts[j].x, pts[j].y);
                    ctx.strokeStyle = `rgba(${GOLD},${(1 - d / LINK) * 0.28})`;
                    ctx.lineWidth   = 0.8;
                    ctx.stroke();
                }
            }
        }

        requestAnimationFrame(frame);
    }

    window.addEventListener('resize', () => { resize(); });
    window.addEventListener('mousemove', e => { mouse.x = e.clientX; mouse.y = e.clientY; });
    window.addEventListener('touchmove', e => {
        mouse.x = e.touches[0].clientX;
        mouse.y = e.touches[0].clientY;
    }, { passive: true });

    init();
    frame();
})();
</script>
</body>
</html>