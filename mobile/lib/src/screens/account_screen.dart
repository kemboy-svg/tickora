import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../widgets/common.dart';
import 'auth_screen.dart';
import 'home_screen.dart';
import 'verify_email.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  Future<void> _changeServer(BuildContext context) async {
    final state = AppScope.read(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Change server?'),
        content: Text('You will be signed out of ${state.serverUrl ?? 'this server'}. '
            'Tickets saved on this phone are removed until you sign in again.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Change server')),
        ],
      ),
    );
    if (confirmed != true) return;
    HomeScreen.tab.value = 0;
    await state.forgetServer();
  }

  Future<void> _signOut(BuildContext context) async {
    final state = AppScope.read(context);
    await state.signOut();
    if (context.mounted) showSnack(context, 'Signed out.');
  }

  Future<void> _refresh(BuildContext context) async {
    try {
      await AppScope.read(context).refreshUser();
    } on ApiException catch (e) {
      if (context.mounted) showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = AppScope.of(context);
    final theme = Theme.of(context);
    final user = state.user;

    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: RefreshIndicator(
        onRefresh: () => _refresh(context),
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            if (user == null || !state.signedIn) ...[
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Not signed in', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900)),
                      const SizedBox(height: 6),
                      const Text('Sign in or create an account to buy tickets and keep them on this phone.'),
                      const SizedBox(height: 16),
                      FilledButton(
                        onPressed: () =>
                            Navigator.of(context).push(MaterialPageRoute<bool>(builder: (_) => const AuthScreen())),
                        child: const Text('Sign in or create account'),
                      ),
                    ],
                  ),
                ),
              ),
            ] else ...[
              Card(
                child: ListTile(
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  leading: CircleAvatar(
                    backgroundColor: theme.colorScheme.primaryContainer,
                    child: Text(
                      user.name.isEmpty ? '?' : user.name.characters.first.toUpperCase(),
                      style: TextStyle(fontWeight: FontWeight.w900, color: theme.colorScheme.onPrimaryContainer),
                    ),
                  ),
                  title: Text(user.name, style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(user.email),
                ),
              ),
              if (!user.emailVerified) ...[
                const SizedBox(height: 12),
                Card(
                  color: theme.colorScheme.tertiaryContainer,
                  child: ListTile(
                    leading: const Icon(Icons.mark_email_unread_outlined),
                    title: const Text('Confirm your email address'),
                    subtitle: const Text('You need to confirm it before buying tickets.'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => showVerifyEmailSheet(context),
                  ),
                ),
              ],
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () => _signOut(context),
                icon: const Icon(Icons.logout),
                label: const Text('Sign out'),
              ),
            ],
            const SizedBox(height: 28),
            Text('Server', style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            Card(
              child: ListTile(
                leading: const Icon(Icons.dns_outlined),
                title: Text(state.serverUrl ?? 'Not set', maxLines: 2, overflow: TextOverflow.ellipsis),
                trailing: TextButton(onPressed: () => _changeServer(context), child: const Text('Change')),
              ),
            ),
            const SizedBox(height: 28),
            const Center(child: TickoraMark(size: 36)),
            const SizedBox(height: 8),
            Center(
              child: Text('Tickora for Android · test build',
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            ),
          ],
        ),
      ),
    );
  }
}
