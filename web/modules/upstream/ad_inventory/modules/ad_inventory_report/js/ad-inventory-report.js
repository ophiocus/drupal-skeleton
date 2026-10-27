/**
 * @file
 * Draws the dashboard's daily chart. Every number is also in a table.
 */
((Drupal, once) => {
  Drupal.behaviors.adInventoryReport = {
    attach(context) {
      if (!window.echarts) {
        return;
      }
      once('air-chart', '[data-air-chart]', context).forEach((element) => {
        let data;
        try {
          data = JSON.parse(element.dataset.airChart);
        } catch (e) {
          return;
        }
        const css = getComputedStyle(element);
        const chart = window.echarts.init(element, null, { renderer: 'svg' });
        chart.setOption({
          animation: false,
          textStyle: { color: css.color },
          tooltip: { trigger: 'axis' },
          legend: { top: 0, textStyle: { color: css.color } },
          grid: { left: 48, right: 48, top: 32, bottom: 32 },
          xAxis: { type: 'category', data: data.days },
          yAxis: [
            { type: 'value', name: data.series[0].name },
            { type: 'value', name: data.series[1].name },
          ],
          series: data.series.map((s, i) => ({
            name: s.name,
            type: 'line',
            yAxisIndex: i,
            data: s.data,
            showSymbol: data.days.length < 40,
          })),
        });
        window.addEventListener('resize', () => chart.resize());
      });
    },
  };
})(Drupal, once);
