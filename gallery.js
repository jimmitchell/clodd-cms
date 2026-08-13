/* Tiled-gallery lightbox. Loaded only on pages that contain a gallery. */
(function () {
'use strict';

// ── Gallery lightbox ──────────────────────────────────────────────────────────
var glItems  = [];
var glCursor = 0;

var glOverlay = document.createElement('div');
glOverlay.className = 'gallery-lightbox';
glOverlay.setAttribute('role', 'dialog');
glOverlay.setAttribute('aria-modal', 'true');
glOverlay.setAttribute('aria-label', 'Gallery');

var glImg   = document.createElement('img');
glImg.className = 'gallery-lightbox__img';
glImg.setAttribute('alt', '');

var glClose = document.createElement('button');
glClose.className = 'gallery-lightbox__btn gallery-lightbox__close';
glClose.setAttribute('aria-label', 'Close');
glClose.innerHTML = '&times;';

var glPrev = document.createElement('button');
glPrev.className = 'gallery-lightbox__btn gallery-lightbox__prev';
glPrev.setAttribute('aria-label', 'Previous image');
glPrev.innerHTML = '&#8249;';

var glNext = document.createElement('button');
glNext.className = 'gallery-lightbox__btn gallery-lightbox__next';
glNext.setAttribute('aria-label', 'Next image');
glNext.innerHTML = '&#8250;';

glOverlay.appendChild(glImg);
glOverlay.appendChild(glClose);
glOverlay.appendChild(glPrev);
glOverlay.appendChild(glNext);
document.body.appendChild(glOverlay);

function glShow(index) {
    glCursor = (index + glItems.length) % glItems.length;
    glImg.src = glItems[glCursor].href;
    glImg.alt = glItems[glCursor].alt;
    glOverlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    glClose.focus();
}

function glClose_() {
    glOverlay.classList.remove('is-open');
    document.body.style.overflow = '';
    glImg.src = '';
}

// Collect all gallery items across all galleries on the page. Two blocks carry
// data-gallery: the tiled [gallery] shortcode, and a photo post's attached
// u-photo rows (.post__photos), which lay out differently but light up the same.
document.querySelectorAll('[data-gallery] [data-gallery-item]').forEach(function (a) {
    glItems.push({ href: a.href, alt: (a.querySelector('img') || {}).alt || '' });
    var idx = glItems.length - 1;
    a.addEventListener('click', function (e) {
        e.preventDefault();
        glShow(idx);
    });
});

// A lone picture has nowhere to step to, so the arrows would only wrap onto
// itself. Photo posts routinely carry one photo; the tiled gallery can too.
if (glItems.length < 2) {
    glPrev.hidden = true;
    glNext.hidden = true;
}

glClose.addEventListener('click', glClose_);
glPrev.addEventListener('click', function () { glShow(glCursor - 1); });
glNext.addEventListener('click', function () { glShow(glCursor + 1); });

glOverlay.addEventListener('click', function (e) {
    if (e.target === glOverlay) glClose_();
});

document.addEventListener('keydown', function (e) {
    if (!glOverlay.classList.contains('is-open')) return;
    if (e.key === 'Escape')      glClose_();
    if (e.key === 'ArrowLeft')  glShow(glCursor - 1);
    if (e.key === 'ArrowRight') glShow(glCursor + 1);
});

}());
