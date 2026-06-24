/* ============================================================
   ANIMATIONS ENGINE - byBuka Premium
   ============================================================ */
(function () {
    'use strict';

    const SPARKLINE_PTS = 9;

    // Boot
    function boot() {
        initSidebarPill();
        initRipple();
        initMobileNav();
        initSidebarCollapse();
        runPageAnimations();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('spa:navigated', () => {
        setTimeout(runPageAnimations, 80);
        setTimeout(initMobileNav, 80);
        setTimeout(initSidebarPill, 80);
    });

    // 1. STAT VALUE COUNT-UP ANIMATION
    function animateStatValues(root) {
        (root || document).querySelectorAll('.stat-value').forEach(el => {
            if (el.offsetWidth === 0) return;
            if (el.dataset.cAnim) return;
            // Постоянный опт-аут: класс не стирается в reinitCardAnimations,
            // в отличие от dataset.cAnim. Нужен для точных финансовых сумм.
            if (el.classList.contains('no-count')) return;

            // Normalize non-breaking spaces for parsing
            const origText = el.textContent;
            const norm = origText.replace(/[   ]/g, ' ').trim();

            // Find a numeric sequence (with optional spaces as thousand separators)
            const numMatch = norm.match(/([\d][\d ]*\.?[\d]*)/);
            if (!numMatch) return;

            const numStr = numMatch[1].replace(/\s/g, '');
            const target = parseFloat(numStr);
            if (!isFinite(target) || target < 10) return; // skip tiny/non-numeric

            el.dataset.cAnim = '1';
            el.classList.add('stat-value-animate');
            setTimeout(() => el.classList.remove('stat-value-animate'), 600);

            // Split original text around the number
            const matchStart = norm.indexOf(numMatch[1]);
            const prefix = origText.slice(0, matchStart);
            const suffix = origText.slice(matchStart + numMatch[1].length);

            // Duration scales with number size, capped at 900ms
            const dur = Math.min(900, 400 + Math.log10(target) * 90);
            const t0 = performance.now();
            const fmt = v => new Intl.NumberFormat('ru-RU').format(Math.round(v));

            // Start from 0
            el.textContent = prefix + '0' + suffix;

            function tick(now) {
                const p = Math.min((now - t0) / dur, 1);
                // Ease out cubic: fast start, slows at end
                const ease = 1 - Math.pow(1 - p, 3);
                el.textContent = prefix + fmt(target * ease) + suffix;
                if (p < 1) {
                    requestAnimationFrame(tick);
                } else {
                    el.textContent = origText; // restore exact original text
                    delete el.dataset.cAnim;
                }
            }
            requestAnimationFrame(tick);
        });
    }

    // Expose for dynamic content (salary tab, etc.)
    window.runCounterAnimation = animateStatValues;

    // 2. SIDEBAR SLIDING PILL
    function initSidebarPill() {
        const nav = document.querySelector('.sidebar-nav');
        if (!nav) return;

        let pill = document.getElementById('sidebar-pill');
        if (!pill) {
            pill = document.createElement('div');
            pill.className = 'sidebar-pill';
            pill.id = 'sidebar-pill';
            nav.insertBefore(pill, nav.firstChild);
        }

        function positionPill(target, animate) {
            if (!target) { pill.classList.remove('visible'); return; }
            const navRect    = nav.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const top        = targetRect.top - navRect.top + nav.scrollTop;

            if (!animate) {
                pill.style.transition = 'none';
                requestAnimationFrame(() => {
                    pill.style.top    = top + 'px';
                    pill.style.height = targetRect.height + 'px';
                    pill.classList.add('visible');
                    requestAnimationFrame(() => { pill.style.transition = ''; });
                });
            } else {
                pill.style.top    = top + 'px';
                pill.style.height = targetRect.height + 'px';
                pill.classList.add('visible');
            }
        }

        positionPill(nav.querySelector('.nav-item.active'), false);

        nav.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', () => {
                nav.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                positionPill(item, true);
            });
        });
    }

    // 3. SPARKLINES
    function initSparklines(root) {
        (root || document).querySelectorAll('.stat-card').forEach((card, idx) => {
            if (card.offsetWidth === 0) return;
            if (card.querySelector('.sparkline-wrap')) return;

            const valueEl = card.querySelector('.stat-value');
            if (!valueEl) return;

            const text = valueEl.textContent.replace(/[^\d]/g, '');
            const seed = parseInt(text) || (idx + 1) * 7919;

            const color = getValueColor(valueEl);
            const pts   = genSparkPoints(seed, SPARKLINE_PTS);

            const wrap   = document.createElement('div');
            wrap.className = 'sparkline-wrap';
            const canvas = document.createElement('canvas');
            wrap.appendChild(canvas);
            card.appendChild(wrap);

            // Двойной rAF — даём браузеру завершить layout после display:block
            requestAnimationFrame(() => requestAnimationFrame(() => drawSparkline(canvas, pts, color, wrap)));
        });
    }

    function genSparkPoints(seed, n) {
        const pts = [];
        let s = Math.abs(seed) || 1;
        for (let i = 0; i < n; i++) {
            s = ((s * 1103515245) + 12345) >>> 0;
            pts.push(15 + (s % 60));
        }
        pts[n - 1] = Math.max(pts[n - 1], 65);
        pts[n - 2] = Math.max(pts[n - 2], 50);
        return pts;
    }

    function getValueColor(el) {
        const rgb = getComputedStyle(el).color;
        const m   = rgb.match(/\d+/g);
        if (!m) return '#C9A96E';
        const [r, g, b] = m.map(Number);
        if (g > 150 && r < 100)  return '#22C55E';
        if (r > 200 && g < 100)  return '#EF4444';
        if (r > 220 && g > 130 && b < 50) return '#F59E0B';
        return '#C9A96E';
    }

    function drawSparkline(canvas, pts, hexColor, wrap) {
        const W = wrap.clientWidth || 200;
        const H = 52;
        const dpr = window.devicePixelRatio || 1;

        canvas.width  = W * dpr;
        canvas.height = H * dpr;
        canvas.style.width  = W + 'px';
        canvas.style.height = H + 'px';

        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        const n    = pts.length;
        const xS   = W / (n - 1);
        const yMin = Math.min(...pts);
        const yMax = Math.max(...pts);
        const yR   = (yMax - yMin) || 1;

        const gx = i => i * xS;
        const gy = v => H - ((v - yMin) / yR) * (H * 0.75) - H * 0.12;

        animateLineDraw(ctx, canvas, pts, gx, gy, n, hexColor, W, H, dpr, wrap);
    }

    function easeOutExpo(t) {
        return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
    }

    function animateLineDraw(ctx, canvas, pts, gx, gy, n, color, W, H, dpr, wrap) {
        const duration = 800;
        const start    = performance.now();
        const rgb      = hexToRgb(color);

        function frame(now) {
            const progress = Math.min((now - start) / duration, 1);
            const eased    = easeOutExpo(progress);

            ctx.clearRect(0, 0, W * dpr, H * dpr);
            ctx.save();
            ctx.scale(dpr, dpr);

            // Fill
            ctx.beginPath();
            ctx.moveTo(gx(0), gy(pts[0]));
            for (let i = 1; i < n; i++) {
                const cx = (gx(i - 1) + gx(i)) / 2;
                ctx.bezierCurveTo(cx, gy(pts[i-1]), cx, gy(pts[i]), gx(i), gy(pts[i]));
            }
            ctx.lineTo(gx(n - 1), H);
            ctx.lineTo(gx(0), H);
            ctx.closePath();
            const grad = ctx.createLinearGradient(0, 0, 0, H);
            grad.addColorStop(0,   `rgba(${rgb},${0.13 * eased})`);
            grad.addColorStop(0.7, `rgba(${rgb},${0.03 * eased})`);
            grad.addColorStop(1,   `rgba(${rgb},0)`);
            ctx.fillStyle = grad;
            ctx.fill();

            // Line (clipped to progress)
            ctx.save();
            ctx.beginPath();
            ctx.rect(0, 0, W * eased, H + 2);
            ctx.clip();
            ctx.beginPath();
            ctx.moveTo(gx(0), gy(pts[0]));
            for (let i = 1; i < n; i++) {
                const cx = (gx(i - 1) + gx(i)) / 2;
                ctx.bezierCurveTo(cx, gy(pts[i-1]), cx, gy(pts[i]), gx(i), gy(pts[i]));
            }
            ctx.strokeStyle = color;
            ctx.globalAlpha = 0.45;
            ctx.lineWidth   = 1.5;
            ctx.lineCap     = 'round';
            ctx.stroke();
            ctx.globalAlpha = 1;
            ctx.restore();

            // End dot
            const dotProgress = Math.max(0, (eased - 0.85) / 0.15);
            if (dotProgress > 0) {
                const lx = gx(n - 1), ly = gy(pts[n - 1]);
                ctx.beginPath();
                ctx.arc(lx, ly, 5 * dotProgress, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(${rgb},${0.15 * dotProgress})`;
                ctx.fill();
                ctx.beginPath();
                ctx.arc(lx, ly, 2.5, 0, Math.PI * 2);
                ctx.fillStyle = color;
                ctx.globalAlpha = dotProgress;
                ctx.fill();
                ctx.globalAlpha = 1;
            }

            ctx.restore();
            if (progress < 1) requestAnimationFrame(frame);
            else wrap.classList.add('ready');
        }
        requestAnimationFrame(frame);
    }

    function hexToRgb(hex) {
        return `${parseInt(hex.slice(1,3),16)},${parseInt(hex.slice(3,5),16)},${parseInt(hex.slice(5,7),16)}`;
    }

    // 4. AMBIENT GLOW
    function initAmbientGlow(root) {
        (root || document).querySelectorAll('.stat-card').forEach(card => {
            if (card.offsetWidth === 0) return;
            card.classList.remove('glow-success','glow-danger','glow-warning','glow-accent');
            const valueEl = card.querySelector('.stat-value');
            if (!valueEl) return;
            const color = getValueColor(valueEl);
            if      (color === '#22C55E') card.classList.add('glow-success');
            else if (color === '#EF4444') card.classList.add('glow-danger');
            else if (color === '#F59E0B') card.classList.add('glow-warning');
            else                          card.classList.add('glow-accent');
        });
    }

    // 5. BUTTON RIPPLE
    function initRipple() {
        document.addEventListener('pointerdown', e => {
            const btn = e.target.closest('.btn');
            if (!btn || btn.disabled) return;
            const ripple = document.createElement('span');
            ripple.className = 'ripple-effect';
            const size = Math.max(btn.clientWidth, btn.clientHeight) * 1.5;
            const rect = btn.getBoundingClientRect();
            ripple.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-rect.left-size/2}px;top:${e.clientY-rect.top-size/2}px;`;
            btn.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
        });
    }

    // 6. SIDEBAR COLLAPSE
    function initSidebarCollapse() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        if (!document.getElementById('sidebar-collapse-btn')) {
            const btn = document.createElement('button');
            btn.id        = 'sidebar-collapse-btn';
            btn.className = 'sidebar-collapse-btn';
            btn.innerHTML = '&#8249;';
            btn.title     = 'Свернуть';
            btn.addEventListener('click', toggleSidebar);
            sidebar.appendChild(btn);
        }

        if (localStorage.getItem('byb_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
            const btn = document.getElementById('sidebar-collapse-btn');
            if (btn) { btn.innerHTML = '&#8250;'; btn.title = 'Развернуть'; }
        }
    }

    function toggleSidebar() {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        const btn = document.getElementById('sidebar-collapse-btn');
        if (btn) {
            btn.innerHTML = collapsed ? '&#8250;' : '&#8249;';
            btn.title     = collapsed ? 'Развернуть' : 'Свернуть';
        }
        localStorage.setItem('byb_sidebar_collapsed', collapsed ? '1' : '0');
        setTimeout(initSidebarPill, 300);
    }
    window.toggleSidebar = toggleSidebar;

    // 7. MOBILE BOTTOM NAV
    function initMobileNav() {
        const nav = document.getElementById('bottom-nav');
        if (!nav) return;
        const page = new URLSearchParams(window.location.search).get('page') || 'dashboard';
        nav.querySelectorAll('.bottom-nav-item').forEach(item => {
            const href     = item.getAttribute('href') || '';
            const itemPage = href.includes('page=') ? href.split('page=')[1] : 'dashboard';
            item.classList.toggle('active', itemPage === page);
        });
        if (window.lucide) lucide.createIcons({ nodes: nav.querySelectorAll('[data-lucide]') });
    }

    // 8. ANIMATED ROW REMOVAL
    window.removeRowAnimated = function(rowEl, cb) {
        if (!rowEl) { cb && cb(); return; }
        rowEl.classList.add('row-removing');
        const done = () => { rowEl.remove(); cb && cb(); };
        rowEl.addEventListener('animationend', done, { once: true });
        setTimeout(done, 450);
    };

    // 9. AURORA CANVAS BACKGROUND (dashboard only)
    function initAurora() {
        if (document.getElementById('aurora-canvas')) return;
        const page = new URLSearchParams(window.location.search).get('page') || 'dashboard';
        if (page !== 'dashboard') return;

        const canvas = document.createElement('canvas');
        canvas.id = 'aurora-canvas';
        canvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:0;opacity:0;transition:opacity 1.2s ease';
        document.body.appendChild(canvas);

        const ctx = canvas.getContext('2d');
        let W, H;

        function resize() {
            W = canvas.width  = window.innerWidth;
            H = canvas.height = window.innerHeight;
        }
        resize();
        window.addEventListener('resize', resize);

        // 3 blobs with slow drift
        const blobs = [
            { x: W * 0.15, y: H * 0.3,  r: 420, dx: 0.18, dy: 0.12, color: '201,169,110', o: 0.032 },
            { x: W * 0.75, y: H * 0.6,  r: 380, dx: -0.14, dy: 0.16, color: '80,120,200', o: 0.022 },
            { x: W * 0.5,  y: H * 0.15, r: 340, dx: 0.10, dy: -0.13, color: '201,169,110', o: 0.018 }
        ];

        function frame() {
            ctx.clearRect(0, 0, W, H);
            blobs.forEach(b => {
                b.x += b.dx; b.y += b.dy;
                if (b.x < -b.r || b.x > W + b.r) b.dx *= -1;
                if (b.y < -b.r || b.y > H + b.r) b.dy *= -1;

                const g = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, b.r);
                g.addColorStop(0,   `rgba(${b.color},${b.o})`);
                g.addColorStop(0.5, `rgba(${b.color},${b.o * 0.4})`);
                g.addColorStop(1,   `rgba(${b.color},0)`);
                ctx.beginPath();
                ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
                ctx.fillStyle = g;
                ctx.fill();
            });
            requestAnimationFrame(frame);
        }

        requestAnimationFrame(frame);
        setTimeout(() => { canvas.style.opacity = '1'; }, 100);
    }

    // 10. SOFT WAVE BACKGROUND IN STAT CARDS
    function initStatCardWaves(root) {
        (root || document).querySelectorAll('.stat-card').forEach((card, idx) => {
            if (card.offsetWidth === 0) return;
            if (card.querySelector('.stat-wave-canvas')) return;

            const canvas = document.createElement('canvas');
            canvas.className = 'stat-wave-canvas';
            card.appendChild(canvas);

            const ctx = canvas.getContext('2d');
            const rgb = getWaveColor(card);
            let tick  = idx * 55; // offset — каждая плитка в своей фазе

            function syncSize() {
                const W = card.offsetWidth  || 240;
                const H = card.offsetHeight || 110;
                canvas.width  = W;
                canvas.height = H;
                return { W, H };
            }

            function drawFrame() {
                // Каждый кадр берём актуальные размеры из атрибутов canvas
                const W = canvas.width, H = canvas.height;
                if (!W || !H) return;
                ctx.clearRect(0, 0, W, H);

                const t = tick * 0.006;

                for (let layer = 0; layer < 2; layer++) {
                    const phase = layer * 1.9;
                    // Абсолютная пикселевая частота — волна не "заканчивается" у края
                    const freq1 = 0.016, freq2 = 0.009;
                    const yBase = H * (layer === 0 ? 0.58 : 0.70);
                    const amp   = H * (layer === 0 ? 0.18 : 0.11);
                    const alpha = layer === 0 ? 0.22 : 0.13;

                    ctx.beginPath();
                    // Начинаем чуть левее 0 чтобы волна заходила за левый край
                    for (let x = -8; x <= W + 8; x += 2) {
                        const y = yBase
                            + Math.sin(x * freq1 + t + phase) * amp
                            + Math.sin(x * freq2 + t * 0.65 + phase) * amp * 0.45;
                        x <= -8 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                    }
                    ctx.lineTo(W + 8, H);
                    ctx.lineTo(-8, H);
                    ctx.closePath();

                    const grad = ctx.createLinearGradient(0, 0, 0, H);
                    grad.addColorStop(0,   `rgba(${rgb},${alpha})`);
                    grad.addColorStop(0.65,`rgba(${rgb},${alpha * 0.3})`);
                    grad.addColorStop(1,   `rgba(${rgb},0)`);
                    ctx.fillStyle = grad;
                    ctx.fill();
                }
                tick++;
            }

            function loop() { drawFrame(); requestAnimationFrame(loop); }

            // Прогрев — 90 кадров до показа, волна уже в естественном состоянии
            syncSize();
            for (let i = 0; i < 90; i++) { drawFrame(); }

            setTimeout(() => {
                syncSize();
                canvas.classList.add('ready');
                loop();
            }, 150 + idx * 55);
        });
    }

    function getWaveColor(card) {
        const valueEl = card.querySelector('.stat-value');
        if (!valueEl) return '201,169,110';
        const hex = getValueColor(valueEl);
        const map = {
            '#22C55E': '34,197,94',
            '#EF4444': '239,68,68',
            '#F59E0B': '245,158,11',
            '#C9A96E': '201,169,110'
        };
        return map[hex] || '201,169,110';
    }

    // Override runPageAnimations
    function runPageAnimations() {
        animateStatValues(document);
        setTimeout(() => initSparklines(document),    200);
        setTimeout(() => initAmbientGlow(document),   300);
        setTimeout(initAurora,                        400);
    }

    document.addEventListener('spa:navigated', () => {
        const page = new URLSearchParams(window.location.search).get('page') || 'dashboard';
        const existing = document.getElementById('aurora-canvas');
        if (existing && page !== 'dashboard') {
            existing.style.opacity = '0';
            setTimeout(() => existing.remove(), 1200);
        }
    });

    // 12. REINIT — для видимых карточек удаляет старые анимации и перерисовывает корректно
    function reinitCardAnimations(container) {
        const root = (typeof container === 'string')
            ? document.getElementById(container)
            : (container instanceof Element ? container : document);
        if (!root) return;

        root.querySelectorAll('.stat-card').forEach(card => {
            if (card.offsetWidth === 0) return;
            card.querySelector('.sparkline-wrap')?.remove();
            card.querySelector('.stat-wave-canvas')?.remove();
            const sv = card.querySelector('.stat-value');
            if (sv) delete sv.dataset.cAnim;
        });

        initSparklines(root);
        initAmbientGlow(root);
        animateStatValues(root);
    }

    window._bybAnimations = {
        initSparklines, initAmbientGlow, initSidebarPill,
        runPageAnimations, animateStatValues, initStatCardWaves,
        initAurora, reinitCardAnimations,
        runCounterAnimation: animateStatValues
    };

})();
