/* Password strength meter — attach to any <input data-meter> */
(function () {
  'use strict';

  var LABELS = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];

  function score(pw) {
    if (!pw) return 0;
    var s = 0;
    if (pw.length >= 8) s++;
    if (pw.length >= 12) s++;
    if (/[A-Za-z]/.test(pw) && /[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    if (/^[A-Za-z]+$/.test(pw) || /^[0-9]+$/.test(pw)) s = Math.min(s, 1);
    return Math.min(s, 4);
  }

  document.querySelectorAll('input[data-meter]').forEach(function (input) {
    var host = (input.closest('.pw-wrap') || input).parentElement;
    var meter = document.createElement('div');
    meter.className = 'pw-meter';
    meter.innerHTML = '<span class="pw-meter-track"><span class="pw-meter-bar"></span></span>' +
                      '<span class="pw-meter-text">Password strength</span>';
    host.insertBefore(meter, input.closest('.pw-wrap') ? input.closest('.pw-wrap').nextSibling : input.nextSibling);

    function update() {
      var lvl = score(input.value);
      meter.setAttribute('data-level', String(lvl));
      meter.querySelector('.pw-meter-bar').style.width = (lvl * 25) + '%';
      meter.querySelector('.pw-meter-text').textContent = input.value ? LABELS[lvl] : 'Password strength';
    }

    input.addEventListener('input', update);
    update();
  });
})();
