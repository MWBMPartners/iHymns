# 🔧 Troubleshooting

> Common issues and how to resolve them.

---

## Web PWA

### Songs not loading

- **Check your internet connection** — the first load requires an internet connection
- **Clear browser cache** — go to browser settings and clear cached data for iHymns.app
- **Try a different browser** — we recommend Chrome, Firefox, Edge, or Safari

### PWA not installing

- **Ensure you're using HTTPS** — PWA install requires a secure connection
- **Use a supported browser** — Chrome, Edge, and Samsung Internet support PWA install natively
- **Safari (iOS)**: Tap the Share button → "Add to Home Screen"

### Search not working

- **Wait for data to load** — the search index builds after songs are loaded
- **Try fewer search terms** — broader searches return more results
- **Check spelling** — although fuzzy matching is enabled, very different spellings may not match

### Offline mode not working

- **Visit the site once while online** — the service worker needs an initial online visit to cache data
- **Check browser settings** — ensure service workers are not disabled
- **Clear and revisit** — clear the site data and visit again while online

---

## Apple (iOS / iPadOS / tvOS)

### App crashes on launch

- **Update to the latest version** — check the App Store for updates
- **Restart your device** — a simple restart often resolves transient issues
- **Reinstall the app** — delete and re-download from the App Store

### Songs not displaying

- **Check for updates** — song data may need an update
- **Force close and reopen** — swipe up on the app in the app switcher, then reopen

---

## Android

### App not installing

- **Check storage space** — ensure you have enough free space
- **Enable unknown sources** — if installing via APK, enable "Install unknown apps" in settings

---

## Still Having Issues?

If none of the above solutions work, please [open an issue](https://github.com/MWBMPartners/iHymns/issues) on GitHub with:

1. Your device and OS version
2. The browser or app version
3. A description of the problem
4. Steps to reproduce the issue

---

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.
