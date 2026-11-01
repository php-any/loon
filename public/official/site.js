'use strict';

const $ = (selector, root = document) => root.querySelector(selector);
let toastTimer;
function toast(message) {
  const element = $('#toast');
  element.textContent = message;
  element.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { element.hidden = true; }, 3000);
}

document.querySelectorAll('[data-copy-target]').forEach(button => {
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(document.getElementById(button.dataset.copyTarget).textContent);
      toast('已复制到剪贴板');
    } catch { toast('浏览器未允许复制，请选中文字后手动复制。'); }
  });
});
