/* سلوك عام: تأكيد الإجراءات الحساسة ومنع الإرسال المزدوج. */
(function () {
  'use strict';
  document.querySelectorAll('[data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!window.confirm(b.dataset.confirm)) e.preventDefault(); });
  });
  document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) { setTimeout(function () { b.disabled = true; b.textContent = 'جارٍ الرفع والمعالجة...'; }, 0); }
    });
  });
})();
