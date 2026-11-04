// Plain data from the Tickora API. Parsing is forgiving: a missing value
// becomes a sensible default rather than a crash.

String _s(Object? v, [String fallback = '']) => v is String ? v : (v == null ? fallback : '$v');
String? _sn(Object? v) => v is String && v.isNotEmpty ? v : null;
int _i(Object? v) => v is int ? v : (v is num ? v.toInt() : int.tryParse('$v') ?? 0);
bool _b(Object? v) => v == true;
Map<String, dynamic> _m(Object? v) => v is Map<String, dynamic> ? v : const {};
List<Map<String, dynamic>> _l(Object? v) =>
    v is List ? v.whereType<Map<String, dynamic>>().toList() : const [];

class Money {
  const Money({required this.minor, required this.currency, required this.formatted});

  factory Money.fromJson(Object? json) {
    final m = _m(json);
    return Money(minor: _i(m['amount_minor']), currency: _s(m['currency']), formatted: _s(m['formatted']));
  }

  final int minor;
  final String currency;
  final String formatted;

  bool get isZero => minor == 0;
}

class AppUser {
  const AppUser({required this.name, required this.email, required this.emailVerified});

  factory AppUser.fromJson(Object? json) {
    final m = _m(json);
    return AppUser(name: _s(m['name']), email: _s(m['email']), emailVerified: _b(m['email_verified']));
  }

  final String name;
  final String email;
  final bool emailVerified;

  Map<String, dynamic> toJson() => {'name': name, 'email': email, 'email_verified': emailVerified};
}

class Category {
  const Category({required this.name, required this.slug});

  factory Category.fromJson(Map<String, dynamic> m) => Category(name: _s(m['name']), slug: _s(m['slug']));

  final String name;
  final String slug;
}

class EventCard {
  const EventCard({
    required this.id,
    required this.slug,
    required this.title,
    required this.summary,
    required this.coverUrl,
    required this.category,
    required this.startsLabel,
    required this.venue,
    required this.city,
    required this.priceFrom,
    required this.isFree,
    required this.availability,
  });

  factory EventCard.fromJson(Map<String, dynamic> m) => EventCard(
        id: _s(m['id']),
        slug: _s(m['slug']),
        title: _s(m['title']),
        summary: _sn(m['summary']),
        coverUrl: _sn(m['cover_url']),
        category: _s(m['category']),
        startsLabel: _s(m['starts_label']),
        venue: _sn(m['venue']),
        city: _sn(m['city']),
        priceFrom: m['price_from'] == null ? null : Money.fromJson(m['price_from']),
        isFree: _b(m['is_free']),
        availability: _s(m['availability'], 'available'),
      );

  final String id;
  final String slug;
  final String title;
  final String? summary;
  final String? coverUrl;
  final String category;
  final String startsLabel;
  final String? venue;
  final String? city;
  final Money? priceFrom;
  final bool isFree;

  /// available, few_left or sold_out
  final String availability;

  String get place => [venue, city].whereType<String>().join(' · ');
}

class TicketTypeOption {
  const TicketTypeOption({
    required this.id,
    required this.name,
    required this.description,
    required this.price,
    required this.isFree,
    required this.remaining,
    required this.soldOut,
    required this.onSale,
    required this.minPerOrder,
    required this.maxPerOrder,
  });

  factory TicketTypeOption.fromJson(Map<String, dynamic> m) => TicketTypeOption(
        id: _s(m['id']),
        name: _s(m['name']),
        description: _sn(m['description']),
        price: Money.fromJson(m['price']),
        isFree: _b(m['is_free']),
        remaining: _i(m['remaining']),
        soldOut: _b(m['sold_out']),
        onSale: _b(m['on_sale']),
        minPerOrder: _i(m['min_per_order']) < 1 ? 1 : _i(m['min_per_order']),
        maxPerOrder: _i(m['max_per_order']) < 1 ? 10 : _i(m['max_per_order']),
      );

  final String id;
  final String name;
  final String? description;
  final Money price;
  final bool isFree;
  final int remaining;
  final bool soldOut;
  final bool onSale;
  final int minPerOrder;
  final int maxPerOrder;

  bool get buyable => onSale && !soldOut && remaining > 0;

