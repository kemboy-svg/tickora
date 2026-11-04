import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../widgets/common.dart';

/// Where testers enter the Tickora server's address, e.g. the staging site.
class ServerScreen extends StatefulWidget {
  const ServerScreen({super.key, this.firstRun = false});

  final bool firstRun;

  @override
  State<ServerScreen> createState() => _ServerScreenState();
}

class _ServerScreenState extends State<ServerScreen> {
  late final TextEditingController _address;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _address = TextEditingController(text: AppScope.read(context).serverUrl ?? '');
  }

  @override
  void dispose() {
    _address.dispose();
    super.dispose();
  }

  Future<void> _connect() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });

    final state = AppScope.read(context);
    final changing = state.serverUrl != null && state.serverUrl != ApiClient.normalizeServerUrl(_address.text);

    try {
      await state.setServer(_address.text);
      if (!mounted) return;
      if (!widget.firstRun) {
        showSnack(context, changing ? 'Connected. Please sign in again.' : 'Connected.');
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: widget.firstRun ? null : AppBar(title: const Text('Server')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
          children: [
            if (widget.firstRun) ...[
              const Align(alignment: Alignment.centerLeft, child: TickoraLogo(size: 44)),
              const SizedBox(height: 32),
              Text('Welcome to Tickora',
                  style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
            ],
            Text(
              'Enter the address of the Tickora site you were invited to test, '
              'for example staging.your-domain.com.',
              style: theme.textTheme.bodyLarge?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: 24),
            TextField(
              controller: _address,
              keyboardType: TextInputType.url,
              autocorrect: false,
              enableSuggestions: false,
              textInputAction: TextInputAction.go,
              onSubmitted: (_) => _busy ? null : _connect(),
              decoration: InputDecoration(
                labelText: 'Server address',
                hintText: 'https://staging.your-domain.com',
                prefixIcon: const Icon(Icons.dns_outlined),
                errorText: _error,
                errorMaxLines: 3,
              ),
            ),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy ? null : _connect,
              child: _busy
                  ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                  : const Text('Connect'),
            ),
            const SizedBox(height: 16),
            Text(
              'Testing on your own computer? Use its network address, such as '
              'http://192.168.1.20:8000, with the phone on the same Wi-Fi.',
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}
