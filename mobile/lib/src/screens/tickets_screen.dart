import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../models.dart';
import '../widgets/common.dart';
import 'auth_screen.dart';
import 'home_screen.dart';
import 'order_screen.dart';

/// The buyer's orders, kept on the phone so tickets open without a
/// connection.
class TicketsScreen extends StatefulWidget {
  const TicketsScreen({super.key});

  @override
  State<TicketsScreen> createState() => TicketsScreenState();
}

class TicketsScreenState extends State<TicketsScreen> {
  List<Order> _orders = const [];
  bool _loading = false;
  bool _loaded = false;
  ApiException? _error;
  String? _forUser;

  @override
  void initState() {
    super.initState();
    HomeScreen.ticketsChanged.addListener(reload);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Signed in or out: show that person's tickets.
    final state = AppScope.of(context);
    final who = state.signedIn ? state.user?.email : null;
    if (who != _forUser) {
      _forUser = who;
      _orders = state.signedIn ? state.cachedOrders() : const [];
      _loaded = false;
      if (state.signedIn) WidgetsBinding.instance.addPostFrameCallback((_) => reload());
    }
  }

  @override
  void dispose() {
    HomeScreen.ticketsChanged.removeListener(reload);
    super.dispose();
  }

  Future<void> reload() async {
    if (!mounted) return;
    final state = AppScope.read(context);
    if (!state.signedIn || _loading) return;

    setState(() => _loading = true);
    try {
      final response = await state.api.get('/orders');
      final data = response['data'];
      if (!mounted) return;
      final list = data is List ? data : const [];
      await state.cacheOrders(list);
      if (!mounted) return;
      setState(() {
        _orders = list.whereType<Map<String, dynamic>>().map(Order.fromJson).toList();
        _error = null;
        _loaded = true;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = AppScope.of(context);
    final theme = Theme.of(context);

    if (!state.signedIn) {
      return Scaffold(
        appBar: AppBar(title: const Text('My tickets')),
        body: MessageView(
          icon: Icons.confirmation_number_outlined,
          title: 'Your tickets live here',
          message: 'Sign in to see the tickets you bought and show them at the door.',
          actionLabel: 'Sign in',
          onAction: () => Navigator.of(context).push(MaterialPageRoute<bool>(builder: (_) => const AuthScreen())),
        ),
      );
    }

    final upcoming = _orders.where((o) => !o.event.isPast).toList();
    final past = _orders.where((o) => o.event.isPast).toList().reversed.toList();
    final error = _error;

    Widget body;
    if (_orders.isEmpty && !_loaded && (_loading || error == null)) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_orders.isEmpty && error != null) {
      body = MessageView(
        icon: error.isNetwork ? Icons.wifi_off : Icons.error_outline,
        title: "Couldn't load your tickets",
        message: error.message,
        actionLabel: 'Try again',
        onAction: reload,
      );
    } else if (_orders.isEmpty) {
      body = MessageView(
        icon: Icons.local_activity_outlined,
        title: 'No tickets yet',
        message: 'Tickets you buy show up here.',
        actionLabel: 'Find events',
        onAction: () => HomeScreen.tab.value = 0,
      );
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
        children: [
          if (error != null && error.isNetwork)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Row(
                children: [
                  Icon(Icons.cloud_off, size: 18, color: theme.colorScheme.onSurfaceVariant),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text('Offline: showing tickets saved on this phone.',
                        style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                  ),
                ],
              ),
            ),
          if (upcoming.isNotEmpty) ...[
            const _Heading('Upcoming'),
            for (final order in upcoming) _OrderTile(order: order),
          ],
          if (past.isNotEmpty) ...[
            const _Heading('Past'),
            for (final order in past) _OrderTile(order: order, dim: true),
          ],
        ],
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('My tickets')),
      body: RefreshIndicator(onRefresh: reload, child: body),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8, bottom: 10),
      child: Text(text, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900)),
    );
  }
}

class _OrderTile extends StatelessWidget {
  const _OrderTile({required this.order, this.dim = false});

  final Order order;
  final bool dim;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final state = AppScope.of(context);
    final usable = order.tickets.where((t) => t.usable).length;
    final count = order.tickets.length;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Opacity(
        opacity: dim ? 0.7 : 1,
        child: Card(
          child: InkWell(
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => OrderScreen(orderId: order.id, initial: order)),
            ),
            child: Row(
              children: [
                SizedBox(width: 108, height: 108, child: CoverImage(url: state.imageUrl(order.event.coverUrl), aspectRatio: 1)),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(order.event.shortDateLabel.toUpperCase(),
                            style: theme.textTheme.labelSmall
                                ?.copyWith(color: theme.colorScheme.primary, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 2),
                        Text(order.event.title,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                        const SizedBox(height: 4),
                        Text(
                          '$count ticket${count == 1 ? '' : 's'}'
                          '${usable < count ? ' · $usable valid' : ''} · ${order.reference}',
                          style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                        ),
                      ],
                    ),
                  ),
                ),
                const Padding(padding: EdgeInsets.only(right: 8), child: Icon(Icons.chevron_right)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
