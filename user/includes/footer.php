    </div><!-- /.app -->

    <!-- 바닥글 — 이 서비스가 무엇인지, 누가 만들었는지 -->
    <footer class="site-foot">
        <div class="site-foot-in">
            <div class="site-foot-brand"><img src="assets/favicon.svg" alt="" width="20" height="20"> 모아증권</div>
            <p>진짜 시세로 연습하는 모의투자 서비스예요. <b>실제 돈이 오가지 않고</b>, 화면의 어떤 정보도 투자 권유가 아니에요.</p>
            <p class="meta">
                <span>시세 · 한국투자증권 Open API</span>
                <span>Made by <a href="https://github.com/ican243" target="_blank" rel="noopener">ican243</a></span>
            </p>
        </div>
    </footer>

    <?php if (empty($hideTabBar)):
        // 휴대폰 하단 메뉴 — 900px 이하에서만 보인다 (PC는 위쪽 메뉴)
        $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $tabs = [
            ['index.php', '홈', '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>'],
            ['auto_trade.php', '자동매매', '<rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 3v4M9 12h.01M15 12h.01M9 16h6"/>'],
            ['trade.php', '내 계좌', '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M16 15h2"/>'],
        ];
    ?>
        <nav class="tabbar" aria-label="하단 메뉴">
            <?php foreach ($tabs as [$href, $label, $icon]): ?>
                <a href="<?= $href ?>" class="<?= $here === $href ? 'on' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg>
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</body>

</html>
