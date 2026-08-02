function togglePass(id, btn) {
      var input = document.getElementById(id);
      if (!input) return;

      if (input.type === 'password') {
        input.type = 'text';
        input.style.letterSpacing = '0';
        btn.textContent = 'hide';
      } else {
        input.type = 'password';
        input.style.letterSpacing = '2px';
        btn.textContent = 'show';
      }
    }