  /// The most this buyer may pick in one order.
  int get limit => remaining < maxPerOrder ? remaining : maxPerOrder;
}

class EventDetail {
  const EventDetail({
    required this.card,
    required this.description,
    required this.dateLabel,
    required this.timeLabel,
    required this.timezoneLabel,
    required this.salesOpen,
    required this.isPast,
    required this.refundPolicy,
    required this.minAge,
    required this.address,
    required this.organizer,
    required this.imageUrls,
    required this.ticketTypes,
  });

  factory EventDetail.fromJson(Map<String, dynamic> m) {
    final venue = _m(m['venue_detail']);
    return EventDetail(
      card: EventCard.fromJson(m),
      description: _sn(m['description']),
      dateLabel: _s(m['date_label']),
      timeLabel: _s(m['time_label']),
      timezoneLabel: _s(m['timezone_label']),
      salesOpen: _b(m['sales_open']),
      isPast: _b(m['is_past']),
      refundPolicy: _sn(m['refund_policy']),
      minAge: m['min_age'] is int ? m['min_age'] as int : null,
      address: venue.isEmpty
          ? null
          : [venue['address_line'], venue['city']].whereType<String>().where((s) => s.isNotEmpty).join(', '),
      organizer: _s(_m(m['organizer'])['name']),
      imageUrls: _l(m['images'])
          .map((i) => _sn(i['url']) ?? _sn(i['large_url']) ?? _sn(i['src']))
          .whereType<String>()
          .toList(),
      ticketTypes: _l(m['ticket_types']).map(TicketTypeOption.fromJson).toList(),
    );
  }

  final EventCard card;
  final String? description;
  final String dateLabel;
  final String timeLabel;
  final String timezoneLabel;
  final bool salesOpen;
  final bool isPast;
  final String? refundPolicy;
  final int? minAge;
  final String? address;
  final String organizer;
  final List<String> imageUrls;
  final List<TicketTypeOption> ticketTypes;
}

class CheckoutLine {
  const CheckoutLine({required this.name, required this.quantity, required this.subtotal});

  factory CheckoutLine.fromJson(Map<String, dynamic> m) =>
      CheckoutLine(name: _s(m['name']), quantity: _i(m['quantity']), subtotal: Money.fromJson(m['subtotal']));

  final String name;
  final int quantity;
  final Money subtotal;
}

class CheckoutOrder {
  const CheckoutOrder({
    required this.id,
    required this.reference,
    required this.statusLabel,
    required this.paid,
    required this.paymentDeclined,
    required this.refunded,
  });

  factory CheckoutOrder.fromJson(Map<String, dynamic> m) => CheckoutOrder(
        id: _s(m['id']),
        reference: _s(m['reference']),
        statusLabel: _s(m['status_label']),
        paid: _b(m['paid']) || const ['paid', 'partially_refunded', 'refunded'].contains(m['status']),
        paymentDeclined: _b(m['payment_declined']),
        refunded: _b(m['refunded']),
      );

  final String id;
  final String reference;
  final String statusLabel;
  final bool paid;
  final bool paymentDeclined;
  final bool refunded;
}

class Checkout {
  const Checkout({
    required this.id,
    required this.status,
    required this.secondsLeft,
    required this.eventTitle,
    required this.eventSlug,
    required this.eventDate,
    required this.eventPlace,
    required this.lines,
    required this.subtotal,
    required this.discount,
    required this.fee,
    required this.tax,
    required this.total,
    required this.promoCode,
    required this.order,
  });

  factory Checkout.fromJson(Map<String, dynamic> m) {
    final event = _m(m['event']);
    final order = m['order'];
    return Checkout(
      id: _s(m['id']),
      status: _s(m['status']),
      secondsLeft: _i(m['seconds_left']),
      eventTitle: _s(event['title']),
      eventSlug: _s(event['slug']),
      eventDate: _s(event['date_label']),
      eventPlace: [event['venue'], event['city']].whereType<String>().join(' · '),
      lines: _l(m['lines']).map(CheckoutLine.fromJson).toList(),
      subtotal: Money.fromJson(m['subtotal']),
      discount: Money.fromJson(m['discount']),
      fee: Money.fromJson(m['fee']),
      tax: Money.fromJson(m['tax']),
      total: Money.fromJson(m['total']),
      promoCode: _sn(m['promo_code']),
      order: order is Map<String, dynamic> ? CheckoutOrder.fromJson(order) : null,
    );
  }

