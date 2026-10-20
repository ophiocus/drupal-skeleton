/**
 * @file
 * Draws the GA4 report's charts from the snapshot in drupalSettings.
 *
 * Every number is already in the page as a table; this only draws. Colours
 * are read from the page's CSS custom properties, so the charts follow the
 * same light and dark roles as the cards around them.
 */
((Drupal, drupalSettings, once) => {
  const settings = drupalSettings.ga4Report || {};
  const snapshot = settings.snapshot || null;
  const cloudflare = settings.cloudflare || null;
  const adsense = settings.adsense || null;
  const kinds = settings.kinds || {};
  const markers = settings.markers || [];
  const lang = document.documentElement.lang || undefined;
  const fmt = new Intl.NumberFormat(lang);
  const compact = new Intl.NumberFormat(lang, {
    notation: 'compact',
    maximumFractionDigits: 1,
  });

  /**
   * The page's colour roles.
   */
  function palette(root) {
    const css = getComputedStyle(root);
    const get = (name) => css.getPropertyValue(`--ga4r-${name}`).trim();
    return {
      surface: get('surface'),
      ink: get('ink'),
      ink2: get('ink-2'),
      muted: get('muted'),
      grid: get('grid'),
      axis: get('axis'),
      other: get('other'),
      series: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => get(`s${n}`)),
      seq: [get('seq-low'), get('seq-mid'), get('seq-high')],
    };
  }

  /**
   * A fixed colour per entity, in the order given; the rest share "other".
   */
  function assign(names, p) {
    const map = {};
    names.forEach((name, i) => {
      map[name] = i < p.series.length ? p.series[i] : p.other;
    });
    return (name) => map[name] || p.other;
  }

  /**
   * Sums a field over rows, grouped by a key.
   */
  function totals(rows, key, field) {
    const out = {};
    rows.forEach((r) => {
      out[r[key]] = (out[r[key]] || 0) + r[field];
    });
    return out;
  }

  /**
   * Keys of a totals map, largest first.
   */
  function ranked(map) {
    return Object.keys(map).sort((a, b) => map[b] - map[a]);
  }

  /**
   * Shared chrome: text in ink, recessive axes, tooltips on the surface.
   */
  function base(p) {
    return {
      backgroundColor: 'transparent',
      textStyle: {
        color: p.ink2,
        fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif',
      },
      tooltip: {
        backgroundColor: p.surface,
        borderColor: p.axis,
        textStyle: { color: p.ink },
        confine: true,
      },
      aria: { enabled: true },
      animationDuration: 300,
    };
  }

  function valueAxis(p, extra) {
    return {
      type: 'value',
      axisLine: { show: false },
      axisTick: { show: false },
      splitLine: { lineStyle: { color: p.grid, width: 1 } },
      axisLabel: { color: p.muted, formatter: (v) => compact.format(v) },
      ...extra,
    };
  }

  function categoryAxis(p, data, extra) {
    return {
      type: 'category',
      data,
      axisLine: { lineStyle: { color: p.axis } },
      axisTick: { show: false },
      axisLabel: { color: p.muted },
      ...extra,
    };
  }

  function legend(p, extra) {
    return {
      top: 0,
      left: 0,
      icon: 'roundRect',
      itemWidth: 12,
      itemHeight: 12,
      textStyle: { color: p.ink2 },
      ...extra,
    };
  }

  const channelOrder = snapshot
    ? ranked(totals(snapshot.channels, 'channel', 'sessions'))
    : [];
  const kindOrder = Object.keys(kinds).filter((k) => k !== 'other');

  /**
   * A UTC minute ("2026-10-20T14:05") in the viewer's own time.
   */
  const minuteFmt = new Intl.DateTimeFormat(lang, {
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
  const localMinute = (m) => minuteFmt.format(new Date(`${m}:00Z`));

  /**
   * Panels stacked on one time axis: one grid per measure, never two scales
   * on one plot. Returns grid, xAxis and yAxis lists for ECharts.
   */
  function panels(p, labels, specs) {
    const gap = 3;
    const total = specs.reduce((a, s) => a + s.weight, 0);
    // 8 % above for the legend, 8 % below for the time labels.
    const usable = 100 - 16 - gap * (specs.length - 1);
    let top = 8;
    const grid = [];
    const xAxis = [];
    const yAxis = [];
    specs.forEach((s, i) => {
      const height = (usable * s.weight) / total;
      grid.push({ left: 56, right: 16, top: `${top}%`, height: `${height}%` });
      top += height + gap;
      xAxis.push(
        categoryAxis(p, labels, {
          gridIndex: i,
          axisLabel: { show: i === specs.length - 1, color: p.muted, hideOverlap: true },
          axisTick: { show: false },
        }),
      );
      yAxis.push(
        valueAxis(p, {
          gridIndex: i,
          name: s.name,
          nameLocation: 'middle',
          nameGap: 40,
          nameTextStyle: { color: p.ink2 },
          splitNumber: 2,
          ...(s.y || {}),
        }),
      );
    });
    return { grid, xAxis, yAxis };
  }

  const charts = {
    edge(p) {
      const visits = {};
      ((snapshot && snapshot.minutes) || []).forEach(([m, v, views, users]) => {
        visits[m] = [v, views, users];
      });
      const rows = cloudflare.minutes;
      const labels = rows.map((r) => localMinute(r[0]));
      const rate = rows.map(([, c, m, o]) => (c + m + o ? Math.round((1000 * c) / (c + m + o)) / 10 : null));
      const lastVisit = snapshot && snapshot.minutes && snapshot.minutes.length ? snapshot.minutes[snapshot.minutes.length - 1][0] : null;
      const layout = panels(p, labels, [
        { weight: 2, name: Drupal.t('Hit rate %'), y: { min: 0, max: 100 } },
        { weight: 3, name: Drupal.t('Requests') },
        { weight: 1.2, name: Drupal.t('Visits'), y: { minInterval: 1 } },
      ]);
      const bar = (name, data, color, axis, stack) => ({
        name,
        type: 'bar',
        data,
        color,
        stack,
        xAxisIndex: axis,
        yAxisIndex: axis,
        barCategoryGap: 0,
        large: true,
      });
      return {
        ...base(p),
        ...layout,
        legend: legend(p),
        axisPointer: { link: [{ xAxisIndex: 'all' }] },
        tooltip: {
          ...base(p).tooltip,
          trigger: 'axis',
          axisPointer: { type: 'line' },
          formatter: (items) => {
            const i = items[0].dataIndex;
            const [m, c, mi, o, b] = rows[i];
            const v = visits[m];
            const ga = !snapshot
              ? ''
              : lastVisit && m > lastVisit
                ? `<br>${Drupal.t('Visits: not processed yet')}`
                : `<br>${Drupal.t('Visits')}: ${v ? `${v[0]} · ${Drupal.t('page views')} ${v[1]} · ${Drupal.t('users')} ${v[2]}` : 0}`;
            return `<b>${labels[i]}</b> (${m.slice(11)} UTC)<br>${Drupal.t('Hit rate')}: ${rate[i] === null ? '–' : `${rate[i]} %`}<br>${Drupal.t('From cache')} ${fmt.format(c)} · ${Drupal.t('missed')} ${fmt.format(mi)} · ${Drupal.t('dynamic or bypass')} ${fmt.format(o)}<br>${Drupal.t('Beacons, not counted')}: ${fmt.format(b)}${ga}`;
          },
        },
        series: [
          {
            name: Drupal.t('Hit rate'),
            type: 'line',
            data: rate,
            color: p.series[0],
            showSymbol: false,
            connectNulls: false,
            lineStyle: { width: 1.5 },
            xAxisIndex: 0,
            yAxisIndex: 0,
          },
          bar(Drupal.t('From cache'), rows.map((r) => r[1]), p.series[0], 1, 'cf'),
          bar(Drupal.t('Missed'), rows.map((r) => r[2]), p.series[1], 1, 'cf'),
          bar(Drupal.t('Dynamic or bypass'), rows.map((r) => r[3]), p.other, 1, 'cf'),
          bar(Drupal.t('Visits'), rows.map((r) => (visits[r[0]] ? visits[r[0]][0] : 0)), p.series[2], 2, 'ga'),
        ],
      };
    },

    edgeDays(p) {
      const rows = cloudflare.days;
      return {
        ...base(p),
        legend: legend(p),
        grid: { left: 8, right: 16, top: 32, bottom: 8, containLabel: true },
        tooltip: {
          ...base(p).tooltip,
          trigger: 'axis',
          axisPointer: { type: 'shadow' },
          formatter: (items) => {
            const [d, all, c] = rows[items[0].dataIndex];
            return `<b>${d}</b><br>${Drupal.t('Requests')}: ${fmt.format(all)}<br>${Drupal.t('From cache')}: ${fmt.format(c)} (${all ? Math.round((1000 * c) / all) / 10 : 0} %)`;
          },
        },
        xAxis: categoryAxis(p, rows.map((r) => r[0])),
        yAxis: valueAxis(p),
        series: [
          { name: Drupal.t('From cache'), type: 'bar', stack: 'd', data: rows.map((r) => r[2]), color: p.series[0], barMaxWidth: 24 },
          { name: Drupal.t('Not from cache'), type: 'bar', stack: 'd', data: rows.map((r) => r[1] - r[2]), color: p.series[1], barMaxWidth: 24, itemStyle: { borderRadius: [4, 4, 0, 0] } },
        ],
      };
    },

    ads(p) {
      const rows = adsense.days;
      const layout = panels(p, rows.map((r) => r[0]), [
        { weight: 3, name: adsense.currency || Drupal.t('Earnings') },
        { weight: 2, name: Drupal.t('Page RPM') },
      ]);
      const money = new Intl.NumberFormat(lang, { maximumFractionDigits: 2 });
      return {
        ...base(p),
        ...layout,
        axisPointer: { link: [{ xAxisIndex: 'all' }] },
        tooltip: {
          ...base(p).tooltip,
          trigger: 'axis',
          formatter: (items) => {
            const [d, e, v, rpm, imp, clicks] = rows[items[0].dataIndex];
            return `<b>${d}</b><br>${Drupal.t('Earnings')}: ${money.format(e)} ${adsense.currency}<br>${Drupal.t('Page views')}: ${fmt.format(v)} · ${Drupal.t('Page RPM')}: ${money.format(rpm)}<br>${Drupal.t('Impressions')}: ${fmt.format(imp)} · ${Drupal.t('Clicks')}: ${fmt.format(clicks)}`;
          },
        },
        series: [
          { name: Drupal.t('Earnings'), type: 'bar', data: rows.map((r) => r[1]), color: p.series[0], barMaxWidth: 24, itemStyle: { borderRadius: [4, 4, 0, 0] }, xAxisIndex: 0, yAxisIndex: 0 },
          { name: Drupal.t('Page RPM'), type: 'line', data: rows.map((r) => r[3]), color: p.series[0], showSymbol: false, lineStyle: { width: 2 }, xAxisIndex: 1, yAxisIndex: 1 },
        ],
      };
    },

    daily(p) {
      const days = snapshot.daily;
      const dates = days.map((d) => d.date);
      const series = [];
      const area = (color) => ({
        color,
        opacity: 0.1,
      });
      const marks = {
        symbol: 'none',
        silent: true,
        lineStyle: { color: p.muted, type: 'solid', width: 1 },
        label: { color: p.ink2, formatter: (m) => m.name, position: 'insideEndTop' },
        data: markers
          .filter((m) => dates.includes(m.date))
          .map((m) => ({ xAxis: m.date, name: m.label })),
      };
      if (snapshot.internal) {
        series.push({
          name: Drupal.t('Visitors'),
          type: 'line',
          stack: 'visits',
          data: days.map((d) => d.externalSessions),
          color: p.series[0],
          lineStyle: { width: 2 },
          areaStyle: area(p.series[0]),
          showSymbol: false,
          markLine: marks,
        });
        series.push({
          name: Drupal.t('Internal'),
          type: 'line',
          stack: 'visits',
          data: days.map((d) => d.sessions - d.externalSessions),
          color: p.muted,
          lineStyle: { width: 2 },
          areaStyle: area(p.muted),
          showSymbol: false,
        });
      } else {
        series.push({
          name: Drupal.t('Visits'),
          type: 'line',
          data: days.map((d) => d.sessions),
          color: p.series[0],
          lineStyle: { width: 2 },
          areaStyle: area(p.series[0]),
          showSymbol: false,
          markLine: marks,
        });
      }
      return {
        ...base(p),
        legend: snapshot.internal ? legend(p) : undefined,
        grid: { left: 8, right: 16, top: snapshot.internal ? 32 : 16, bottom: 8, containLabel: true },
        tooltip: { ...base(p).tooltip, trigger: 'axis' },
        xAxis: categoryAxis(p, dates, { boundaryGap: false }),
        yAxis: valueAxis(p),
        series,
      };
    },

    flow(p) {
      const color = assign(channelOrder, p);
      const names = new Set();
      snapshot.flow.forEach((l) => {
        names.add(l.source);
        names.add(l.target);
      });
      const nodes = [...names].map((name) => {
        const [kind, label] = [name.slice(0, 1), name.slice(2)];
        return {
          name,
          depth: { c: 0, s: 1, d: 2 }[kind],
          itemStyle: { color: kind === 'c' ? color(label) : p.other },
        };
      });
      return {
        ...base(p),
        tooltip: {
          ...base(p).tooltip,
          formatter: (i) =>
            i.dataType === 'edge'
              ? `${i.data.source.slice(2)} → ${i.data.target.slice(2)}: ${fmt.format(i.data.value)}`
              : `${i.name.slice(2)}: ${fmt.format(i.value)}`,
        },
        series: [
          {
            type: 'sankey',
            left: 8,
            right: 120,
            top: 8,
            bottom: 8,
            nodeWidth: 10,
            nodeGap: 8,
            layoutIterations: 64,
            emphasis: { focus: 'adjacency' },
            data: nodes,
            links: snapshot.flow,
            label: {
              color: p.ink,
              formatter: (i) => i.name.slice(2),
            },
            lineStyle: { color: 'source', opacity: 0.3, curveness: 0.5 },
          },
        ],
      };
    },

    places(p, el) {
      // One country, the one most visits come from: on a world-wide scale a
      // single foreign visit squeezes every home city into one dot.
      const home = snapshot.countries.length ? snapshot.countries[0].country : null;
      const cities = snapshot.cities.filter((c) => c.lat !== null && c.country === home);
      if (!cities.length) {
        return null;
      }
      const lats = cities.map((c) => c.lat);
      const lons = cities.map((c) => c.lon);
      const pad = (lo, hi) => Math.max(0.5, (hi - lo) * 0.12);
      const latLo = Math.min(...lats);
      const latHi = Math.max(...lats);
      const lonLo = Math.min(...lons);
      const lonHi = Math.max(...lons);
      const box = {
        latMin: latLo - pad(latLo, latHi),
        latMax: latHi + pad(latLo, latHi),
        lonMin: lonLo - pad(lonLo, lonHi),
        lonMax: lonHi + pad(lonLo, lonHi),
      };
      // Equal distances look equal: a degree of longitude shrinks with the
      // cosine of the latitude.
      const k = Math.cos((((box.latMax + box.latMin) / 2) * Math.PI) / 180);
      const ratio = (box.latMax - box.latMin) / ((box.lonMax - box.lonMin) * k);
      el.style.height = `${Math.round(Math.min(560, Math.max(260, el.clientWidth * ratio)))}px`;
      const max = Math.max(...cities.map((c) => c.sessions));
      const size = (v) => (v > 0 ? 6 + 40 * Math.sqrt(v / max) : 0);
      const top = new Set(cities.slice(0, 8).map((c) => c.city));
      const hidden = { show: false };
      const series = [
        {
          name: Drupal.t('Visits'),
          type: 'scatter',
          data: cities.map((c) => ({
            name: c.city,
            value: [c.lon, c.lat, c.sessions, c.internalSessions, c.country],
          })),
          symbolSize: (v) => size(v[2]),
          itemStyle: { color: p.series[0], opacity: 0.55, borderColor: p.surface, borderWidth: 2 },
          label: {
            show: true,
            position: 'right',
            color: p.ink2,
            formatter: (i) => (top.has(i.name) ? i.name : ''),
          },
          labelLayout: { hideOverlap: true },
        },
      ];
      if (snapshot.internal) {
        series.push({
          name: Drupal.t('Internal'),
          type: 'scatter',
          silent: true,
          data: cities
            .filter((c) => c.internalSessions > 0)
            .map((c) => ({ name: c.city, value: [c.lon, c.lat, c.internalSessions] })),
          symbolSize: (v) => size(v[2]),
          itemStyle: {
            color: 'transparent',
            borderColor: p.ink2,
            borderWidth: 1.5,
            borderType: 'dashed',
          },
        });
      }
      return {
        ...base(p),
        grid: { left: 4, right: 4, top: 4, bottom: 4 },
        tooltip: {
          ...base(p).tooltip,
          formatter: (i) => {
            const [, , visits, internal, country] = i.value;
            const extra = snapshot.internal ? `<br>${Drupal.t('Internal')}: ${fmt.format(internal || 0)}` : '';
            return `${i.name}, ${country}<br>${Drupal.t('Visits')}: ${fmt.format(visits)}${extra}`;
          },
        },
        xAxis: { type: 'value', min: box.lonMin, max: box.lonMax, show: false },
        yAxis: { type: 'value', min: box.latMin, max: box.latMax, show: false },
        series,
      };
    },

    rhythm(p) {
      // Monday first; Analytics numbers Sunday 0.
      const order = [1, 2, 3, 4, 5, 6, 0];
      const dayName = new Intl.DateTimeFormat(lang, { weekday: 'short' });
      // 2023-01-01 was a Sunday.
      const names = order.map((d) => dayName.format(new Date(2023, 0, 1 + d)));
      const data = snapshot.weekHours.map(([day, hour, v]) => [hour, order.indexOf(day), v]);
      const max = Math.max(1, ...data.map((d) => d[2]));
      return {
        ...base(p),
        grid: { left: 8, right: 8, top: 8, bottom: 48, containLabel: true },
        tooltip: {
          ...base(p).tooltip,
          formatter: (i) => `${names[i.value[1]]} ${String(i.value[0]).padStart(2, '0')}:00 · ${fmt.format(i.value[2])}`,
        },
        xAxis: categoryAxis(p, [...Array(24).keys()].map(String), { splitArea: { show: false } }),
        yAxis: categoryAxis(p, names, { inverse: true, axisLine: { show: false } }),
        visualMap: {
          min: 0,
          max,
          calculable: false,
          orient: 'horizontal',
          left: 'center',
          bottom: 0,
          itemHeight: 120,
          itemWidth: 10,
          textStyle: { color: p.muted },
          inRange: { color: p.seq },
        },
        series: [
          {
            type: 'heatmap',
            data,
            itemStyle: { borderColor: p.surface, borderWidth: 2, borderRadius: 2 },
            emphasis: { itemStyle: { borderColor: p.ink, borderWidth: 1 } },
          },
        ],
      };
    },

    tech(p) {
      const devices = ranked(totals(snapshot.tech, 'device', 'sessions'));
      const color = assign(devices, p);
      const tree = devices.map((device) => {
        const rows = snapshot.tech.filter((t) => t.device === device);
        const oses = ranked(totals(rows, 'os', 'sessions'));
        return {
          name: device,
          itemStyle: { color: color(device) },
          children: oses.map((os) => ({
            name: os,
            children: rows
              .filter((t) => t.os === os)
              .map((t) => ({ name: t.browser, value: t.sessions })),
          })),
        };
      });
      return {
        ...base(p),
        tooltip: {
          ...base(p).tooltip,
          formatter: (i) => `${i.treePathInfo.slice(1).map((n) => n.name).join(' › ')}: ${fmt.format(i.value)}`,
        },
        series: [
          {
            type: 'sunburst',
            data: tree,
            radius: ['12%', '92%'],
            sort: null,
            itemStyle: { borderColor: p.surface, borderWidth: 2 },
            label: { color: p.ink, minAngle: 12, rotate: 'radial', fontSize: 11 },
            levels: [
              {},
              { label: { rotate: 0, color: '#fff', fontWeight: 600 } },
              { itemStyle: { opacity: 0.75 } },
              { itemStyle: { opacity: 0.5 } },
            ],
          },
        ],
      };
    },

    audience(p) {
      const byLang = totals(snapshot.audience, 'language', 'sessions');
      const all = Object.values(byLang).reduce((a, b) => a + b, 0) || 1;
      const langs = ranked(byLang);
      const keep = langs.slice(0, 7);
      const other = Drupal.t('Other languages');
      const columns = {};
      snapshot.audience.forEach((a) => {
        const name = keep.includes(a.language) ? a.language : other;
        columns[name] = columns[name] || { new: 0, returning: 0, total: 0 };
        const type = a.type === 'returning' ? 'returning' : 'new';
        columns[name][type] += a.sessions;
        columns[name].total += a.sessions;
      });
      const names = Object.keys(columns).sort((a, b) =>
        a === other ? 1 : b === other ? -1 : columns[b].total - columns[a].total,
      );
      const rects = [];
      let x = 0;
      names.forEach((name) => {
        const c = columns[name];
        const w = c.total / all;
        const nShare = c.total ? c.new / c.total : 0;
        rects.push([x, w, 0, nShare, 0, name, c.new, c.total]);
        rects.push([x, w, nShare, 1 - nShare, 1, name, c.returning, c.total]);
        x += w;
      });
      const colors = [p.series[0], p.series[1]];
      const typeNames = [Drupal.t('New'), Drupal.t('Returning')];
      return {
        ...base(p),
        legend: legend(p, { data: typeNames }),
        grid: { left: 4, right: 4, top: 32, bottom: 28 },
        tooltip: {
          ...base(p).tooltip,
          formatter: (i) => {
            const [, , , , type, name, value, total] = i.value;
            return `${name} · ${typeNames[type]}: ${fmt.format(value)} (${Math.round((100 * value) / (total || 1))} %)`;
          },
        },
        xAxis: { type: 'value', min: 0, max: 1, show: false },
        yAxis: { type: 'value', min: 0, max: 1, show: false },
        series: [
          ...typeNames.map((name, i) => ({ name, type: 'custom', data: [], color: colors[i] })),
          {
            type: 'custom',
            name: 'mosaic',
            data: rects,
            encode: { x: [0], y: [2] },
            renderItem: (params, api) => {
              const x0 = api.value(0);
              const w = api.value(1);
              const y0 = api.value(2);
              const h = api.value(3);
              const type = api.value(4);
              const a = api.coord([x0, y0]);
              const b = api.coord([x0 + w, y0 + h]);
              const gap = 2;
              const width = Math.max(0, b[0] - a[0] - gap);
              const height = Math.max(0, a[1] - b[1] - gap);
              const children = [
                {
                  type: 'rect',
                  shape: { x: a[0], y: b[1], width, height, r: 2 },
                  style: { fill: colors[type] },
                },
              ];
              if (type === 0 && width > 44) {
                children.push({
                  type: 'text',
                  style: {
                    text: api.value(5),
                    x: a[0] + width / 2,
                    y: a[1] + 6,
                    align: 'center',
                    verticalAlign: 'top',
                    fill: p.ink2,
                    fontSize: 11,
                    width: width - 4,
                    overflow: 'truncate',
                  },
                });
              }
              return { type: 'group', children };
            },
          },
        ],
      };
    },

    pages(p) {
      const color = assign(kindOrder, p);
      const groups = {};
      snapshot.pages.forEach((page) => {
        groups[page.kind] = groups[page.kind] || [];
        groups[page.kind].push({ name: page.path, value: page.views });
      });
      const data = Object.keys(groups)
        .sort((a, b) => (a === 'other') - (b === 'other') || kindOrder.indexOf(a) - kindOrder.indexOf(b))
        .map((kind) => ({
          name: kinds[kind] || kind,
          itemStyle: { color: color(kind) },
          // White reads on the series hues; the pale "other" fill takes ink.
          children: kind === 'other'
            ? groups[kind].map((leaf) => ({ ...leaf, label: { color: p.ink } }))
            : groups[kind],
        }));
      return {
        ...base(p),
        tooltip: {
          ...base(p).tooltip,
          formatter: (i) => `${i.name}: ${fmt.format(i.value)} ${Drupal.t('views')}`,
        },
        series: [
          {
            type: 'treemap',
            data,
            roam: false,
            nodeClick: false,
            breadcrumb: { show: false },
            left: 0,
            right: 0,
            top: 0,
            bottom: 0,
            label: { color: '#fff', fontSize: 11, overflow: 'truncate' },
            levels: [
              // The root: kinds sit side by side with a wider gap.
              { itemStyle: { borderWidth: 0, gapWidth: 6 } },
              // A kind: its name in a band across the top, in ink.
              {
                itemStyle: { borderWidth: 0, gapWidth: 2, borderColor: p.surface },
                upperLabel: { show: true, height: 20, color: p.ink, backgroundColor: p.surface, fontWeight: 600 },
              },
              // A page.
              { itemStyle: { borderWidth: 0 } },
            ],
          },
        ],
      };
    },

    kinds(p) {
      const rows = [...snapshot.kinds].sort((a, b) => a.views - b.views);
      return {
        ...base(p),
        grid: { left: 8, right: 48, top: 8, bottom: 8, containLabel: true },
        tooltip: { ...base(p).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' } },
        xAxis: valueAxis(p),
        yAxis: categoryAxis(p, rows.map((r) => r.label), { axisLabel: { color: p.ink2 } }),
        series: [
          {
            name: Drupal.t('Page views'),
            type: 'bar',
            data: rows.map((r) => r.views),
            color: p.series[0],
            barMaxWidth: 24,
            itemStyle: { borderRadius: [0, 4, 4, 0] },
            label: { show: true, position: 'right', color: p.ink2, formatter: (i) => fmt.format(i.value) },
          },
        ],
      };
    },

    landing(p) {
      const color = assign(channelOrder, p);
      const byKind = totals(snapshot.landing, 'kind', 'sessions');
      const kindsShown = ranked(byKind).reverse();
      const channels = channelOrder.filter((c) => snapshot.landing.some((l) => l.channel === c));
      return {
        ...base(p),
        legend: legend(p),
        grid: { left: 8, right: 16, top: 56, bottom: 8, containLabel: true },
        tooltip: { ...base(p).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' } },
        xAxis: valueAxis(p),
        yAxis: categoryAxis(p, kindsShown.map((k) => kinds[k] || k), { axisLabel: { color: p.ink2 } }),
        series: channels.map((channel) => ({
          name: channel,
          type: 'bar',
          stack: 'landing',
          color: color(channel),
          barMaxWidth: 24,
          itemStyle: { borderColor: p.surface, borderWidth: 1 },
          data: kindsShown.map((k) =>
            snapshot.landing
              .filter((l) => l.kind === k && l.channel === channel)
              .reduce((a, l) => a + l.sessions, 0),
          ),
        })),
      };
    },
  };

  Drupal.behaviors.ga4Report = {
    attach(context) {
      if ((!snapshot && !cloudflare && !adsense) || typeof window.echarts === 'undefined') {
        return;
      }
      const root = document.querySelector('.ga4r');
      once('ga4r', '[data-ga4r-chart]', context).forEach((el) => {
        const draw = charts[el.dataset.ga4rChart];
        if (!draw) {
          return;
        }
        const p = palette(root);
        const option = draw(p, el);
        if (!option) {
          el.hidden = true;
          return;
        }
        const chart = window.echarts.init(el, null, { renderer: 'svg' });
        chart.setOption(option);
        new ResizeObserver(() => chart.resize()).observe(el);
      });
    },
  };
})(Drupal, drupalSettings, once);
