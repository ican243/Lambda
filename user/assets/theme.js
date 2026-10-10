// theme.js — 라이트/다크 테마 (사용자 화면 + 관리자 화면 공용)
//
// <head> 맨 위에서 '동기'로 읽힌다 → 화면이 그려지기 전에 테마가 정해져서 하얗게 번쩍이지 않는다.
//   · 저장된 선택(localStorage 'moa-theme')이 있으면 <html data-theme="dark|light"> 로 고정
//   · 없으면 아무것도 안 붙임 → CSS가 기기 설정(prefers-color-scheme)을 따른다
//   · [data-theme-toggle] 버튼을 누르면 반대 테마로 바꾸고 기억한다
//   · 테마가 바뀌면 window 에 'themechange' 이벤트를 쏜다 → 차트처럼 그림판(canvas)에 그린 것들이 새 색으로 다시 그린다
(function () {
    var KEY = 'moa-theme', root = document.documentElement;
    var saved = null;
    try { saved = localStorage.getItem(KEY); } catch (e) {}
    if (saved === 'dark' || saved === 'light') root.setAttribute('data-theme', saved);

    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function current() {
        var t = root.getAttribute('data-theme');
        return t ? t : (mq && mq.matches ? 'dark' : 'light');
    }
    var MOON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    var SUN  = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';

    function sync() {
        var dark = current() === 'dark';
        var btns = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].innerHTML = dark ? SUN : MOON;                          // 지금 다크면 '해'(=라이트로 바꾸기)
            btns[i].setAttribute('aria-label', dark ? '라이트 모드로 바꾸기' : '다크 모드로 바꾸기');
            btns[i].title = btns[i].getAttribute('aria-label');
        }
        var meta = document.querySelector('meta[name="theme-color"]');      // 휴대폰 주소창 색도 맞춘다
        if (meta) meta.setAttribute('content', getComputedStyle(root).getPropertyValue('--card').trim() || (dark ? '#1c1c21' : '#ffffff'));
        try { window.dispatchEvent(new Event('themechange')); } catch (e) {}
    }
    function toggle() {
        var next = current() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem(KEY, next); } catch (e) {}
        sync();
    }
    // 기기 설정이 바뀌면(밤에 자동 다크 등) 직접 고른 적이 없을 때만 따라간다
    if (mq) {
        var onChange = function () { if (!root.getAttribute('data-theme')) sync(); };
        if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-theme-toggle]');
        if (b) toggle();
    });
    document.addEventListener('DOMContentLoaded', sync);
    window.moaTheme = { current: current, toggle: toggle };
})();
