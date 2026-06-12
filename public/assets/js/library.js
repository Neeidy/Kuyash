/* Kuyash library page helpers — vanilla JS, no frameworks.
   1) show the picked file name in the upload box
   2) client-side size pre-check (the server re-validates; this only saves the
      user from waiting out a doomed 200MB POST)
   3) confirm() guard on the delete form */
(function () {
  'use strict';

  var form = document.querySelector('form[data-max-video]');
  if (form) {
    var input = form.querySelector('input[type="file"]');
    var label = form.querySelector('[data-file-label]');
    var warning = form.querySelector('[data-size-warning]');
    var maxVideo = parseInt(form.getAttribute('data-max-video'), 10);
    var maxPhoto = parseInt(form.getAttribute('data-max-photo'), 10);

    function capFor(file) {
      return /^video\//.test(file.type) ? maxVideo : maxPhoto;
    }

    if (input) {
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        if (label) {
          label.textContent = file.name + ' (' + (file.size / 1048576).toFixed(1) + ' MB)';
        }
        if (warning) warning.hidden = file.size <= capFor(file);
      });
    }

    form.addEventListener('submit', function (e) {
      var file = input && input.files && input.files[0];
      if (file && file.size > capFor(file)) {
        e.preventDefault();
        if (warning) warning.hidden = false;
        return;
      }
      // upload-in-progress: a 200MB POST is silent otherwise, and the
      // disabled button prevents double-submit
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.textContent = 'Uploading…';
      }
    });
  }

  var confirmForm = document.querySelector('form[data-confirm]');
  if (confirmForm) {
    confirmForm.addEventListener('submit', function (e) {
      if (!window.confirm(confirmForm.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  }
})();
