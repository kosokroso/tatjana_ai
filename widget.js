/**
 * Vgradni klepet za tujo spletno stran (WordPress, Shopify, karkoli).
 *
 * Uporaba: na stran dodaj posodo in to skripto.
 *
 *   <div id="tatjana-chat"></div>
 *   <script src="/asistent/widget.js" defer></script>
 *
 * Naslov končne točke se prebere iz src te skripte, zato v kodi ni nikjer
 * zapisane domene — ista datoteka dela tudi pri naslednji stranki.
 *
 * Kadar modul teče na isti domeni kot stran (priporočeno), dodatnih nastavitev
 * ni. Pri vgradnji s tuje domene mora biti ta domena v CHAT_ALLOWED_ORIGINS
 * v config.php, sicer strežnik zahtevke zavrne s 403.
 */
(function () {
  'use strict';

  // currentScript je na voljo samo med izvajanjem skripte, zato ga zajamemo takoj.
  var script = document.currentScript;
  if (!script) {
    var vsi = document.getElementsByTagName('script');
    for (var i = vsi.length - 1; i >= 0; i--) {
      if (vsi[i].src && vsi[i].src.indexOf('widget.js') !== -1) { script = vsi[i]; break; }
    }
  }
  if (!script) { return; }

  var ENDPOINT = script.src.replace(/widget\.js(\?.*)?$/, 'ai/chat.php');
  var IME = script.getAttribute('data-ime') || 'Tatjana';
  var UVOD = script.getAttribute('data-uvod')
    || 'Pozdravljeni. Vprašajte me o ponudbi, cenah ali rokih izdelave.';

  var VZORCI = [
    'Koliko stane spletna stran?',
    'Kaj vključuje napredni paket?',
    'Kako dolgo traja izdelava?',
    'Delate tudi spletne trgovine?'
  ];

  // Vsa pravila so pod .tsi- predpono, da se ne zaletijo s temo strani.
  // Lastnosti so navedene izrecno, ker tema sicer podeduje svoje.
  var CSS = [
    '.tsi-w{--tsi-ink:#111;--tsi-muted:#6f6f6f;--tsi-line:#e6e6e6;--tsi-accent:#bf6c2c;--tsi-bubble:#f5f4f2;',
    'max-width:720px;margin:0 auto;font:16px/1.6 -apple-system,"Segoe UI",Roboto,system-ui,sans-serif;color:var(--tsi-ink);box-sizing:border-box}',
    '.tsi-w *{box-sizing:border-box}',
    '.tsi-log{border:1px solid var(--tsi-line);border-radius:14px;padding:20px;height:420px;overflow-y:auto;background:#fff}',
    '.tsi-msg{margin:0 0 16px;display:flex;flex-direction:column;gap:5px}',
    '.tsi-who{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--tsi-muted);margin:0}',
    '.tsi-body{white-space:pre-wrap;margin:0;color:var(--tsi-ink)}',
    '.tsi-msg.tsi-user .tsi-body{background:var(--tsi-bubble);padding:10px 15px;border-radius:14px;align-self:flex-start;max-width:85%}',
    '.tsi-msg.tsi-err .tsi-body{color:#b3261e}',
    '.tsi-form{display:flex;gap:10px;margin:16px 0 0}',
    '.tsi-in{flex:1;padding:14px 18px;border:1px solid var(--tsi-line);border-radius:999px;font:inherit;color:var(--tsi-ink);background:#fff;min-width:0}',
    '.tsi-in:focus{outline:none;border-color:var(--tsi-ink)}',
    '.tsi-send{padding:14px 30px;border:0;border-radius:999px;background:var(--tsi-ink);color:#fff;font:inherit;cursor:pointer}',
    '.tsi-send[disabled]{opacity:.45;cursor:default}',
    '.tsi-chips{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0 0}',
    '.tsi-chip{background:transparent;border:1px solid var(--tsi-line);color:var(--tsi-accent);font:inherit;font-size:14px;padding:8px 16px;border-radius:999px;cursor:pointer}',
    '.tsi-chip:hover{border-color:var(--tsi-accent)}',
    '.tsi-note{font-size:12px;color:var(--tsi-muted);margin:14px 0 0}',
    '@media(max-width:520px){.tsi-send{padding:14px 20px}.tsi-log{height:340px}}'
  ].join('');

  function vstavi() {
    var posoda = document.getElementById('tatjana-chat');
    if (!posoda || posoda.getAttribute('data-tsi-vgrajen')) { return; }
    posoda.setAttribute('data-tsi-vgrajen', '1');

    var slog = document.createElement('style');
    slog.textContent = CSS;
    document.head.appendChild(slog);

    var w = document.createElement('div');
    w.className = 'tsi-w';
    w.innerHTML =
      '<div class="tsi-log" aria-live="polite"></div>' +
      '<form class="tsi-form">' +
        '<input type="text" class="tsi-in" placeholder="Napišite vprašanje…" autocomplete="off" required>' +
        '<button type="submit" class="tsi-send">Pošlji</button>' +
      '</form>' +
      '<div class="tsi-chips"></div>' +
      '<p class="tsi-note">Odgovarja samodejni asistent. Za zavezujočo ponudbo pustite kontakt.</p>';
    posoda.appendChild(w);

    var log = w.querySelector('.tsi-log');
    var form = w.querySelector('.tsi-form');
    var vnos = w.querySelector('.tsi-in');
    var gumb = w.querySelector('.tsi-send');
    var chips = w.querySelector('.tsi-chips');
    var zgodovina = [];

    function sporocilo(vloga, besedilo) {
      var el = document.createElement('div');
      el.className = 'tsi-msg' + (vloga === 'user' ? ' tsi-user' : vloga === 'err' ? ' tsi-err' : '');

      var kdo = document.createElement('p');
      kdo.className = 'tsi-who';
      kdo.textContent = vloga === 'user' ? 'Vi' : vloga === 'err' ? 'Napaka' : IME;

      var telo = document.createElement('p');
      telo.className = 'tsi-body';
      telo.textContent = besedilo;

      el.appendChild(kdo);
      el.appendChild(telo);
      log.appendChild(el);
      log.scrollTop = log.scrollHeight;
    }

    function poslji(besedilo) {
      sporocilo('user', besedilo);
      zgodovina.push({ role: 'user', content: besedilo });
      gumb.disabled = true;

      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: zgodovina })
      })
        .then(function (r) { return r.json(); })
        .then(function (p) {
          if (!p.success) {
            sporocilo('err', p.error || 'Prišlo je do napake.');
            return;
          }
          sporocilo('bot', p.data.reply);
          zgodovina.push({ role: 'assistant', content: p.data.reply });
        })
        .catch(function () {
          sporocilo('err', 'Povezava ni uspela. Poskusite znova.');
        })
        .then(function () {
          gumb.disabled = false;
          vnos.focus();
        });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var t = vnos.value.trim();
      if (!t) { return; }
      vnos.value = '';
      poslji(t);
    });

    VZORCI.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'tsi-chip';
      b.textContent = t;
      b.addEventListener('click', function () { poslji(t); });
      chips.appendChild(b);
    });

    sporocilo('bot', UVOD);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', vstavi);
  } else {
    vstavi();
  }
})();
