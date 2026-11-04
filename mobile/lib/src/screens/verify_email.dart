import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../widgets/common.dart';

/// Asks the buyer to confirm their email before buying. Returns true once
/// the server says the address is confirmed.
Future<bool> showVerifyEmailSheet(BuildContext context) async {
  final result = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => const _VerifyEmailSheet(),
  );
  return result ?? false;
}

class _VerifyEmailSheet extends StatefulWidget {
  const _VerifyEmailSheet();

  @override
  State<_VerifyEmailSheet> createState() => _VerifyEmailSheetState();
}

class _VerifyEmailSheetState extends State<_VerifyEmailSheet> {
  bool _busy = false;
  String? _note;

  Future<void> _resend() async {
    setState(() => _busy = true);
    try {
      final response = await AppScope.read(context).api.post('/me/verification-notification');
      if (mounted) setState(() => _note = '${response['message'] ?? 'Email sent.'}');
    } on ApiException catch (e) {
      if (mounted) setState(() => _note = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _check() async {
    final state = AppScope.read(context);
    setState(() => _busy = true);
    try {
      await state.refreshUser();
      if (!mounted) return;
      if (state.user?.emailVerified ?? false) {
        showSnack(context, 'Email confirmed. Thanks!');
        Navigator.of(context).pop(true);
      } else {
        setState(() => _note = 'Not confirmed yet. Open the link in the email, then try again.');
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _note = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final email = AppScope.of(context).user?.email ?? 'your email address';
    final note = _note;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Icon(Icons.mark_email_unread_outlined, size: 44, color: theme.colorScheme.primary),
            const SizedBox(height: 12),
            Text('Confirm your email to buy tickets',
                textAlign: TextAlign.center,
                style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900)),
            const SizedBox(height: 8),
            Text(
              'We sent a confirmation link to $email. Open it on this phone or any computer, then come back.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            if (note != null) ...[
              const SizedBox(height: 12),
              Text(note, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 20),
            FilledButton(onPressed: _busy ? null : _check, child: const Text("I've confirmed it")),
            const SizedBox(height: 10),
            OutlinedButton(onPressed: _busy ? null : _resend, child: const Text('Send the email again')),
          ],
        ),
      ),
    );
  }
}
