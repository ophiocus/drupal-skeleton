/**
 * @file
 * Fills ad slots in the browser, only after the reader's advertising grant.
 *
 * The page is the same for every reader: each slot shows the site's blocked
 * banner, and what may fill it waits in an inert <template> (the browser
 * requests none of its images or scripts). Until window[global][property]
 * is exactly true, nothing here requests a creative, a network script or the
 * beacon. After the grant each slot takes, in order: a booking in force today
 * (an exclusive one first), a network unit (providers in their configured
 * order, within each provider's per-page limit), a house creative.
 *
 * A slot counts as viewed once at least half of it has been on screen for one
 * continuous second. On pagehide one beacon carries every viewed
 * {slot, creative} pair (a network fill is "provider:<id>") and every network
 * unit that came back unfilled. Nothing identifies the reader.
 *
 * Extension point: Drupal.adInventory.handlers[name] = (unit, entry) => {}
 * fills a network unit for providers whose handler() returns that name.
 */
((Drupal, drupalSettings, once) => {
  const settings = drupalSettings.adInventory || {};
  const consent = settings.consent || {};
  const pending = [];
  const loaders = {};
  const perProvider = {};
  const viewed = new Map();
  const unfilled = new Map();
  let started = false;
  let sent = false;

  /**
   * Whether the site's consent signal holds the advertising grant.
   */
  function granted() {
    const holder = consent.global ? window[consent.global] : null;
    return !!(holder && consent.property && holder[consent.property] === true);
  }

  /**
   * Today in the site's time zone, as YYYY-MM-DD.
   */
  function today() {
    try {
      const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: settings.timezone || undefined,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
      }).formatToParts(new Date());
      const get = (type) => (parts.find((p) => p.type === type) || {}).value;
      return `${get('year')}-${get('month')}-${get('day')}`;
    } catch (e) {
      return new Date().toISOString().slice(0, 10);
    }
  }

  /**
   * Loads a script once per page.
   */
  function load(url, crossorigin) {
    if (!loaders[url]) {
      loaders[url] = new Promise((resolve) => {
        const script = document.createElement('script');
        script.async = true;
        script.src = url;
        if (crossorigin) {
          script.crossOrigin = 'anonymous';
        }
        script.onload = resolve;
        script.onerror = resolve;
        document.head.appendChild(script);
      });
    }
    return loaders[url];
  }

  /**
   * Scripts inserted from a template do not run; recreate them so they do.
   */
  function activate(root) {
    root.querySelectorAll('script').forEach((old) => {
      const script = document.createElement('script');
      Array.from(old.attributes).forEach((attr) =>
        script.setAttribute(attr.name, attr.value),
      );
      script.text = old.textContent;
      old.replaceWith(script);
    });
  }

  const handlers = {
    generic(unit, entry) {
      activate(unit);
      if (entry.dataset.adLoader) {
        load(entry.dataset.adLoader, false);
      }
    },
    adsbygoogle(unit, entry) {
      if (entry.dataset.adLoader) {
        load(entry.dataset.adLoader, true);
      }
      (window.adsbygoogle = window.adsbygoogle || []).push({});
    },
  };

  Drupal.adInventory = Drupal.adInventory || {};
  Drupal.adInventory.handlers = Object.assign(
    handlers,
    Drupal.adInventory.handlers || {},
  );

  /**
   * One random entry of a list, or null.
   */
  function any(list) {
    return list.length ? list[Math.floor(Math.random() * list.length)] : null;
  }

  /**
   * Counts a slot as viewed after one second at half or more on screen.
   */
  function watch(slot) {
    if (!('IntersectionObserver' in window)) {
      return;
    }
    let timer = null;
    const observer = new IntersectionObserver(
      (records) => {
        records.forEach((record) => {
          if (record.intersectionRatio >= 0.5) {
            if (timer === null) {
              timer = window.setTimeout(() => {
                timer = null;
                if (document.visibilityState !== 'visible') {
                  return;
                }
                const pair = [slot.dataset.adSlot, slot.dataset.adShown];
                viewed.set(pair.join('|'), pair);
                observer.disconnect();
              }, 1000);
            }
          } else if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
          }
        });
      },
      { threshold: [0, 0.5, 1] },
    );
    observer.observe(slot.querySelector('.ad-slot__box') || slot);
  }

  /**
   * Notices a network unit that came back unfilled (AdSense marks its <ins>
   * data-ad-status="unfilled"); the house creative behind it then shows.
   */
  function watchUnfilled(slot, entry, behind) {
    const ins = entry.querySelector('ins');
    if (!ins || !('MutationObserver' in window)) {
      return;
    }
    const observer = new MutationObserver(() => {
      if (ins.getAttribute('data-ad-status') === 'unfilled') {
        const pair = [slot.dataset.adSlot, entry.dataset.adCreative];
        unfilled.set(pair.join('|'), pair);
        if (behind) {
          slot.dataset.adShown = behind.dataset.adCreative;
          slot.classList.add('is-unfilled');
        }
        observer.disconnect();
      }
    });
    observer.observe(ins, {
      attributes: true,
      attributeFilter: ['data-ad-status'],
    });
  }

  /**
   * Fills one slot.
   */
  function fill(slot) {
    const template = slot.querySelector('template.ad-slot__fill');
    const box = slot.querySelector('.ad-slot__box');
    if (!template || !box) {
      return;
    }
    const entries = Array.from(
      template.content.querySelectorAll('.ad-slot__entry'),
    );
    const day = today();
    const booked = entries.filter(
      (e) =>
        e.dataset.adKind === 'booking' &&
        e.dataset.start <= day &&
        e.dataset.end >= day,
    );
    const exclusive = booked.filter((e) => e.dataset.exclusive === '1');
    const house = any(entries.filter((e) => e.dataset.adKind === 'house'));
    let chosen = any(exclusive.length ? exclusive : booked);
    if (!chosen) {
      chosen =
        entries.find((e) => {
          if (e.dataset.adKind !== 'network') {
            return false;
          }
          const max = parseInt(e.dataset.adMax || '0', 10);
          const used = perProvider[e.dataset.adProvider] || 0;
          if (max > 0 && used >= max) {
            return false;
          }
          perProvider[e.dataset.adProvider] = used + 1;
          return true;
        }) || null;
    }
    if (!chosen) {
      chosen = house;
    }
    box.replaceChildren();
    slot.classList.add('is-granted');
    if (!chosen) {
      // Granted, nothing to show: the box stays reserved and empty.
      return;
    }
    const node = document.importNode(chosen, true);
    let behind = null;
    if (
      chosen.dataset.adKind === 'network' &&
      house &&
      chosen.dataset.adHouseBehind === '1'
    ) {
      behind = document.importNode(house, true);
      behind.classList.add('ad-slot__behind');
      box.appendChild(behind);
    }
    box.appendChild(node);
    const caption = slot.querySelector('.ad-slot__caption');
    if (caption) {
      caption.textContent = node.dataset.caption || '';
    }
    slot.dataset.adShown = node.dataset.adCreative;
    slot.classList.add('is-filled');
    if (chosen.dataset.adKind === 'network') {
      const handler =
        Drupal.adInventory.handlers[node.dataset.adHandler] ||
        Drupal.adInventory.handlers.generic;
      handler(node.querySelector('.ad-slot__unit') || node, node);
      watchUnfilled(slot, node, behind);
    }
    watch(slot);
  }

  /**
   * Sends the page's counts once, when the page goes away.
   */
  function beacon() {
    if (sent || !settings.beacon || !navigator.sendBeacon) {
      return;
    }
    if (!viewed.size && !unfilled.size) {
      return;
    }
    sent = true;
    navigator.sendBeacon(
      settings.beacon,
      JSON.stringify({
        v: Array.from(viewed.values()),
        u: Array.from(unfilled.values()),
      }),
    );
  }

  /**
   * Starts filling, after the grant.
   */
  function start() {
    if (started || !granted()) {
      return;
    }
    started = true;
    pending.splice(0).forEach(fill);
    window.addEventListener('pagehide', beacon);
  }

  if (consent.event) {
    document.addEventListener(consent.event, start);
  }

  Drupal.behaviors.adInventory = {
    attach(context) {
      once('ad-inventory', '.ad-slot[data-ad-slot]', context).forEach(
        (slot) => {
          if (started) {
            fill(slot);
          } else {
            pending.push(slot);
          }
        },
      );
      start();
    },
  };
})(Drupal, drupalSettings, once);
