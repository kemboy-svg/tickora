import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../models.dart';
import '../widgets/common.dart';
import 'auth_screen.dart';
import 'checkout_screen.dart';
import 'verify_email.dart';

/// An event's details and its ticket picker.
class EventScreen extends StatefulWidget {
  const EventScreen({super.key, required this.slug, this.preview});

  final String slug;

  /// Shown while the full details load.
  final EventCard? preview;

  @override
  State<EventScreen> createState() => _EventScreenState();
}

class _EventScreenState extends State<EventScreen> {
  EventDetail? _event;
  ApiException? _error;
  final Map<String, int> _quantities = {};
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final response = await AppScope.read(context).api.get('/events/${Uri.encodeComponent(widget.slug)}');
      final data = response['data'];
      if (!mounted) return;
      setState(() => _event = data is Map<String, dynamic> ? EventDetail.fromJson(data) : null);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  int get _count => _quantities.values.fold(0, (sum, q) => sum + q);

  int get _totalMinor {
    final event = _event;
    if (event == null) return 0;
    var total = 0;
    for (final type in event.ticketTypes) {
      total += (type.price.minor) * (_quantities[type.id] ?? 0);
    }
    return total;
  }

  void _change(TicketTypeOption type, int delta) {
    final current = _quantities[type.id] ?? 0;
    var next = current + delta;
    // Orders need at least min_per_order of a type, or none.
    if (delta > 0 && current == 0) next = type.minPerOrder;
    if (delta < 0 && current <= type.minPerOrder) next = 0;
    if (next > type.limit) return;
    setState(() => _quantities[type.id] = next);
  }

