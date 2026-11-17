(function () {
  const form = document.getElementById('loginForm');
  const email = document.getElementById('email');
  const password = document.getElementById('password');
  const toggle = document.getElementById('togglePassword');
  const clientError = document.getElementById('clientError');
  const emailPattern = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/i;

  function showError(msg) {
    clientError.textContent = msg || '';
  }

  if (toggle && password) {
    toggle.addEventListener('click', function () {
      const isHidden = password.type === 'password';
      password.type = isHidden ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', String(isHidden));
      toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      showError('');
      const emailVal = (email.value || '').trim();
      const passVal = password.value || '';

      if (!emailVal || !passVal) {
        e.preventDefault();
        showError('Both email and password are required.');
        return;
      }
      if (!emailPattern.test(emailVal)) {
        e.preventDefault();
        showError('Please enter a valid institutional email address ending in @edu.ph.');
        email.focus();
        return;
      }
    });

    email.addEventListener('input', function () {
      if (clientError.textContent) showError('');
    });
    password.addEventListener('input', function () {
      if (clientError.textContent) showError('');
    });
  }
})();
