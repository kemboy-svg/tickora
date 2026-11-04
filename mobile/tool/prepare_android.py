"""Adjusts the Android project that `flutter create` generates in CI.

- network access (release builds have none by default) and plain-HTTP
  servers on the local network, for testers
- the app name, and tickora:// links that bring the app back after payment
- browsers visible to the app (Android 11+ package visibility)
- release signing with the key in TICKORA_KEYSTORE, when set

Fails loudly if the template changed, so a broken build is never silent.
"""
import pathlib
import re
import sys

root = pathlib.Path(__file__).resolve().parent.parent / 'android' / 'app'


def need(condition, what):
    if not condition:
        sys.exit(f'prepare_android: could not {what}; the Flutter template may have changed.')


# --- Manifest -----------------------------------------------------------
manifest_path = root / 'src' / 'main' / 'AndroidManifest.xml'
manifest = manifest_path.read_text()

if 'android.permission.INTERNET' not in manifest:
    manifest, n = re.subn(r'(<application\b)', '<uses-permission android:name="android.permission.INTERNET"/>\n    \\1', manifest, count=1)
    need(n == 1, 'add the internet permission')

manifest, n = re.subn(r'android:label="[^"]*"', 'android:label="Tickora"', manifest, count=1)
need(n == 1, 'set the app name')

if 'usesCleartextTraffic' not in manifest:
    manifest, n = re.subn(r'<application\b', '<application\n        android:usesCleartextTraffic="true"', manifest, count=1)
    need(n == 1, 'allow local http servers')

deep_link = '''
            <intent-filter>
                <action android:name="android.intent.action.VIEW"/>
                <category android:name="android.intent.category.DEFAULT"/>
                <category android:name="android.intent.category.BROWSABLE"/>
                <data android:scheme="tickora"/>
            </intent-filter>
            <meta-data android:name="flutter_deeplinking_enabled" android:value="false"/>
        </activity>'''
need('</activity>' in manifest, 'find the main activity')
manifest = manifest.replace('</activity>', deep_link, 1)

browsers = '''
        <intent>
            <action android:name="android.intent.action.VIEW"/>
            <data android:scheme="https"/>
        </intent>
        <intent>
            <action android:name="android.intent.action.VIEW"/>
            <data android:scheme="http"/>
        </intent>
        <intent>
            <action android:name="android.support.customtabs.action.CustomTabsService"/>
        </intent>
    </queries>'''
if '<queries>' in manifest:
    manifest = manifest.replace('</queries>', browsers, 1)
else:
    manifest = manifest.replace('</manifest>', '    <queries>' + browsers + '\n</manifest>', 1)

manifest_path.write_text(manifest)

# --- Signing ------------------------------------------------------------
gradle_path = root / 'build.gradle.kts'
need(gradle_path.exists(), 'find app/build.gradle.kts')
gradle = gradle_path.read_text()

signing = '''    signingConfigs {
        create("release") {
            val keystore = System.getenv("TICKORA_KEYSTORE")
            if (!keystore.isNullOrEmpty()) {
                storeFile = file(keystore)
                storePassword = System.getenv("TICKORA_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("TICKORA_KEY_ALIAS")
                keyPassword = System.getenv("TICKORA_KEY_PASSWORD")
            }
        }
    }

    buildTypes {'''
need('buildTypes {' in gradle, 'find buildTypes')
gradle = gradle.replace('    buildTypes {', signing, 1)

gradle, n = re.subn(
    r'signingConfig\s*=\s*signingConfigs\.getByName\("debug"\)',
    'signingConfig = if (System.getenv("TICKORA_KEYSTORE").isNullOrEmpty()) '
    'signingConfigs.getByName("debug") else signingConfigs.getByName("release")',
    gradle,
    count=1,
)
need(n == 1, 'switch release signing')

gradle_path.write_text(gradle)
print('prepare_android: manifest and signing updated')
