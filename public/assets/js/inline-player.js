/* Kuyash inline approval player (Phase 17). Play a draft preview RIGHT IN THE
   approval card — it never opens the drawer (that was the old bug). The <video>
   is preload="none", so it makes no network request until the user hits play
   (keeps the media-free visual seed free of 404s). Real timeupdate drives the
   progress bar; reduced-motion is irrelevant here (progress reflects real time).
   Vanilla JS, no framework. */
(function () {
  'use strict';
  var players = Array.prototype.slice.call(document.querySelectorAll('[data-inline-player]'));
  if (!players.length) return;

  players.forEach(function (p) {
    var video = p.querySelector('.inline-player__video');
    var playBtn = p.querySelector('.inline-player__play');
    var progress = p.querySelector('.inline-player__progress');
    if (!video || !playBtn) return;

    function setProgress() {
      if (!progress || !video.duration || !isFinite(video.duration)) return;
      progress.style.transform = 'scaleX(' + (video.currentTime / video.duration) + ')';
    }
    function toPlaying(on) { p.classList.toggle('is-playing', on); }

    function toggle() {
      if (video.paused) { video.play().catch(function () { /* autoplay/source issue — stay paused */ }); }
      else { video.pause(); }
    }

    playBtn.addEventListener('click', toggle);
    video.addEventListener('click', function () { if (!video.paused) video.pause(); });
    video.addEventListener('play', function () { toPlaying(true); });
    video.addEventListener('pause', function () { toPlaying(false); });
    video.addEventListener('timeupdate', setProgress);
    video.addEventListener('ended', function () {
      toPlaying(false);
      if (progress) progress.style.transform = 'scaleX(0)';
      video.currentTime = 0;
    });
  });
})();
