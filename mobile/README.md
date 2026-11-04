# Tickora for Android

The buyer app: browse events, buy tickets, and keep QR tickets on the phone
(they open without a connection). Flutter, Android only for now.

## Getting the APK

Every push to `main` that touches `mobile/` builds a signed APK on GitHub
Actions (`.github/workflows/mobile.yml`) and publishes it as a release:

<https://github.com/kemboy-svg/tickora/releases/latest/download/tickora-android.apk>

On first launch the app asks for the server address (for example the
staging site). It checks the address with `GET /api/v1/ping`.

## How it talks to the server

The app uses the JSON API in `routes/api.php` with a Sanctum token per
phone. Payment opens the provider's page (Stripe, Paystack, or the sandbox
on staging) in the phone's browser; the provider returns the buyer to a
signed page that hands back to the app (`tickora://`), and the app checks
the order with `GET /api/v1/checkouts/{id}`.

## Building locally

```bash
cd mobile
flutter create --org com.tickora --project-name tickora --platforms android .
rm -f test/widget_test.dart
python3 tool/prepare_android.py
flutter pub get
dart run flutter_launcher_icons
flutter run            # or: flutter build apk --release
```

The `android/` folder is generated, not committed, so it always matches
your Flutter version.

## Signing

Test builds are signed with `signing/testers.jks` (password
`tickora-testers`) so each new APK installs over the previous one. It is
not secret. **Before publishing on Google Play**, create a private upload
key and add it as repository secrets: `TICKORA_KEYSTORE_BASE64` (the
keystore, base64-encoded), `TICKORA_KEYSTORE_PASSWORD`, `TICKORA_KEY_ALIAS`
and `TICKORA_KEY_PASSWORD`. The workflow then uses it instead.
