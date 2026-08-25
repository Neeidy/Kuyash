/* Kuyash app shell — mobile sidebar toggle + destructive-action confirm.
   Vanilla JS, no frameworks. */
(function () {
  'use strict';

  /* forms marked data-confirm ask before submitting (e.g. reject = cancel
     the whole run); without JS the form still posts — CSRF + server guards hold */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  var toggle = document.querySelector('[data-sidebar-toggle]');
  var scrim = document.querySelector('[data-sidebar-scrim]');
  if (!toggle) return;

  function setOpen(open) {
    document.body.classList.toggle('sidebar-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  toggle.addEventListener('click', function () {
    setOpen(!document.body.classList.contains('sidebar-open'));
  });
  if (scrim) {
    scrim.addEventListener('click', function () { setOpen(false); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
})();

/* ---- styled file picker: reflect the chosen file's name next to the button.
   Progressive enhancement only — the label opens the picker without JS; this
   just replaces the "no photo chosen yet" placeholder once one is picked. ---- */
(function filePickName() {
  'use strict';

  var picks = document.querySelectorAll('.filepick input[type="file"]');
  Array.prototype.forEach.call(picks, function (input) {
    var name = input.closest('.filepick').querySelector('[data-file-name]');
    if (!name) return;
    var placeholder = name.textContent;

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) {
        name.textContent = placeholder;
        name.classList.remove('is-set');
        return;
      }
      name.textContent = file.name + ' (' + (file.size / 1048576).toFixed(1) + ' MB)';
      name.classList.add('is-set');
    });
  });
})();

/* Live character count for the post-text editor (Phase 25).

   It measures the SAME thing the server does — the body, plus the AI notice
   where one applies, plus the tag block — because that is what actually reaches
   the platform. A counter that measured only the body would understate every
   number, and the one on screen would disagree with the one that was saved.

   Progressive enhancement only: the server renders a correct count already, so
   with scripting off nothing is lost. */
/* Post-text editor: live character/tag counts, and a guard against approving
   text you typed but never saved. Everything here is scoped to ONE editor —
   the approval queue renders one per waiting post, and a document-wide lookup
   would make the second card drive the first card's numbers. */
(function () {
  'use strict';

  var editors = document.querySelectorAll('.textedit');
  if (!editors.length) return;

  /* mirrors Kuyash\Publish\Disclosure::compose — appended on its own final
     line, and skipped when the body already carries it */
  function withDisclosure(body, line) {
    var trimmed = body.replace(/\s+$/, '');
    if (!line) return trimmed;
    if (trimmed === '') return line;
    var needle = line.trim().toLowerCase();
    var lines = trimmed.split(/\r\n|\r|\n/);
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].trim().toLowerCase() === needle) return trimmed;
    }
    return trimmed + '\n' + line;
  }

  /* the template already carries the limit, rendered server-side, so the
     translated sentence is never rebuilt in JS — only its number changes */
  function fill(el, template, n) {
    el.textContent = (template || '{n}').replace('{n}', String(n));
  }

  /* same two thresholds the server applies (PlatformLimits): over the limit, or
     approaching it — otherwise the promised "warns as you get close" only ever
     appeared after a save */
  function mark(el, n, limit) {
    var near = parseInt(el.getAttribute('data-near') || '0', 10);
    var over = limit > 0 && n > limit;
    var close = !over && near > 0 && n >= near;
    el.classList.toggle('textedit__count--over', over);
    el.classList.toggle('textedit__count--near', close);
    /* Announced only while it MATTERS. A live region on a plain counter reads
       the number out on every keystroke; the thing worth interrupting for is
       crossing the limit, and the count itself is reachable any time through
       the field's aria-describedby. */
    if (over || close) {
      el.setAttribute('aria-live', 'polite');
    } else {
      el.removeAttribute('aria-live');
    }
  }

  function setup(editor) {
    var boxes = editor.querySelectorAll('[data-count-for]');
    var tagField = editor.querySelector('[data-count-tags]');
    var tagOut = editor.querySelector('[data-count-tags-of]');

    function tags() {
      if (!tagField) return [];
      return tagField.value.split(/[\s,]+/).filter(Boolean).map(function (t) {
        return t.charAt(0) === '#' ? t : '#' + t;
      });
    }

    var outs = editor.querySelectorAll('[data-count-of]');

    /* matched by attribute value rather than built into a selector string: a
       key carrying a quote would make the selector a SyntaxError and silently
       kill both counters */
    function counterFor(platform) {
      for (var i = 0; i < outs.length; i++) {
        if (outs[i].getAttribute('data-count-of') === platform) return outs[i];
      }
      return null;
    }

    function render(box) {
      var out = counterFor(box.getAttribute('data-count-for'));
      if (!out) return;
      var text = withDisclosure(box.value, box.getAttribute('data-disclosure') || '');
      var list = tags();
      if (list.length) text = text.replace(/\s+$/, '') + '\n\n' + list.join(' ');

      var n = Array.from(text).length;   // count characters, not UTF-16 units
      var limit = parseInt(box.getAttribute('data-limit') || '0', 10);
      fill(out, limit > 0 ? out.getAttribute('data-t-known') : out.getAttribute('data-t-unknown'), n);
      mark(out, n, limit);
    }

    function renderTags() {
      if (!tagOut) return;
      var n = tags().length;
      var limit = parseInt(tagOut.getAttribute('data-limit') || '0', 10);
      fill(tagOut, limit > 0 ? tagOut.getAttribute('data-t-known') : tagOut.getAttribute('data-t-unknown'), n);
      mark(tagOut, n, limit);
    }

    function renderAll() {
      Array.prototype.forEach.call(boxes, render);
      renderTags();
    }

    /* Typed-but-unsaved text is the trap this phase would otherwise create:
       "Save the text" and "Approve & publish" are two sibling forms, and
       approving without saving would quietly publish the AI's words instead of
       yours. Mark the editor dirty and let the approve form ask. */
    function markDirty() {
      editor.setAttribute('data-dirty', '1');
    }

    Array.prototype.forEach.call(boxes, function (box) {
      box.addEventListener('input', function () { render(box); markDirty(); });
    });
    if (tagField) {
      tagField.addEventListener('input', function () { renderAll(); markDirty(); });
    }
    renderAll();
  }

  Array.prototype.forEach.call(editors, setup);

  /* Leaving the page loses an unsaved edit just as thoroughly as approving
     over it — and the run screen has no approve button to hang the other guard
     on. The browser supplies its own wording here; ours is not used. */
  window.addEventListener('beforeunload', function (e) {
    if (!document.querySelector('.textedit[data-dirty]')) return;
    e.preventDefault();
    e.returnValue = '';
  });

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-needs-saved-text]');
    if (!form) return;
    var card = form.closest('[data-approve-card]');
    var editor = card && card.querySelector('.textedit[data-dirty]');
    if (editor && !window.confirm(form.getAttribute('data-needs-saved-text'))) {
      e.preventDefault();
      return;
    }
    clean(card && card.querySelector('.textedit'));
  });

  /* submitting IS the save — the unload guard must not then ask again. Scoped
     to the editor that was submitted: clearing every card's flag meant saving
     card B silenced the warning for card A's unsaved text, which is the loss
     the unload guard exists to prevent. */
  document.addEventListener('submit', function (e) {
    clean(e.target.closest('.textedit'));
  });

  function clean(el) {
    if (el) el.removeAttribute('data-dirty');
  }
})();
