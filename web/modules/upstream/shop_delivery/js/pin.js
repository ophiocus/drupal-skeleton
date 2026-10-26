/**
 * @file
 * The delivery pin: geolocation, a pasted link, or an address search.
 *
 * Every way ends the same: the latitude and longitude boxes are filled and
 * the shipping pane is asked to recalculate, which redraws the pane with
 * the maper map of the new pin and the fee for it.
 */
((Drupal, drupalSettings, once) => {
  /**
   * Coordinates in a pasted link or text, latitude first, or null.
   *
   * Google Maps (@lat,lon / q=lat,lon / !3dlat!4dlon), Apple Maps (ll=,
   * q=), OpenStreetMap (mlat=&mlon= or #map=z/lat/lon), WhatsApp's shared
   * location (a Google Maps link) and plain "lat, lon".
   */
  function parse(text) {
    const value = String(text || '').trim();
    const patterns = [
      /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/,
      /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/,
      /[?&](?:q|query|ll|destination|daddr)=(-?\d+(?:\.\d+)?)(?:,|%2C)\s*(-?\d+(?:\.\d+)?)/i,
      /mlat=(-?\d+(?:\.\d+)?)&mlon=(-?\d+(?:\.\d+)?)/,
      /#map=\d+\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/,
      /^(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)$/,
    ];
    for (const pattern of patterns) {
      const match = value.match(pattern);
      if (match) {
        const lat = parseFloat(match[1]);
        const lon = parseFloat(match[2]);
        if (Math.abs(lat) <= 90 && Math.abs(lon) <= 180) {
          return { lat, lon };
        }
      }
    }
    return null;
  }

  /**
   * The address typed in the same form, as one line for the search.
   */
  function address(root) {
    const form = root.closest('form');
    const scope = root.parentElement.closest('fieldset, .form-wrapper, div') || form;
    const pick = (part) => {
      const field =
        scope.querySelector(`[name$="[${part}]"]`) ||
        form.querySelector(`[name*="shipping"][name$="[${part}]"]`);
      if (!field) {
        return '';
      }
      if (field.tagName === 'SELECT') {
        return field.selectedIndex > 0
          ? field.options[field.selectedIndex].text
          : '';
      }
      return field.value.trim();
    };
    return [
      pick('address_line1'),
      pick('dependent_locality'),
      pick('locality'),
      pick('administrative_area'),
      'Colombia',
    ]
      .filter(Boolean)
      .join(', ');
  }

  /**
   * Where the last pin's message and search answers are kept for the tab.
   */
  const MEMORY = 'delivery-pin';

  /**
   * What the customer is told about one search answer.
   */
  function label(hit) {
    return hit.precise
      ? 'Encontrada. Revisa en el mapa que sea tu puerta.'
      : 'Es un punto aproximado (la calle o el barrio, no tu puerta). Revisa el mapa y corrígelo con tu ubicación actual o un enlace de Google Maps.';
  }

  Drupal.behaviors.shopDeliveryPin = {
    attach(context) {
      once('delivery-pin', '[data-delivery-pin]', context).forEach((root) => {
        const lat = root.querySelector('[data-delivery-pin-lat]');
        const lon = root.querySelector('[data-delivery-pin-lon]');
        const status = root.querySelector('[data-delivery-pin-status]');
        const choices = root.querySelector('[data-delivery-pin-choices]');
        const settings = drupalSettings.shopDeliveryPin || {};

        const say = (text) => {
          status.textContent = text;
        };

        // The shipping pane redraws itself (with the new map) after every
        // pin, which would wipe the message and the search's other answers.
        // They are kept for the tab and shown again while the pin is the
        // same one.
        const remember = (state) => {
          try {
            sessionStorage.setItem(MEMORY, JSON.stringify(state));
          } catch (e) {
            // Private mode or storage off: the message just does not return.
          }
        };
        const recalled = () => {
          try {
            return JSON.parse(sessionStorage.getItem(MEMORY) || 'null');
          } catch (e) {
            return null;
          }
        };

        let set;
        const showChoices = (hits) => {
          choices.textContent = '';
          if (!hits || hits.length < 2) {
            return;
          }
          const list = document.createElement('ul');
          hits.forEach((hit) => {
            const item = document.createElement('li');
            const pick = document.createElement('button');
            pick.type = 'button';
            pick.className = 'link';
            pick.textContent = hit.display;
            pick.addEventListener('click', () => set(hit, label(hit), hits));
            item.appendChild(pick);
            list.appendChild(item);
          });
          const title = document.createElement('p');
          title.textContent = '¿No es esa? Elige otra:';
          choices.append(title, list);
        };

        set = (point, message, hits) => {
          lat.value = point.lat.toFixed(7);
          lon.value = point.lon.toFixed(7);
          const text = message || 'Ubicación marcada. Revisa el mapa.';
          say(text);
          showChoices(hits);
          remember({ lat: lat.value, lon: lon.value, text, hits: hits || [] });
          lat.dispatchEvent(new Event('change', { bubbles: true }));
          const recalc = Drupal.shippingRecalculate;
          if (recalc && recalc.wrapper && recalc.canRecalculateRates()) {
            recalc.recalculateRates();
          }
        };

        const last = recalled();
        if (last && last.lat === lat.value && last.lon === lon.value) {
          say(last.text);
          showChoices(last.hits);
        }

        root
          .querySelector('[data-delivery-pin-action="geolocate"]')
          ?.addEventListener('click', () => {
            if (!navigator.geolocation) {
              say('Tu navegador no comparte la ubicación. Pega un enlace.');
              return;
            }
            say('Buscando tu ubicación…');
            navigator.geolocation.getCurrentPosition(
              (position) =>
                set(
                  {
                    lat: position.coords.latitude,
                    lon: position.coords.longitude,
                  },
                  `Ubicación marcada (precisión ~${Math.round(position.coords.accuracy)} m). Si no estás en la puerta de entrega, corrígela.`,
                ),
              () =>
                say(
                  'No pudimos leer tu ubicación. Pega un enlace de Google Maps o busca tu dirección.',
                ),
              { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
            );
          });

        // Read a link once it is all there: on paste, on leaving the box,
        // or after a pause in typing. Reading every keystroke would take
        // "-7" for the longitude of "-74.05…" and recalculate on it.
        const paste = root.querySelector(
          '[data-delivery-pin-action="paste"]',
        );
        let pause;
        const take = () => {
          clearTimeout(pause);
          const text = paste.value.trim();
          if (text === '') {
            return;
          }
          const point = parse(text);
          if (point) {
            set(point, 'Ubicación tomada del enlace. Revisa el mapa.');
          } else {
            say(
              'Ese enlace no trae coordenadas. En Google Maps, mantén presionado el punto y copia los números.',
            );
          }
        };
        paste?.addEventListener('paste', () => setTimeout(take, 0));
        paste?.addEventListener('change', take);
        paste?.addEventListener('input', () => {
          clearTimeout(pause);
          pause = setTimeout(take, 1200);
        });

        root
          .querySelector('[data-delivery-pin-action="search"]')
          ?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const query = address(root);
            choices.textContent = '';
            if (!settings.locate) {
              return;
            }
            button.disabled = true;
            say('Buscando la dirección… (puede tardar unos segundos)');
            try {
              const token = await fetch(settings.token).then((r) => r.text());
              const response = await fetch(settings.locate, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': token,
                },
                body: JSON.stringify({ q: query }),
              });
              const data = await response.json();
              if (!response.ok || !data.hits) {
                say(data.error || 'La búsqueda no respondió.');
                return;
              }
              if (!data.hits.length) {
                say(
                  'No encontramos esa dirección. Usa tu ubicación actual o pega un enlace.',
                );
                return;
              }
              set(data.hits[0], label(data.hits[0]), data.hits);
            } catch (e) {
              say('La búsqueda no respondió. Usa tu ubicación actual o pega un enlace.');
            } finally {
              button.disabled = false;
            }
          });
      });
    },
  };
})(Drupal, drupalSettings, once);
