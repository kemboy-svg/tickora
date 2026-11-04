import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../api.dart';
import '../app_state.dart';
import '../models.dart';
import '../widgets/common.dart';
import 'ticket_screen.dart';

/// One order: its event, tickets and receipt.
class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.orderId, this.initial});

  final String orderId;
  final Order? initial;

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  Order? _order;
  ApiException? _error;

  @override
  void initState() {
    super.initState();
    _order = widget.initial;
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final response = await AppScope.read(context).api.get('/orders/${widget.orderId}');
      final data = response['data'];
      if (!mounted || data is! Map<String, dynamic>) return;
      setState(() {
        _order = Order.fromJson(data);
        _error = null;
      });
    } on ApiException catch (e) {
      // Offline with a saved copy: keep showing it.
      if (mounted && _order == null) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final order = _order;
    final error = _error;

    return Scaffold(
      appBar: AppBar(title: Text(order == null ? 'Order' : 'Order ${order.reference}')),
      body: order == null
          ? (error != null
              ? MessageView(
                  icon: error.isNetwork ? Icons.wifi_off : Icons.error_outline,
                  title: "Couldn't load this order",
                  message: error.message,
                  actionLabel: 'Try again',
                  onAction: _load,
                )
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _load, child: _body(context, order)),
    );
  }

  Widget _body(BuildContext context, Order order) {
    final theme = Theme.of(context);
    final state = AppScope.of(context);
    final event = order.event;

    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        CoverImage(url: state.imageUrl(event.coverUrl), aspectRatio: 21 / 9),
        Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(event.title, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w900)),
              const SizedBox(height: 6),
              Text(event.dateLabel, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
              if (event.address != null || event.venue != null)
                Text(event.address ?? event.venue!,
                    style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
              const SizedBox(height: 20),
              Text(
                order.tickets.length == 1 ? 'Your ticket' : 'Your ${order.tickets.length} tickets',
                style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 4),
              Text('Tap a ticket to show its QR code at the door.',
                  style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
              const SizedBox(height: 12),
              for (final (index, ticket) in order.tickets.indexed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _TicketTile(
                    ticket: ticket,
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => TicketScreen(event: event, tickets: order.tickets, initialIndex: index),
                      ),
                    ),
                  ),
                ),
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Receipt', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 8),
                      for (final item in order.items)
                        AmountRow(label: '${item.quantity} × ${item.name}', value: item.amount.formatted),
                      if (!order.discount.isZero) AmountRow(label: 'Discount', value: '−${order.discount.formatted}'),
                      if (!order.fee.isZero) AmountRow(label: 'Fees', value: order.fee.formatted),
                      const Divider(height: 20),
                      AmountRow(label: 'Total paid', value: order.total.formatted, strong: true),
                      const SizedBox(height: 8),
                      Text(
                        [order.statusLabel, if (order.paidLabel != null) order.paidLabel!].join(' · '),
                        style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _TicketTile extends StatelessWidget {
  const _TicketTile({required this.ticket, required this.onTap});

  final Ticket ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final qr = ticket.qr;
    return Card(
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Container(
                width: 64,
                height: 64,
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
                child: qr == null
                    ? const Icon(Icons.qr_code_2, color: Colors.black54)
                    : Opacity(
                        opacity: ticket.usable ? 1 : 0.3,
                        child: QrImageView(data: qr, padding: EdgeInsets.zero, backgroundColor: Colors.white),
                      ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(ticket.type, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                    if (ticket.attendee != null) Text(ticket.attendee!, style: theme.textTheme.bodyMedium),
                    Text(ticket.reference,
                        style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                  ],
                ),
              ),
              _StatusChip(ticket: ticket),
              const Icon(Icons.chevron_right),
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.ticket});

  final Ticket ticket;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final ok = ticket.usable;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: ok ? scheme.primaryContainer : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(ticket.statusLabel,
          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: ok ? scheme.onPrimaryContainer : scheme.onSurfaceVariant)),
    );
  }
}
