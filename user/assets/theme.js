// theme.js — 라이트/다크 테마 (사용자 화면 + 관리자 화면 공용)
//
// <head> 맨 위에서 '동기'로 읽힌다 → 화면이 그려지기 전에 테마가 정해져서 하얗게 번쩍이지 않는다.
//   · 저장된 선택(localStorage 'moa-theme')이 있으면 <html data-theme="dark|light"> 로 고정
//   · 없으면 아무것도 안 붙임 → CSS가 기기 설정(prefers-color-scheme)을 따른다
//   · 해/달 스위치(input[data-theme-switch], includes/theme_switch.php)를 켜고 끄면 다크/라이트로 바꾸고 기억한다
//     (스위치 '모양'은 CSS가 지금 테마를 보고 정한다 → 여기선 체크 상태만 맞춰 준다)
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
    function sync() {
        var dark = current() === 'dark';
        var sws = document.querySelectorAll('input[data-theme-switch]');
        for (var i = 0; i < sws.length; i++) sws[i].checked = dark;         // 체크 = 다크 (화면읽기 프로그램이 '켜짐/꺼짐'으로 읽음)
        var meta = document.querySelector('meta[name="theme-color"]');      // 휴대폰 주소창 색도 맞춘다
        if (meta) meta.setAttribute('content', getComputedStyle(root).getPropertyValue('--card').trim() || (dark ? '#1c1c21' : '#ffffff'));
        try { window.dispatchEvent(new Event('themechange')); } catch (e) {}
    }
    function set(next) {
        root.setAttribute('data-theme', next);
        try { localStorage.setItem(KEY, next); } catch (e) {}
        sync();
    }
    function toggle() { set(current() === 'dark' ? 'light' : 'dark'); }
    // 기기 설정이 바뀌면(밤에 자동 다크 등) 직접 고른 적이 없을 때만 따라간다
    if (mq) {
        var onChange = function () { if (!root.getAttribute('data-theme')) sync(); };
        if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }
    // 스위치를 켜고 끄면(마우스·터치·스페이스바 모두) → 그 상태대로 테마 저장
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[data-theme-switch]')) set(e.target.checked ? 'dark' : 'light');
    });
    document.addEventListener('DOMContentLoaded', sync);
    window.moaTheme = { current: current, toggle: toggle, set: set };
})();
