// Skeleton entry point — rename and fill in.
//
// Convention: this file is the top-level concern. It boots the
// theme's runtime once the DOM is ready, then hands off to
// purpose-built modules. Keep this file small; substantive logic
// goes in siblings under src/.

function boot(): void {
  console.info("[example-theme] booted");
  // Your initialization here.
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot, { once: true });
} else {
  boot();
}
