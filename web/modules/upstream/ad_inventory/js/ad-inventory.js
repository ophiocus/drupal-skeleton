/**
 * @file
 * The browser half of a banner space.
 *
 * The page arrives the same for everyone: each space shows the "turned off"
 * notice, and its creative waits inert in a <template>. This file swaps the
 * creative in only once the reader's advertising consent is granted, read
 * from the site's own consent signal (a global object and a DOM event,
 * named in the module's settings). Unanswered means no. Nothing is fetched
 * before that: no image, no provider script, no count.
 *
 * After the swap a space counts as seen when at least half of it stays on
 * screen for one second. Every space seen is sent once, in one beacon, when
 * the page is left. The beacon carries the space, the creative and the
 * source; nothing about the reader.
 */
((Drupal, drupalSettings, once) => {
  const settings = drupalSettings.adInventory || {};
  const consent = settings.consent || {};
  const seen = new Map();
  const loaded = new Set();
  let observer = null;

  /**
   * Whether the reader granted advertising consent, as the site reports it.
   */
  function granted() {
    const signal = window[consent.global || 'adConsent'];
    return !!(signal && signal[consent.category || 'ads'] === true);
  }

  /**
   * Loads each provider script once.
   */
  function loadScripts() {
    (settings.loaders || []).forEach((src) => {
      if (loaded.has(src) || !/^https:\/\//.test(src)) {
        return;
      }
      loaded.add(src);
      const script = document.createElement('script');
      script.async = true;
      script.src = src;
      script.crossOrigin = 'anonymous';
      document.head.appendChild(script);
    });
  }

  /**
   * Recreates scripts copied out of a template so that they run.
   */
  function activate(root) {
    root.querySelectorAll('script').forEach((old) => {
      const script = document.createElement('script');
      Array.from(old.attributes).forEach((a) => script.setAttribute(a.name, a.value));
      script.textContent = old.textContent;
      old.replaceWith(script);
    });
  }

  /**
   * Counts a space once it has been half on screen for a second.
   */
  function watch(space) {
    if (!('IntersectionObserver' in window)) {
      return;
    }
    if (!observer) {
      const timers = new WeakMap();
      observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          const el = entry.target;
          if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
            if (!timers.has(el)) {
              timers.set(el, setTimeout(() => {
                const key = [el.dataset.adSlot, Number(el.dataset.adCreative || 0), el.dataset.adSource];
                seen.set(key.join('|'), key);
                observer.unobserve(el);
              }, 1000));
            }
          }
          else if (timers.has(el)) {
            clearTimeout(timers.get(el));
            timers.delete(el);
          }
        });
      }, { threshold: [0, 0.5, 1] });
    }
    observer.observe(space);
  }

  /**
   * Swaps a space's creative in.
   */
  function fill(space) {
    if (space.dataset.adFilled) {
      return;
    }
    // House banners come as several templates: show one at random, and
    // count that one.
    const templates = space.querySelectorAll('template.ad-inventory__fill');
    const template = templates[Math.floor(Math.random() * templates.length)];
    const box = space.querySelector('.ad-inventory__box');
    if (!template || !box) {
      return;
    }
    space.dataset.adCreative = template.dataset.adCreative || '0';
    space.dataset.adFilled = '1';
    box.replaceChildren(template.content.cloneNode(true));
    activate(box);
    space.classList.add('ad-inventory--filled');
    watch(space);
  }

  /**
   * Fills every space on the page.
   */
  function fillAll() {
    loadScripts();
    document.querySelectorAll('.ad-inventory[data-ad-slot]').forEach(fill);
  }

  /**
   * Sends what was seen, once, as the page is left.
   */
  function send() {
    if (!seen.size || !settings.beacon || !navigator.sendBeacon) {
      return;
    }
    const body = JSON.stringify({ v: Array.from(seen.values()) });
    seen.clear();
    navigator.sendBeacon(settings.beacon, new Blob([body], { type: 'application/json' }));
  }

  Drupal.behaviors.adInventory = {
    attach(context) {
      const spaces = once('ad-inventory', '.ad-inventory[data-ad-slot]', context);
      if (!spaces.length) {
        return;
      }
      if (granted()) {
        fillAll();
      }
    },
  };

  document.addEventListener(consent.event || 'ad:consent', (event) => {
    const choice = event.detail || window[consent.global || 'adConsent'] || {};
    if (choice[consent.category || 'ads'] === true) {
      fillAll();
    }
  });
  window.addEventListener('pagehide', send);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      send();
    }
  });
})(Drupal, drupalSettings, once);
