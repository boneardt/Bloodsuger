(function () {
  var root = document.documentElement;
  var button = document.getElementById('theme-toggle');
  if (!button) {
    return;
  }

  function currentTheme() {
    var attr = root.getAttribute('data-theme');
    if (attr === 'light' || attr === 'dark') {
      return attr;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  button.addEventListener('click', function () {
    var next = currentTheme() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try {
      localStorage.setItem('bs-theme', next);
    } catch (e) {
      // Private browsing / blocked storage — theme just won't persist across reloads.
    }
  });
})();
