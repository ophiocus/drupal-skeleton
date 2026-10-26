/**
 * @file
 * The picker's service dropdown loads its free times as soon as it changes.
 *
 * Without JavaScript the "See free times" button does the same.
 */
((Drupal, once) => {
  Drupal.behaviors.appointmentsPicker = {
    attach(context) {
      once('appointments-autosubmit', '[data-appointments-autosubmit]', context).forEach((select) => {
        const button = select.form.querySelector('.appointments-picker__go');
        if (button) {
          button.hidden = true;
        }
        select.addEventListener('change', () => select.form.submit());
      });
    },
  };
})(Drupal, once);
