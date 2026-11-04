import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tickora/src/api.dart';
import 'package:tickora/src/models.dart';
import 'package:tickora/src/widgets/common.dart';

void main() {
  group('server address', () {
    test('adds https and trims slashes and API paths', () {
      expect(ApiClient.normalizeServerUrl(' staging.example.com/ '), 'https://staging.example.com');
      expect(ApiClient.normalizeServerUrl('http://192.168.1.20:8000/api/v1'), 'http://192.168.1.20:8000');
      expect(ApiClient.normalizeServerUrl(''), '');
    });

    test('resolves image addresses against the server', () {
      final api = ApiClient(baseUrl: 'http://192.168.1.20:8000');
      expect(api.resolveUrl('/storage/a.jpg'), 'http://192.168.1.20:8000/storage/a.jpg');
      expect(api.resolveUrl('http://localhost/storage/a.jpg'), 'http://192.168.1.20:8000/storage/a.jpg');
      expect(api.resolveUrl('https://cdn.example.com/a.jpg'), 'https://cdn.example.com/a.jpg');
      expect(api.resolveUrl(null), isNull);
    });
  });

  group('models', () {
    test('parse a checkout from the API', () {
      final checkout = Checkout.fromJson({
        'id': '01J',
        'status': 'awaiting_payment',
        'seconds_left': 420,
        'event': {'title': 'Sega Night', 'slug': 'sega-night', 'date_label': 'Sat 12 Dec', 'venue': 'Le Morne', 'city': 'Mauritius'},
        'lines': [
          {'name': 'Regular', 'quantity': 2, 'subtotal': {'amount_minor': 170000, 'currency': 'MUR', 'formatted': 'Rs 1,700.00'}},
        ],
        'subtotal': {'amount_minor': 170000, 'currency': 'MUR', 'formatted': 'Rs 1,700.00'},
        'discount': {'amount_minor': 0, 'currency': 'MUR', 'formatted': 'Rs 0.00'},
        'fee': {'amount_minor': 8500, 'currency': 'MUR', 'formatted': 'Rs 85.00'},
        'tax': {'amount_minor': 0, 'currency': 'MUR', 'formatted': 'Rs 0.00'},
        'total': {'amount_minor': 178500, 'currency': 'MUR', 'formatted': 'Rs 1,785.00'},
        'promo_code': null,
        'order': {'id': '01K', 'reference': 'TK-ABC', 'status': 'paid', 'status_label': 'Paid', 'paid': true},
      });

      expect(checkout.isPaid, isTrue);
      expect(checkout.ticketCount, 2);
      expect(checkout.total.formatted, 'Rs 1,785.00');
      expect(checkout.eventPlace, 'Le Morne · Mauritius');
    });

    test('tolerate missing values', () {
      final order = Order.fromJson({'id': 'x'});
      expect(order.tickets, isEmpty);
      expect(order.event.title, '');
      expect(order.total.minor, 0);
    });

    test('limit ticket choices to what is left', () {
      final type = TicketTypeOption.fromJson({
        'id': 't',
        'name': 'Early bird',
        'price': {'amount_minor': 50000, 'currency': 'MUR', 'formatted': 'Rs 500.00'},
        'remaining': 3,
        'sold_out': false,
        'on_sale': true,
        'min_per_order': 1,
        'max_per_order': 10,
      });
      expect(type.limit, 3);
      expect(type.buyable, isTrue);
    });
  });

  testWidgets('the logo renders', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: Center(child: TickoraLogo()))));
    expect(find.text('tickora'), findsOneWidget);
  });
}
