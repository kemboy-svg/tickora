import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

/// A failed request, with a message fit to show the person.
class ApiException implements Exception {
  ApiException(this.message, {this.status = 0, this.fieldErrors = const {}, this.data = const {}});

  ApiException.network(this.message)
      : status = 0,
        fieldErrors = const {},
        data = const {};

  final String message;

  /// HTTP status, or 0 when the server could not be reached.
  final int status;

  /// The first validation message for each field, e.g. {'email': '…'}.
  final Map<String, String> fieldErrors;

  final Map<String, dynamic> data;

  bool get isNetwork => status == 0;
  bool get isUnauthorized => status == 401;
  bool get needsVerifiedEmail => status == 403 && message.toLowerCase().contains('not verified');

  @override
  String toString() => message;
}

/// Talks to a Tickora server's /api/v1.
class ApiClient {
  ApiClient({required this.baseUrl, this.token, this.onUnauthorized});

  /// e.g. https://staging.example.com (no trailing slash).
  final String baseUrl;
  String? token;

  /// Called when the server no longer accepts the token.
  final void Function()? onUnauthorized;

  final http.Client _http = http.Client();

  static const _timeout = Duration(seconds: 25);

  Uri uri(String path, [Map<String, String>? query]) {
    final base = Uri.parse('$baseUrl/api/v1$path');
    if (query == null || query.isEmpty) return base;
    return base.replace(queryParameters: query);
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) =>
      _send('GET', path, query: query);

  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) =>
      _send('POST', path, body: body ?? const {});

  Future<Map<String, dynamic>> delete(String path) => _send('DELETE', path);

  /// Makes a server-relative image address absolute, and points addresses
  /// the server built for itself (localhost) at the address the app uses.
  String? resolveUrl(String? url) {
    if (url == null || url.isEmpty) return null;
    final server = Uri.parse(baseUrl);
    if (url.startsWith('/')) return '$baseUrl$url';
    final parsed = Uri.tryParse(url);
    if (parsed == null) return null;
    if (parsed.host == 'localhost' || parsed.host == '127.0.0.1') {
      return parsed.replace(scheme: server.scheme, host: server.host, port: server.port).toString();
    }
    return url;
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
  }) async {
    final request = http.Request(method, uri(path, query));
    request.headers['Accept'] = 'application/json';
    final currentToken = token;
    if (currentToken != null) request.headers['Authorization'] = 'Bearer $currentToken';
    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      final streamed = await _http.send(request).timeout(_timeout);
      response = await http.Response.fromStream(streamed).timeout(_timeout);
    } on TimeoutException {
      throw ApiException.network('The server took too long to answer. Check your connection and try again.');
    } on SocketException {
      throw ApiException.network("Can't reach the server. Check your connection and try again.");
    } on HandshakeException {
      throw ApiException.network("Can't make a secure connection to the server.");
    } on http.ClientException {
      throw ApiException.network("Can't reach the server. Check your connection and try again.");
    }

    Map<String, dynamic> json = const {};
    if (response.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(utf8.decode(response.bodyBytes));
        if (decoded is Map<String, dynamic>) json = decoded;
      } on FormatException {
        // Not JSON: usually not a Tickora server, or a proxy error page.
        if (response.statusCode < 300) {
          throw ApiException('The server sent an unexpected answer. Is this the right address?',
              status: response.statusCode);
        }
      }
    }

    if (response.statusCode >= 200 && response.statusCode < 300) return json;

    if (response.statusCode == 401) onUnauthorized?.call();

    final fieldErrors = <String, String>{};
    final errors = json['errors'];
    if (errors is Map) {
      errors.forEach((key, value) {
        if (value is List && value.isNotEmpty) fieldErrors['$key'] = '${value.first}';
        if (value is String) fieldErrors['$key'] = value;
      });
    }

    final message = json['message'] is String && (json['message'] as String).isNotEmpty
        ? fieldErrors.isNotEmpty
            ? fieldErrors.values.first
            : json['message'] as String
        : _defaultMessage(response.statusCode);

    throw ApiException(message, status: response.statusCode, fieldErrors: fieldErrors, data: json);
  }

  static String _defaultMessage(int status) => switch (status) {
        401 => 'Please sign in again.',
        403 => "You don't have access to this.",
        404 => "This couldn't be found. It may have been removed.",
        419 || 429 => 'Too many attempts. Please wait a moment and try again.',
        >= 500 => 'Something went wrong on the server. Please try again shortly.',
        _ => 'Something went wrong. Please try again.',
      };

  /// Cleans up what someone typed as the server address.
  static String normalizeServerUrl(String input) {
    var url = input.trim();
    if (url.isEmpty) return url;
    if (!url.contains('://')) url = 'https://$url';
    while (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    for (final suffix in ['/api/v1', '/api']) {
      if (url.endsWith(suffix)) url = url.substring(0, url.length - suffix.length);
    }
    return url;
  }
}
