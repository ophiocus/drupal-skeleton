/**
 * @file
 * The browser half of a banner space.
 *
 * The page arrives the same for everyone: each space is empty, with no text,
 * and its creative and its "turned off" notice wait inert in <template>s. This
 * file reads the site's own consent signal (a global object and a DOM event,
 * named in the module's settings). Granted: it adds the label and swaps the
 * creative in. Refused: it adds the label and the notice, with the link that
 * reopens the choice. Unanswered means no, and the space stays empty. Nothing
 * is fetched before a grant: no image, no provider script, no count.
 *
 * A space sold to several clients carries every placement and creative; the
 * browser asks the site once which goes next (the fewest views this week)
 * and shows that one. House banners are picked at random.
 *
 * After the swap a space counts as seen when at least half of it stays on
 * screen for one second. Every space seen is sent once, in one beacon, when
 * the page is left. The beacon carries the space, the creative and the
 * source (for a booking, "booking:<id>"); nothing about the reader.
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
   * Whether the reader refused it, as opposed to not having answered.
   */
  function refused() {
    const signal = window[consent.global || 'adConsent'];
    return !!(signal && signal[consent.category || 'ads'] === false);
  }

  /**
   * Puts the label on a space, once it shows something.
   */
  function labelled(space) {
    const text = space.dataset.adLabel;
    const line = space.querySelector('.ad-inventory__label');
    if (space.dataset.adLabelled || !text || !line) {
      return;
    }
    space.dataset.adLabelled = '1';
    line.prepend(document.createTextNode(line.childElementCount ? `${text} ` : text));
    space.setAttribute('aria-label', text);
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
   * The fill templates of a space.
   */
  function templatesOf(space) {
    return Array.from(space.querySelectorAll('template.ad-inventory__fill'));
  }

  /**
   * Booking ids among a space's templates, once each.
   */
  function bookingsOf(space) {
    const ids = templatesOf(space).map((t) => Number(t.dataset.adBooking || 0)).filter((id) => id > 0);
    return Array.from(new Set(ids));
  }

  /**
   * Whether the site must say which template goes next: several of bookings.
   */
  function needsPick(space) {
    return templatesOf(space).filter((t) => Number(t.dataset.adBooking || 0) > 0).length > 1;
  }

  /**
   * Swaps a space's creative in.
   *
   * With a pick from the site ([booking, creative]) that template shows;
   * otherwise one at random (house banners, or the site did not answer).
   */
  function fill(space, pick) {
    if (space.dataset.adFilled) {
      return;
    }
    const templates = templatesOf(space);
    let template = null;
    if (Array.isArray(pick)) {
      template = templates.find((t) => Number(t.dataset.adBooking || 0) === pick[0] && Number(t.dataset.adCreative || 0) === pick[1])
        || templates.find((t) => Number(t.dataset.adBooking || 0) === pick[0])
        || null;
    }
    template = template || templates[Math.floor(Math.random() * templates.length)];
    const box = space.querySelector('.ad-inventory__box');
    if (!template || !box) {
      return;
    }
    space.dataset.adCreative = template.dataset.adCreative || '0';
    if (template.dataset.adSource) {
      space.dataset.adSource = template.dataset.adSource;
    }
    space.dataset.adFilled = '1';
    box.replaceChildren(template.content.cloneNode(true));
    activate(box);
    labelled(space);
    space.classList.remove('ad-inventory--refused');
    space.classList.add('ad-inventory--filled');
    watch(space);
  }

  /**
   * Shows a space's "turned off" notice. A space already filled stays filled.
   */
  function notice(space) {
    const template = space.querySelector('template.ad-inventory__notice');
    const box = space.querySelector('.ad-inventory__box');
    if (space.dataset.adFilled || !template || !box || box.childElementCount) {
      return;
    }
    box.replaceChildren(template.content.cloneNode(true));
    labelled(space);
    space.classList.add('ad-inventory--refused');
  }

  /**
   * Shows the notice in every space on the page.
   */
  function noticeAll() {
    document.querySelectorAll('.ad-inventory[data-ad-slot]').forEach(notice);
  }

  /**
   * Asks the site which placement and creative each space shows next.
   *
   * One small request for the whole page, only when some space has more than
   * one to choose from: the answer is the one with the fewest views this
   * week. It carries the space and booking ids printed on the page; nothing
   * about the reader. Unanswered within two seconds, the browser picks.
   */
  function ask(spaces) {
    const bySlot = new Map();
    spaces.forEach((space) => {
      bySlot.set(space.dataset.adSlot, Array.from(new Set([...(bySlot.get(space.dataset.adSlot) || []), ...bookingsOf(space)])));
    });
    if (!settings.pick || !window.fetch) {
      return Promise.resolve({});
    }
    const controller = window.AbortController ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), 2000) : null;
    return fetch(settings.pick, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ p: Array.from(bySlot.entries()) }),
      signal: controller ? controller.signal : undefined,
    })
      .then((response) => (response.ok ? response.json() : {}))
      .catch(() => ({}))
      .finally(() => timer && clearTimeout(timer));
  }

  /**
   * Fills every space on the page.
   */
  function fillAll() {
    loadScripts();
    const waiting = [];
    document.querySelectorAll('.ad-inventory[data-ad-slot]').forEach((space) => {
      if (space.dataset.adFilled || space.dataset.adAsking) {
        return;
      }
      if (needsPick(space)) {
        space.dataset.adAsking = '1';
        waiting.push(space);
      }
      else {
        fill(space);
      }
    });
    if (waiting.length) {
      ask(waiting).then((answer) => {
        waiting.forEach((space) => {
          delete space.dataset.adAsking;
          fill(space, answer && answer[space.dataset.adSlot]);
        });
      });
    }
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
      else if (refused()) {
        noticeAll();
      }
    },
  };

  document.addEventListener(consent.event || 'ad:consent', (event) => {
    const choice = event.detail || window[consent.global || 'adConsent'] || {};
    if (choice[consent.category || 'ads'] === true) {
      fillAll();
    }
    else if (choice[consent.category || 'ads'] === false) {
      noticeAll();
    }
  });
  window.addEventListener('pagehide', send);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      send();
    }
  });
})(Drupal, drupalSettings, once);
