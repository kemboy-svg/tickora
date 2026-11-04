import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'models.dart';

/// Which server the app talks to, who is signed in, and the tickets kept on
/// the phone. The sign-in token lives in the phone's secure storage.
class AppState extends ChangeNotifier {
  AppState(this._prefs, this._secure);

  final SharedPreferences _prefs;
  final FlutterSecureStorage _secure;

  static const _serverKey = 'server_url';
  static const _userKey = 'user';
  static const _tokenKey = 'token';

  String? _serverUrl;
  ApiClient? _api;
  AppUser? _user;

  String? get serverUrl => _serverUrl;
  AppUser? get user => _user;
  bool get signedIn => _user != null && _api?.token != null;

  /// Only valid once a server is set.
  ApiClient get api => _api!;

  static Future<AppState> load() async {
    final prefs = await SharedPreferences.getInstance();
    const secure = FlutterSecureStorage(aOptions: AndroidOptions(encryptedSharedPreferences: true));
    final state = AppState(prefs, secure);

    final server = prefs.getString(_serverKey);
    if (server != null && server.isNotEmpty) {
      String? token;
      try {
        token = await secure.read(key: _tokenKey);
      } catch (_) {
        token = null;
      }
      state._connect(server, token);

      final userJson = prefs.getString(_userKey);
      if (token != null && userJson != null) {
        try {
          state._user = AppUser.fromJson(jsonDecode(userJson));
        } catch (_) {
          state._user = null;
        }
      }
    }

    return state;
  }

  void _connect(String server, String? token) {
    _serverUrl = server;
    _api = ApiClient(baseUrl: server, token: token, onUnauthorized: _expired);
  }

  /// Checks that the address belongs to a Tickora server, then uses it.
  Future<void> setServer(String input) async {
    final url = ApiClient.normalizeServerUrl(input);
    if (url.isEmpty) throw ApiException('Enter the server address.');

    final uri = Uri.tryParse(url);
    if (uri == null || uri.host.isEmpty) throw ApiException("That doesn't look like a web address.");

    final probe = ApiClient(baseUrl: url);
    final Map<String, dynamic> info;
    try {
      info = await probe.get('/ping');
    } on ApiException catch (e) {
      if (e.status == 404) {
        throw ApiException("This server doesn't have the Tickora app API. Check the address, or update the server.");
      }
      rethrow;
    }
    if (info['app'] != 'tickora') {
      throw ApiException("This doesn't look like a Tickora server.");
    }

    if (_serverUrl != url) await _clearSession();
    await _prefs.setString(_serverKey, url);
    _connect(url, null);
    notifyListeners();
  }

  Future<void> forgetServer() async {
    await signOut();
    await _prefs.remove(_serverKey);
    _serverUrl = null;
    _api = null;
    notifyListeners();
  }

  Future<void> signedInWith(Map<String, dynamic> response) async {
    final token = response['token'];
    if (token is! String) throw ApiException('The server did not return a sign-in token.');
    final user = AppUser.fromJson(response['user']);

    await _secure.write(key: _tokenKey, value: token);
    await _prefs.setString(_userKey, jsonEncode(user.toJson()));
    api.token = token;
    _user = user;
    notifyListeners();
  }

  Future<void> refreshUser() async {
    if (!signedIn) return;
    final response = await api.get('/me');
    _user = AppUser.fromJson(response['user']);
    await _prefs.setString(_userKey, jsonEncode(_user!.toJson()));
    notifyListeners();
  }

  Future<void> signOut() async {
    final api = _api;
    if (api != null && api.token != null) {
      try {
        await api.post('/auth/logout');
      } catch (_) {
        // Offline or already revoked: forget the token here anyway.
      }
    }
    await _clearSession();
    notifyListeners();
  }

  Future<void> _clearSession() async {
    try {
      await _secure.delete(key: _tokenKey);
    } catch (_) {}
    await _prefs.remove(_userKey);
    await _prefs.remove(_ordersCacheKey);
    _api?.token = null;
    _user = null;
  }

  void _expired() {
    if (_user == null) return;
    _clearSession().then((_) => notifyListeners());
  }

  // --- Tickets kept on the phone -------------------------------------

  static const _ordersCacheKey = 'orders_cache';

  Future<void> cacheOrders(List<dynamic> raw) async {
    await _prefs.setString(_ordersCacheKey, jsonEncode(raw));
  }

  List<Order> cachedOrders() {
    final json = _prefs.getString(_ordersCacheKey);
    if (json == null) return const [];
    try {
      final list = jsonDecode(json);
      if (list is! List) return const [];
      return list.whereType<Map<String, dynamic>>().map(Order.fromJson).toList();
    } catch (_) {
      return const [];
    }
  }

  String? imageUrl(String? url) => _api?.resolveUrl(url);
}

/// Makes the AppState available to every screen.
class AppScope extends InheritedNotifier<AppState> {
  const AppScope({super.key, required AppState state, required super.child}) : super(notifier: state);

  static AppState of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<AppScope>()!.notifier!;

  /// Without rebuilding when the state changes (for callbacks).
  static AppState read(BuildContext context) =>
      context.getInheritedWidgetOfExactType<AppScope>()!.notifier!;
}
