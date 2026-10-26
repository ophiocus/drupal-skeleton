/**
 * @file
 * Reloads the tracking page while its delivery is under way.
 *
 * No live position exists to stream, so a reload every minute is all the
 * page needs; it stops once the delivery is done or the tab is hidden.
 */
((Drupal, once) => {
  Drupal.behaviors.shopDeliveryTracking = {
    attach(context) {
      once('delivery-track', '[data-delivery-track-live]', context).forEach(
        (node) => {
          const seconds = parseInt(node.dataset.deliveryTrackLive, 10);
          if (!seconds) {
            return;
          }
          setTimeout(function reload() {
            if (document.visibilityState === 'visible') {
              window.location.reload();
            } else {
              setTimeout(reload, seconds * 1000);
            }
          }, seconds * 1000);
        },
      );
    },
  };
})(Drupal, once);