  Future<void> _continue() async {
    final state = AppScope.read(context);
    final event = _event;
    if (event == null || _count == 0) return;

    if (!state.signedIn) {
      final signedIn = await Navigator.of(context).push<bool>(
        MaterialPageRoute(builder: (_) => const AuthScreen(reason: 'Sign in to buy tickets.')),
      );
      if (signedIn != true || !mounted) return;
    }

    if (!(state.user?.emailVerified ?? false)) {
      try {
        await state.refreshUser();
      } on ApiException {
        // Checked again by the server below.
      }
      if (!mounted) return;
      if (!(state.user?.emailVerified ?? false)) {
        final verified = await showVerifyEmailSheet(context);
        if (!verified || !mounted) return;
      }
    }

    setState(() => _busy = true);
    try {
      final response = await state.api.post('/checkouts', {
        'event': event.card.id,
        'items': {
          for (final entry in _quantities.entries)
            if (entry.value > 0) entry.key: entry.value,
        },
      });
      final data = response['data'];
      if (!mounted || data is! Map<String, dynamic>) return;
      final checkout = Checkout.fromJson(data);
      setState(() => _quantities.clear());
      await Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => CheckoutScreen(checkoutId: checkout.id, initial: checkout)),
      );
      if (mounted) _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.needsVerifiedEmail) {
        await showVerifyEmailSheet(context);
      } else {
        showSnack(context, e.message);
        _load();
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final event = _event;
    final preview = widget.preview;
    final error = _error;
    final state = AppScope.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(event?.card.title ?? preview?.title ?? 'Event', maxLines: 1, overflow: TextOverflow.ellipsis)),
      body: event == null
          ? (error != null
              ? MessageView(
                  icon: error.isNetwork ? Icons.wifi_off : Icons.error_outline,
                  title: "Couldn't load this event",
                  message: error.message,
                  actionLabel: 'Try again',
                  onAction: _load,
                )
              : ListView(children: [
                  if (preview != null) CoverImage(url: state.imageUrl(preview.coverUrl)),
                  const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
                ]))
          : RefreshIndicator(onRefresh: _load, child: _details(context, event)),
      bottomNavigationBar: event == null ? null : _buyBar(context, event),
    );
  }

  Widget _details(BuildContext context, EventDetail event) {
    final theme = Theme.of(context);
    final state = AppScope.of(context);
    final muted = theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant);

    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        CoverImage(url: state.imageUrl(event.card.coverUrl)),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(event.card.category.toUpperCase(),
                  style: theme.textTheme.labelMedium
                      ?.copyWith(color: theme.colorScheme.primary, fontWeight: FontWeight.w800, letterSpacing: 0.6)),
              const SizedBox(height: 6),
              Text(event.card.title, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w900)),
              if (event.organizer.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text('by ${event.organizer}', style: muted),
              ],
              const SizedBox(height: 20),
              _InfoRow(
                icon: Icons.event_outlined,
                title: event.dateLabel,
                subtitle: [event.timeLabel, event.timezoneLabel].where((s) => s.isNotEmpty).join(' · '),
              ),
              if (event.card.venue != null)
                _InfoRow(icon: Icons.place_outlined, title: event.card.venue!, subtitle: event.address ?? ''),
              if (event.minAge != null)
                _InfoRow(icon: Icons.badge_outlined, title: 'Ages ${event.minAge}+', subtitle: ''),
              const SizedBox(height: 12),
              Text('Tickets', style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              if (event.isPast)
                Text('This event has ended.', style: muted)
              else if (!event.salesOpen)
                Text('Tickets are not on sale right now.', style: muted)
              else if (event.ticketTypes.isEmpty)
                Text('No tickets are available yet.', style: muted),
              for (final type in event.ticketTypes)
                _TicketTypeRow(
                  type: type,
                  quantity: _quantities[type.id] ?? 0,
                  enabled: event.salesOpen && !event.isPast && !_busy,
                  onAdd: () => _change(type, 1),
                  onRemove: () => _change(type, -1),
                ),
              if (event.description != null) ...[
                const SizedBox(height: 20),
                Text('About', style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900)),
                const SizedBox(height: 8),
                Text(event.description!, style: theme.textTheme.bodyLarge),
              ],
              if (event.refundPolicy != null) ...[
                const SizedBox(height: 20),
                Text('Refunds', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                Text(event.refundPolicy!, style: muted),
              ],
              if (event.imageUrls.length > 1) ...[
                const SizedBox(height: 20),
                SizedBox(
                  height: 120,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: event.imageUrls.length,
                    separatorBuilder: (context, index) => const SizedBox(width: 8),
                    itemBuilder: (context, index) => ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: SizedBox(width: 180, child: CoverImage(url: state.imageUrl(event.imageUrls[index]), aspectRatio: 3 / 2)),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget? _buyBar(BuildContext context, EventDetail event) {
    if (event.isPast || !event.salesOpen || event.ticketTypes.isEmpty) return null;
    final theme = Theme.of(context);
    final currency = event.ticketTypes.first.price.currency;

    return SafeArea(
      child: Container(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerLowest,
          border: Border(top: BorderSide(color: theme.colorScheme.outlineVariant)),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_count == 0 ? 'Choose tickets' : '$_count ticket${_count == 1 ? '' : 's'}',
                      style: theme.textTheme.labelLarge),
                  if (_count > 0)
                    Text(
                      _totalMinor == 0 ? 'Free' : '${formatMinor(_totalMinor, currency)} + fees',
                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900),
                    ),
                ],
              ),
            ),
            SizedBox(
              width: 160,
              child: FilledButton(
                onPressed: _count == 0 || _busy ? null : _continue,
                child: _busy
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                    : const Text('Continue'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A rough local total before the server prices the order (2 decimals).
String formatMinor(int minor, String currency) {
  final major = minor ~/ 100;
  final cents = (minor % 100).toString().padLeft(2, '0');
  final digits = major.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (m) => ',');
  return '$currency $digits.$cents';
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.icon, required this.title, required this.subtitle});

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: theme.colorScheme.primaryContainer,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, size: 20, color: theme.colorScheme.onPrimaryContainer),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                if (subtitle.isNotEmpty)
                  Text(subtitle,
                      style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TicketTypeRow extends StatelessWidget {
  const _TicketTypeRow({
    required this.type,
    required this.quantity,
    required this.enabled,
    required this.onAdd,
    required this.onRemove,
  });

  final TicketTypeOption type;
  final int quantity;
  final bool enabled;
  final VoidCallback onAdd;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);

    final String? note;
    if (type.soldOut || type.remaining == 0) {
      note = 'Sold out';
    } else if (!type.onSale) {
      note = 'Not on sale';
    } else if (type.remaining <= 10) {
      note = 'Only ${type.remaining} left';
    } else {
      note = null;
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(type.name, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 2),
                    Text(type.isFree ? 'Free' : type.price.formatted,
                        style: theme.textTheme.titleSmall?.copyWith(color: theme.colorScheme.primary, fontWeight: FontWeight.w800)),
                    if (type.description != null) ...[
                      const SizedBox(height: 4),
                      Text(type.description!, style: muted),
                    ],
                    if (note != null) ...[
                      const SizedBox(height: 4),
                      Text(note, style: muted?.copyWith(fontWeight: FontWeight.w700)),
                    ],
                  ],
                ),
              ),
              if (type.buyable) ...[
                IconButton.filledTonal(
                  tooltip: 'Remove one ${type.name}',
                  onPressed: enabled && quantity > 0 ? onRemove : null,
                  icon: const Icon(Icons.remove),
                ),
                SizedBox(
                  width: 32,
                  child: Text('$quantity',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900)),
                ),
                IconButton.filledTonal(
                  tooltip: 'Add one ${type.name}',
                  onPressed: enabled && quantity < type.limit ? onAdd : null,
                  icon: const Icon(Icons.add),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