  final String id;

  /// open, awaiting_payment, completed, expired or cancelled
  final String status;
  final int secondsLeft;
  final String eventTitle;
  final String eventSlug;
  final String eventDate;
  final String eventPlace;
  final List<CheckoutLine> lines;
  final Money subtotal;
  final Money discount;
  final Money fee;
  final Money tax;
  final Money total;
  final String? promoCode;
  final CheckoutOrder? order;

  bool get isPaid => order?.paid ?? false;
  bool get isActive => status == 'open' || status == 'awaiting_payment';
  int get ticketCount => lines.fold(0, (sum, l) => sum + l.quantity);
}

class TicketEvent {
  const TicketEvent({
    required this.title,
    required this.slug,
    required this.dateLabel,
    required this.shortDateLabel,
    required this.startsAt,
    required this.venue,
    required this.address,
    required this.coverUrl,
    required this.isPast,
  });

  factory TicketEvent.fromJson(Object? json) {
    final m = _m(json);
    return TicketEvent(
      title: _s(m['title']),
      slug: _s(m['slug']),
      dateLabel: _s(m['date_label']),
      shortDateLabel: _s(m['short_date_label'], _s(m['date_label'])),
      startsAt: DateTime.tryParse(_s(m['starts_at'])),
      venue: _sn(m['venue']),
      address: _sn(m['address']),
      coverUrl: _sn(m['cover_url']),
      isPast: _b(m['is_past']),
    );
  }

  final String title;
  final String slug;
  final String dateLabel;
  final String shortDateLabel;
  final DateTime? startsAt;
  final String? venue;
  final String? address;
  final String? coverUrl;
  final bool isPast;
}

class Ticket {
  const Ticket({
    required this.id,
    required this.reference,
    required this.type,
    required this.attendee,
    required this.status,
    required this.usable,
    required this.qr,
  });

  factory Ticket.fromJson(Map<String, dynamic> m) => Ticket(
        id: _s(m['id']),
        reference: _s(m['reference']),
        type: _s(m['type']),
        attendee: _sn(m['attendee']),
        status: _s(m['status']),
        usable: _b(m['usable']),
        qr: _sn(m['qr']),
      );

  final String id;
  final String reference;
  final String type;
  final String? attendee;

  /// valid, checked_in, cancelled, refunded or void
  final String status;
  final bool usable;
  final String? qr;

  String get statusLabel => switch (status) {
        'valid' => 'Valid',
        'checked_in' => 'Checked in',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'void' => 'Void',
        _ => status,
      };
}

class OrderItem {
  const OrderItem({required this.name, required this.quantity, required this.amount});

  factory OrderItem.fromJson(Map<String, dynamic> m) =>
      OrderItem(name: _s(m['name']), quantity: _i(m['quantity']), amount: Money.fromJson(m['amount']));

  final String name;
  final int quantity;
  final Money amount;
}

class Order {
  const Order({
    required this.id,
    required this.reference,
    required this.statusLabel,
    required this.paidLabel,
    required this.items,
    required this.discount,
    required this.fee,
    required this.total,
    required this.event,
    required this.tickets,
  });

  factory Order.fromJson(Map<String, dynamic> m) => Order(
        id: _s(m['id']),
        reference: _s(m['reference']),
        statusLabel: _s(m['status_label']),
        paidLabel: _sn(m['paid_label']),
        items: _l(m['items']).map(OrderItem.fromJson).toList(),
        discount: Money.fromJson(m['discount']),
        fee: Money.fromJson(m['fee']),
        total: Money.fromJson(m['total']),
        event: TicketEvent.fromJson(m['event']),
        tickets: _l(m['tickets']).map(Ticket.fromJson).toList(),
      );

  final String id;
  final String reference;
  final String statusLabel;
  final String? paidLabel;
  final List<OrderItem> items;
  final Money discount;
  final Money fee;
  final Money total;
  final TicketEvent event;
  final List<Ticket> tickets;
}
