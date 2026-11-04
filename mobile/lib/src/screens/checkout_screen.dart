import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../app_state.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'home_screen.dart';
import 'order_screen.dart';

/// Review held tickets, apply a promo code and pay. Payment happens on the
/// provider's page in the browser; this screen watches for the result.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, required this.checkoutId, this.initial});

  final String checkoutId;
  final Checkout? initial;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> with WidgetsBindingObserver {
  Checkout? _checkout;
  ApiException? _error;
  bool _busy = false;
  bool _waitingForPayment = false;
  String? _promoError;
  final _promo = TextEditingController();

  /// Counts down locally between refreshes.
  int _secondsLeft = 0;
  Timer? _ticker;
  Timer? _poller;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final initial = widget.initial;
    if (initial != null) _apply(initial);
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (_secondsLeft > 0 && mounted) setState(() => _secondsLeft--);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _ticker?.cancel();
    _poller?.cancel();
    _promo.dispose();
    super.dispose();
  }

  /// Back from the payment page: find out how it went.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _waitingForPayment) _refresh();
  }

  void _apply(Checkout checkout) {
    _checkout = checkout;
    _secondsLeft = checkout.secondsLeft;
    if (checkout.promoCode != null && _promo.text.isEmpty) _promo.text = checkout.promoCode!;

    final settled = checkout.isPaid || !checkout.isActive || (checkout.order?.paymentDeclined ?? false);
    if (settled) {
      _waitingForPayment = false;
      _poller?.cancel();
      _poller = null;
    }
    if (checkout.isPaid) HomeScreen.ticketsChanged.value++;
  }

  Future<void> _refresh() async {
    try {
      final response = await AppScope.read(context).api.get('/checkouts/${widget.checkoutId}');
      final data = response['data'];
      if (!mounted || data is! Map<String, dynamic>) return;
      setState(() {
        _apply(Checkout.fromJson(data));
        _error = null;
      });
    } on ApiException catch (e) {
      if (mounted && _checkout == null) setState(() => _error = e);
    }
  }

  Future<void> _applyPromo({bool remove = false}) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _promoError = null;
    });
    try {
      final response = await AppScope.read(context).api.post('/checkouts/${widget.checkoutId}/promo', {
        'promo_code': remove ? null : _promo.text.trim(),
      });
      final data = response['data'];
      if (!mounted || data is! Map<String, dynamic>) return;
      if (remove) _promo.clear();
      setState(() => _apply(Checkout.fromJson(data)));
      showSnack(context, remove ? 'Promo code removed.' : 'Promo code applied.');
    } on ApiException catch (e) {
      if (mounted) setState(() => _promoError = e.fieldErrors['promo_code'] ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pay() async {
    setState(() => _busy = true);
    try {
      final response = await AppScope.read(context).api.post('/checkouts/${widget.checkoutId}/pay');
      final data = response['data'];
      if (!mounted || data is! Map<String, dynamic>) return;

      if (data['paid'] == true) {
        await _refresh();
        return;
      }

      final url = data['payment_url'];
      if (url is! String) throw ApiException('The payment page could not be opened.');

      setState(() => _waitingForPayment = true);
      _startPolling();
      final opened = await _openPaymentPage(Uri.parse(url));
      if (!opened && mounted) {
        setState(() => _waitingForPayment = false);
        showSnack(context, "Couldn't open the payment page. Is a web browser installed?");
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message);
      await _refresh();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _openPaymentPage(Uri uri) async {
    try {
      if (await launchUrl(uri, mode: LaunchMode.inAppBrowserView)) return true;
    } catch (_) {
      // Fall back to the full browser below.
    }
    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  void _startPolling() {
    _poller?.cancel();
    var polls = 0;
    _poller = Timer.periodic(const Duration(seconds: 4), (timer) {
      polls++;
      // About 20 minutes: longer than any hold.
      if (!mounted || !_waitingForPayment || polls > 300) {
        timer.cancel();
        return;
      }
      _refresh();
    });
  }

  Future<void> _release() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Release these tickets?'),
        content: const Text('They go back on sale for other people.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep them')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Release')),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _busy = true);
    try {
      await AppScope.read(context).api.delete('/checkouts/${widget.checkoutId}');
      if (!mounted) return;
      showSnack(context, 'Your tickets were released.');
      Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final checkout = _checkout;
    final error = _error;

    return Scaffold(
      appBar: AppBar(title: const Text('Checkout')),
      body: checkout == null
          ? (error != null
              ? MessageView(
                  icon: Icons.error_outline,
                  title: "Couldn't load your checkout",
                  message: error.message,
                  actionLabel: 'Try again',
                  onAction: _refresh,
                )
              : const Center(child: CircularProgressIndicator()))
          : checkout.isPaid
              ? _paid(context, checkout)
              : RefreshIndicator(onRefresh: _refresh, child: _review(context, checkout)),
    );
  }

  Widget _paid(BuildContext context, Checkout checkout) {
    final theme = Theme.of(context);
    final order = checkout.order;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            const Spacer(),
            const CircleAvatar(
              radius: 44,
              backgroundColor: Brand.violet,
              child: Icon(Icons.check_rounded, color: Colors.white, size: 52),
            ),
            const SizedBox(height: 24),
            Text("You're going!",
                style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w900)),
            const SizedBox(height: 8),
            Text(
              '${checkout.eventTitle}\n${checkout.eventDate}',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            if (order != null) ...[
              const SizedBox(height: 8),
              Text('Order ${order.reference}', style: theme.textTheme.labelLarge),
            ],
            const SizedBox(height: 8),
            Text('Your tickets are saved in the app and sent to your email.',
                textAlign: TextAlign.center, style: theme.textTheme.bodyMedium),
            const Spacer(),
            FilledButton(
              onPressed: order == null
                  ? null
                  : () => Navigator.of(context).pushReplacement(
                        MaterialPageRoute<void>(builder: (_) => OrderScreen(orderId: order.id)),
                      ),
              child: const Text('Show my tickets'),
            ),
            const SizedBox(height: 10),
            OutlinedButton(
              onPressed: () {
                HomeScreen.showTickets();
                Navigator.of(context).popUntil((route) => route.isFirst);
              },
              child: const Text('Done'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _review(BuildContext context, Checkout checkout) {
    final theme = Theme.of(context);
    final order = checkout.order;
    final expired = !checkout.isActive || (_secondsLeft <= 0 && checkout.isActive);
    final declined = order?.paymentDeclined ?? false;
    final canEditPromo = checkout.status == 'open' && !expired;

    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        if (!expired) _HoldBanner(secondsLeft: _secondsLeft),
        if (expired)
          _Notice(
            icon: Icons.timer_off_outlined,
            color: theme.colorScheme.errorContainer,
            text: checkout.status == 'cancelled'
                ? 'You released these tickets.'
                : 'Your hold ran out and the tickets went back on sale. Pick them again on the event page.'
                    '${order?.refunded ?? false ? ' Any payment taken has been refunded.' : ''}',
          ),
        if (declined && !expired)
          _Notice(
            icon: Icons.credit_card_off_outlined,
            color: theme.colorScheme.errorContainer,
            text: 'The payment was declined. No money was taken. You can try again while your tickets are held.',
          ),
        if (_waitingForPayment && !expired && !declined)
          _Notice(
            icon: Icons.hourglass_top,
            color: theme.colorScheme.secondaryContainer,
            text: 'Waiting for your payment… Finish paying in the browser, then come back here.',
            trailing: const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2)),
          ),
        const SizedBox(height: 8),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(checkout.eventTitle, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900)),
                const SizedBox(height: 4),
                Text([checkout.eventDate, checkout.eventPlace].where((s) => s.isNotEmpty).join('\n'),
                    style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                const Divider(height: 28),
                for (final line in checkout.lines)
                  AmountRow(label: '${line.quantity} × ${line.name}', value: line.subtotal.formatted),
                if (!checkout.discount.isZero)
                  AmountRow(label: 'Discount', value: '−${checkout.discount.formatted}'),
                if (!checkout.fee.isZero) AmountRow(label: 'Booking fee', value: checkout.fee.formatted),
                if (!checkout.tax.isZero) AmountRow(label: 'Tax', value: checkout.tax.formatted),
                const Divider(height: 24),
                AmountRow(label: 'Total', value: checkout.total.formatted, strong: true),
              ],
            ),
          ),
        ),
        if (canEditPromo || checkout.promoCode != null) ...[
          const SizedBox(height: 16),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: TextField(
                  controller: _promo,
                  enabled: canEditPromo && checkout.promoCode == null && !_busy,
                  textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(labelText: 'Promo code', errorText: _promoError, errorMaxLines: 3),
                ),
              ),
              const SizedBox(width: 8),
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: checkout.promoCode == null
                    ? FilledButton.tonal(
                        onPressed: canEditPromo && !_busy ? () => _applyPromo() : null,
                        child: const Text('Apply'),
                      )
                    : TextButton(
                        onPressed: canEditPromo && !_busy ? () => _applyPromo(remove: true) : null,
                        child: const Text('Remove'),
                      ),
              ),
            ],
          ),
        ],
        const SizedBox(height: 24),
        if (!expired) ...[
          FilledButton(
            onPressed: _busy ? null : _pay,
            child: _busy
                ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                : Text(checkout.total.isZero
                    ? 'Get tickets'
                    : _waitingForPayment
                        ? 'Open the payment page again'
                        : declined
                            ? 'Try paying again'
                            : 'Pay ${checkout.total.formatted}'),
          ),
          const SizedBox(height: 10),
          if (checkout.status == 'open')
            TextButton(onPressed: _busy ? null : _release, child: const Text('Release these tickets')),
          const SizedBox(height: 8),
          Text(
            checkout.total.isZero
                ? 'Free tickets are issued straight away.'
                : "You pay securely on the payment provider's page. Tickora never sees your card details.",
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ] else
          OutlinedButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Back to the event')),
      ],
    );
  }
}

class _HoldBanner extends StatelessWidget {
  const _HoldBanner({required this.secondsLeft});

  final int secondsLeft;

  @override
  Widget build(BuildContext context) {
    final minutes = secondsLeft ~/ 60;
    final seconds = (secondsLeft % 60).toString().padLeft(2, '0');
    final urgent = secondsLeft < 120;
    final theme = Theme.of(context);
    return _Notice(
      icon: Icons.timer_outlined,
      color: urgent ? theme.colorScheme.errorContainer : theme.colorScheme.primaryContainer,
      text: 'Tickets held for you for $minutes:$seconds',
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.icon, required this.color, required this.text, this.trailing});

  final IconData icon;
  final Color color;
  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final trailingWidget = trailing;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(14)),
      child: Row(
        children: [
          Icon(icon),
          const SizedBox(width: 12),
          Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w600))),
          if (trailingWidget != null) ...[const SizedBox(width: 12), trailingWidget],
        ],
      ),
    );
  }
}
